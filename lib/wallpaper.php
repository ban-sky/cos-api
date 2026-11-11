<?php

declare(strict_types=1);

/**
 * Shared wallpaper lookup logic for the COS API.
 *
 * The HTTP entry points (index.php and api/index.php) stay thin; everything
 * that can be tested without credentials or network access lives here.
 */

/** Wallpaper type => COS prefix, in one place instead of two switch blocks. */
const WALLPAPER_FOLDERS = [
    'horizontal' => 'horizontal_wallpapers',
    'vertical' => 'catgirl_wallpapers',
];

const WALLPAPER_DEFAULT_TYPE = 'horizontal';
const WALLPAPER_DEFAULT_DOMAIN = 'image.lonelyx.cn';
const WALLPAPER_DEFAULT_TTL = 300;
const WALLPAPER_MAX_TTL = 86400;
const WALLPAPER_PAGE_SIZE = 1000;
const WALLPAPER_MAX_PAGES = 20;

/**
 * Minimal seam over the COS client so listing can be faked in tests.
 */
interface WallpaperLister
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function list(array $params): array;
}

final class CosLister implements WallpaperLister
{
    private readonly \Qcloud\Cos\Client $client;

    public function __construct(\Qcloud\Cos\Client $client)
    {
        $this->client = $client;
    }

    public function list(array $params): array
    {
        $result = $this->client->listObjects($params);

        return is_array($result) ? $result : [];
    }
}

/**
 * Read an environment variable, falling back to $_SERVER/$_ENV for SAPIs that
 * do not export the environment with getenv().
 */
function wallpaper_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';
    }

    $value = is_string($value) ? trim($value) : '';

    return $value !== '' ? $value : $default;
}

/**
 * Build the runtime configuration.
 *
 * Precedence: environment variables > key/config.local.php > key/Tencent Key.php.
 * Env-first means secrets never have to be written into the web root.
 *
 * @return array{bucket: string, region: string, secretId: string, secretKey: string, domain: string, ttl: int}
 */
function wallpaper_config(?string $keyDir = null): array
{
    $keyDir ??= dirname(__DIR__) . '/key';

    $bucket = '';
    $region = '';
    $secretId = '';
    $secretKey = '';
    $domain = '';

    // Legacy layout: key/Tencent Key.php defines plain variables.
    $legacyFile = $keyDir . '/Tencent Key.php';
    if (is_file($legacyFile)) {
        if (!is_readable($legacyFile)) {
            error_log('cos-api: legacy key file is not readable, skipping: ' . $legacyFile);
        } else {
            try {
                /** @psalm-suppress UnusedVariable */
                $returned = require $legacyFile;
            } catch (Throwable $e) {
                error_log('cos-api: legacy key file failed to load: ' . $legacyFile . ': ' . $e->getMessage());
                $returned = null;
            }
            if (is_array($returned)) {
                $bucket = (string) ($returned['bucket'] ?? $bucket);
                $region = (string) ($returned['region'] ?? $region);
                $secretId = (string) ($returned['secretId'] ?? $secretId);
                $secretKey = (string) ($returned['secretKey'] ?? $secretKey);
                $domain = (string) ($returned['domain'] ?? $domain);
            } elseif ($returned !== null) {
                // The included file assigned these variables in this scope.
                $bucket = isset($bucket) ? (string) $bucket : '';
                $region = isset($region) ? (string) $region : '';
                $secretId = isset($secretId) ? (string) $secretId : '';
                $secretKey = isset($secretKey) ? (string) $secretKey : '';
            }
        }
    }

    // Optional, git-ignored file for deployments that cannot set env vars.
    $localFile = $keyDir . '/config.local.php';
    if (is_file($localFile)) {
        if (!is_readable($localFile)) {
            error_log('cos-api: local config file is not readable, skipping: ' . $localFile);
        } else {
            try {
                $local = require $localFile;
            } catch (Throwable $e) {
                error_log('cos-api: local config file failed to load: ' . $localFile . ': ' . $e->getMessage());
                $local = null;
            }
            if (is_array($local)) {
                $bucket = (string) ($local['bucket'] ?? $bucket);
                $region = (string) ($local['region'] ?? $region);
                $secretId = (string) ($local['secretId'] ?? $secretId);
                $secretKey = (string) ($local['secretKey'] ?? $secretKey);
                $domain = (string) ($local['domain'] ?? $domain);
            }
        }
    }

    $bucket = wallpaper_env('COS_BUCKET', trim($bucket));
    $region = wallpaper_env('COS_REGION', trim($region));
    $secretId = wallpaper_env('COS_SECRET_ID', trim($secretId));
    $secretKey = wallpaper_env('COS_SECRET_KEY', trim($secretKey));
    $domain = wallpaper_env('COS_DOMAIN', trim($domain));

    if ($domain === '') {
        $domain = WALLPAPER_DEFAULT_DOMAIN;
    }

    $rawTtl = wallpaper_env('COS_CACHE_TTL');
    $ttl = $rawTtl === '' ? WALLPAPER_DEFAULT_TTL : filter_var($rawTtl, FILTER_VALIDATE_INT);
    if ($ttl === false) {
        $ttl = WALLPAPER_DEFAULT_TTL;
    }
    $ttl = max(0, min(WALLPAPER_MAX_TTL, $ttl));

    return [
        'bucket' => $bucket,
        'region' => $region,
        'secretId' => $secretId,
        'secretKey' => $secretKey,
        'domain' => $domain,
        'ttl' => $ttl,
    ];
}

