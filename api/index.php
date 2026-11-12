<?php
declare(strict_types=1);

use Qcloud\Cos\Client;

// 接口只返回 302，任何错误都返回纯文本，避免在 header() 之前产生输出。
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

// 配置文件可能带有 BOM 或结束标记后的空白；先缓冲，发送响应头前丢弃，避免 headers already sent。
$obLevel = ob_get_level();
ob_start();

const WALLPAPER_FOLDERS = [
    'horizontal' => 'horizontal_wallpapers',
    'vertical' => 'catgirl_wallpapers',
];
const WALLPAPER_DEFAULT_DOMAIN = 'image.lonelyx.cn';

$fail = static function (Throwable $e) use ($obLevel): void {
    error_log('cos-api: ' . $e->getMessage());

    while (ob_get_level() > $obLevel) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    echo "Wallpaper service temporarily unavailable.\n";
    exit;
};

try {
    require __DIR__ . '/../vendor/autoload.php';
} catch (Throwable $e) {
    $fail($e);
}

$bucket = '';
$region = '';
$secretId = '';
$secretKey = '';
$domain = WALLPAPER_DEFAULT_DOMAIN;

$configFile = __DIR__ . '/../key/Tencent Key.php';
if (is_readable($configFile)) {
    try {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $bucket = $loaded['bucket'] ?? $bucket;
            $region = $loaded['region'] ?? $region;
            $secretId = $loaded['secretId'] ?? $secretId;
            $secretKey = $loaded['secretKey'] ?? $secretKey;
            $domain = $loaded['domain'] ?? $domain;
        }
    } catch (Throwable $e) {
        error_log('cos-api: key/Tencent Key.php: ' . $e->getMessage());
    }
}

$localFile = __DIR__ . '/../key/config.local.php';
if (is_readable($localFile)) {
    try {
        $loaded = require $localFile;
        if (is_array($loaded)) {
            $bucket = $loaded['bucket'] ?? $bucket;
            $region = $loaded['region'] ?? $region;
            $secretId = $loaded['secretId'] ?? $secretId;
            $secretKey = $loaded['secretKey'] ?? $secretKey;
            $domain = $loaded['domain'] ?? $domain;
        }
    } catch (Throwable $e) {
        error_log('cos-api: key/config.local.php: ' . $e->getMessage());
    }
}

try {
    $bucket = trim((string) $bucket);
    $region = trim((string) $region);
    $secretId = trim((string) $secretId);
    $secretKey = trim((string) $secretKey);

    if ($bucket === '' || $region === '' || $secretId === '' || $secretKey === '') {
        throw new RuntimeException('COS configuration is incomplete');
    }

    $domain = trim((string) $domain);
    if ($domain === '') {
        $domain = WALLPAPER_DEFAULT_DOMAIN;
    }
    $domain = (string) preg_replace('#^https?://#i', '', $domain);
    $domain = rtrim($domain, '/');
    if (!preg_match('#^[A-Za-z0-9.\-]+(?::\d+)?(?:/[A-Za-z0-9._~\-/]*)?$#', $domain)) {
        throw new RuntimeException('Invalid CDN domain');
    }

    $rawType = $_GET['type'] ?? 'horizontal';
    $type = is_string($rawType) ? $rawType : '';
    $folder = WALLPAPER_FOLDERS[$type] ?? WALLPAPER_FOLDERS['horizontal'];

    $client = new Client([
        // SDK 默认是 http，这里强制走 HTTPS，避免密钥标识在明文链路中传输。
        'scheme' => 'https',
        'region' => $region,
        'credentials' => [
            'secretId' => $secretId,
            'secretKey' => $secretKey,
        ],
    ]);

    $result = $client->listObjects([
        'Bucket' => $bucket,
        'Prefix' => $folder . '/',
    ]);

    $contents = [];
    if (is_array($result) && isset($result['Contents']) && is_array($result['Contents'])) {
        $contents = $result['Contents'];
    }

    $keys = [];
    foreach ($contents as $object) {
        if (!is_array($object) || !isset($object['Key']) || !is_string($object['Key'])) {
            continue;
        }
        $key = ltrim($object['Key'], '/');
        if ($key === '' || substr($key, -1) === '/') {
            continue;
        }
        $keys[] = $key;
    }

    if ($keys === []) {
        throw new RuntimeException('No wallpapers found in ' . $folder);
    }

    $key = $keys[random_int(0, count($keys) - 1)];
    $path = implode('/', array_map('rawurlencode', explode('/', $key)));
    $wallpaperUrl = 'https://' . $domain . '/' . $path;

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (is_string($accept) && stripos($accept, 'image/webp') !== false) {
        $wallpaperUrl .= '?imageMogr2/format/webp';
    }
} catch (Throwable $e) {
    $fail($e);
}

while (ob_get_level() > $obLevel) {
    ob_end_clean();
}

if (!headers_sent()) {
    header('Location: ' . $wallpaperUrl, true, 302);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
exit;