/**
 * @param array{bucket: string, region: string, secretId: string, secretKey: string, domain: string, ttl: int} $config
 */
function wallpaper_client(array $config): \Qcloud\Cos\Client
{
    foreach (['bucket', 'region', 'secretId', 'secretKey'] as $field) {
        if ($config[$field] === '') {
            // Never echo the value of a credential field.
            throw new RuntimeException(sprintf('COS configuration is incomplete: %s is empty.', $field));
        }
    }

    return new \Qcloud\Cos\Client([
        'region' => $config['region'],
        'credentials' => [
            'secretId' => $config['secretId'],
            'secretKey' => $config['secretKey'],
        ],
    ]);
}

/**
 * Normalise the user-supplied wallpaper type against a strict allow-list.
 *
 * Accepts mixed on purpose: `?type[]=x` yields an array, which must fall back
 * to the default instead of raising a TypeError.
 */
function wallpaper_selected_type(mixed $raw): string
{
    $type = is_string($raw) ? strtolower(trim($raw)) : '';

    return array_key_exists($type, WALLPAPER_FOLDERS) ? $type : WALLPAPER_DEFAULT_TYPE;
}

function wallpaper_folder(string $type): string
{
    return WALLPAPER_FOLDERS[$type] ?? WALLPAPER_FOLDERS[WALLPAPER_DEFAULT_TYPE];
}

function wallpaper_accepts_webp(?string $accept): bool
{
    return stripos((string) $accept, 'image/webp') !== false;
}

/**
 * Build the public object URL.
 *
 * Every path segment is percent-encoded, so object keys containing quotes,
 * spaces or CR/LF cannot break out of an HTML attribute or a Location header.
 */
function wallpaper_url(string $domain, string $key, bool $webp): string
{
    $domain = trim($domain);
    $domain = (string) preg_replace('#^https?://#i', '', $domain);
    $domain = rtrim($domain, '/');

    if ($domain === '' || preg_match('#^[A-Za-z0-9.\-]+(?::\d+)?(?:/[A-Za-z0-9._~\-/]*)?$#', $domain) !== 1) {
        throw new InvalidArgumentException('Invalid wallpaper domain.');
    }

    $key = ltrim($key, '/');
    if ($key === '') {
        throw new InvalidArgumentException('Empty object key.');
    }

    $path = implode('/', array_map('rawurlencode', explode('/', $key)));
    $url = 'https://' . $domain . '/' . $path;

    if ($webp) {
        $url .= '?imageMogr2/format/webp';
    }

    return $url;
}

/**
 * @return string Absolute cache directory, created with owner-only permissions.
 */
function wallpaper_cache_dir(): string
{
    $dir = wallpaper_env('COS_CACHE_DIR');
    if ($dir === '') {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/cos-api-' . substr(sha1(__DIR__), 0, 8);
    }

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create the wallpaper cache directory.');
    }

    return $dir;
}

function wallpaper_cache_file(string $bucket, string $prefix): string
{
    return wallpaper_cache_dir() . '/' . hash('sha256', $bucket . '|' . $prefix) . '.json';
}

/**
 * @return array{expires: int, keys: list<string>}|null
 */
function wallpaper_cache_read(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['expires'], $data['keys']) || !is_array($data['keys'])) {
        return null;
    }

    $keys = [];
    foreach ($data['keys'] as $key) {
        if (is_string($key) && $key !== '') {
            $keys[] = $key;
        }
    }

    return ['expires' => (int) $data['expires'], 'keys' => $keys];
}

/**
 * Best-effort, atomic cache write; a read-only cache directory must not break
 * the endpoint.
 *
 * @param list<string> $keys
 */
function wallpaper_cache_write(string $file, array $keys, int $ttl): void
{
    $payload = json_encode(
        ['expires' => time() + $ttl, 'keys' => array_values($keys)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        return;
    }

    $tmp = @tempnam(dirname($file), 'cos-api-');
    if ($tmp === false) {
        return;
    }

    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        @unlink($tmp);

        return;
    }

    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

/**
 * List every object key under a prefix, with a short-lived cache so that a
 * burst of requests does not hit the COS API once per visitor.
 *
 * @return list<string>
 */
function wallpaper_list_keys(
    WallpaperLister $lister,
    string $bucket,
    string $prefix,
    int $ttl = WALLPAPER_DEFAULT_TTL
): array {
    $cacheFile = wallpaper_cache_file($bucket, $prefix);
    $cached = wallpaper_cache_read($cacheFile);

    if ($cached !== null && $cached['keys'] !== [] && $cached['expires'] > time()) {
        return $cached['keys'];
    }

    $seen = [];
    $marker = null;
    $lastKey = null;

    try {
        for ($page = 0; $page < WALLPAPER_MAX_PAGES; $page++) {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => WALLPAPER_PAGE_SIZE,
            ];
            if ($marker !== null) {
                $params['Marker'] = $marker;
            }

            $result = $lister->list($params);

            foreach ((array) ($result['Contents'] ?? []) as $object) {
                if (!is_array($object) || !isset($object['Key']) || !is_string($object['Key'])) {
                    continue;
                }
                $key = $object['Key'];
                if ($key === '' || $key === $prefix || str_ends_with($key, '/')) {
                    continue; // folder placeholder
                }
                $seen[$key] = $key;
                $lastKey = $key;
            }

            if (filter_var($result['IsTruncated'] ?? false, FILTER_VALIDATE_BOOL) === false) {
                break;
            }

            $marker = isset($result['NextMarker']) && is_string($result['NextMarker'])
                && $result['NextMarker'] !== ''
                ? $result['NextMarker']
                : $lastKey;
            if ($marker === null || $marker === '') {
                break;
            }
        }
    } catch (Throwable $e) {
        if ($cached !== null && $cached['keys'] !== []) {
            error_log('cos-api: listObjects failed, serving stale cache: ' . $e->getMessage());

            return $cached['keys'];
        }

        throw $e;
    }

    $keys = array_values($seen);
    sort($keys, SORT_STRING);

    if ($keys !== []) {
        wallpaper_cache_write($cacheFile, $keys, $ttl);
    }

    return $keys;
}

/**
 * Pick one key without the modulo bias of array_rand() and without throwing on
 * an empty list (PHP 8 turned that into a ValueError).
 *
 * @param list<string> $keys
 * @param (callable(int, int): int)|null $randomInt
 */
function wallpaper_pick(array $keys, ?callable $randomInt = null): ?string
{
    $keys = array_values($keys);
    $count = count($keys);
    if ($count === 0) {
        return null;
    }

    $index = $randomInt !== null ? (int) $randomInt(0, $count - 1) : random_int(0, $count - 1);

    return $keys[$index] ?? null;
}

/**
 * End-to-end resolution used by both entry points.
 *
 * @param array{bucket: string, region: string, secretId: string, secretKey: string, domain: string, ttl: int} $config
 */
function wallpaper_resolve(
    array $config,
    mixed $type = null,
    ?string $accept = null,
    ?WallpaperLister $lister = null
): string {
    $prefix = wallpaper_folder(wallpaper_selected_type($type)) . '/';
    $lister ??= new CosLister(wallpaper_client($config));

    $key = wallpaper_pick(wallpaper_list_keys($lister, $config['bucket'], $prefix, $config['ttl']));
    if ($key === null) {
        throw new RuntimeException('No wallpapers found under prefix "' . $prefix . '".');
    }

    return wallpaper_url($config['domain'], $key, wallpaper_accepts_webp($accept));
}
