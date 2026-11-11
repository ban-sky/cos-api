<?php

declare(strict_types=1);

/**
 * Dependency-free test runner: `php tests/run.php` (or `composer test`).
 *
 * Exercises the shared library without credentials, network access or the COS
 * SDK, which keeps CI fast and makes the edge cases reproducible.
 */

require dirname(__DIR__) . '/lib/wallpaper.php';

final class FakeLister implements WallpaperLister
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var (callable(array<string, mixed>): array<string, mixed>)|null */
    private $handler;

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $handler
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
    }

    public function list(array $params): array
    {
        $this->calls[] = $params;

        if ($this->handler === null) {
            return ['Contents' => []];
        }

        return ($this->handler)($params);
    }
}

$tests = 0;
$failures = 0;

function check(string $name, callable $test): void
{
    global $tests, $failures;

    $tests++;
    try {
        $test();
        echo "ok {$tests} - {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "not ok {$tests} - {$name}\n";
        echo '  ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function same(mixed $expected, mixed $actual, string $what = 'value'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function truthy(mixed $actual, string $what = 'value'): void
{
    if (!$actual) {
        throw new RuntimeException($what . ': expected a truthy value, got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $class = Throwable::class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }

        throw new RuntimeException('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }

    throw new RuntimeException('expected ' . $class . ', nothing was thrown');
}

function temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/cos-api-test-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create temp dir ' . $dir);
    }

    return $dir;
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? remove_dir($path) : @unlink($path);
    }

    @rmdir($dir);
}

/**
 * @param array<string, string|null> $vars null removes the variable
 */
function with_env(array $vars, callable $fn): void
{
    $saved = [];

    foreach ($vars as $name => $value) {
        $saved[$name] = [
            getenv($name),
            $_SERVER[$name] ?? null,
            $_ENV[$name] ?? null,
        ];

        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        } else {
            putenv($name . '=' . $value);
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
    }

    try {
        $fn();
    } finally {
        foreach ($saved as $name => [$previous, $server, $env]) {
            if ($previous === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous);
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }
    }
}

const BASE_CONFIG = [
    'bucket' => 'example-1250000000',
    'region' => 'ap-guangzhou',
    'secretId' => 'id',
    'secretKey' => 'key',
    'domain' => 'image.example.com',
    'ttl' => 300,
];

check('folder allow-list', function (): void {
    same('horizontal_wallpapers', wallpaper_folder('horizontal'));
    same('catgirl_wallpapers', wallpaper_folder('vertical'));
    same('horizontal_wallpapers', wallpaper_folder('bogus')); // unknown values never reach COS
});

check('type normalisation', function (): void {
    same('vertical', wallpaper_selected_type('  VERTICAL '));
    same('horizontal', wallpaper_selected_type('../../etc/passwd'));
    same('horizontal', wallpaper_selected_type(null));
    same('horizontal', wallpaper_selected_type(['vertical'])); // ?type[]=x
    same('horizontal', wallpaper_selected_type(42));
});

check('webp negotiation', function (): void {
    truthy(wallpaper_accepts_webp('image/avif,image/webp,*/*'));
    truthy(wallpaper_accepts_webp('IMAGE/WEBP'));
    same(false, wallpaper_accepts_webp('image/png'));
    same(false, wallpaper_accepts_webp(null));
});

check('URL encoding protects attributes and Location headers', function (): void {
    $url = wallpaper_url('image.example.com', 'horizontal_wallpapers/壁纸 #1?.jpg', false);
    same(
        'https://image.example.com/horizontal_wallpapers/%E5%A3%81%E7%BA%B8%20%231%3F.jpg',
        $url
    );
    same(false, str_contains($url, '"'));
    same(false, str_contains($url, "\r"));
    same(false, str_contains($url, "\n"));
});

check('URL adds the WebP transform when requested', function (): void {
    same(
        'https://image.example.com/a/b.jpg?imageMogr2/format/webp',
        wallpaper_url('image.example.com', '/a/b.jpg', true)
    );
});

check('URL accepts a CDN domain with a path prefix', function (): void {
    same(
        'https://cdn.example.com/img/a.jpg',
        wallpaper_url('https://cdn.example.com/img/', 'a.jpg', false)
    );
});

check('URL rejects bad input', function (): void {
    throws(fn () => wallpaper_url('evil".example.com', 'a.jpg', false), InvalidArgumentException::class);
    throws(fn () => wallpaper_url('image.example.com', '', false), InvalidArgumentException::class);
});

check('random pick is injectable and safe on an empty list', function (): void {
    same(null, wallpaper_pick([]));
    same('b', wallpaper_pick(['a', 'b', 'c'], fn (int $min, int $max): int => 1));
    same(null, wallpaper_pick(['a'], fn (int $min, int $max): int => 99)); // out-of-range RNG cannot crash
});

check('cache round-trip', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function () use ($dir): void {
            $file = wallpaper_cache_file('bucket', 'prefix/');
            same($dir . '/' . hash('sha256', 'bucket|prefix/') . '.json', $file);

            wallpaper_cache_write($file, ['b.jpg', 'a.jpg'], 60);
            $cached = wallpaper_cache_read($file);

            truthy($cached !== null, 'cache entry');
            same(['b.jpg', 'a.jpg'], $cached['keys']);
            truthy($cached['expires'] > time(), 'expiry');
            same(0600, fileperms($file) & 0777);
        });
    } finally {
        remove_dir($dir);
    }
});

check('corrupt cache is ignored', function (): void {
    $dir = temp_dir();
    try {
        $file = $dir . '/cache.json';
        file_put_contents($file, '{not json');
        same(null, wallpaper_cache_read($file));
        file_put_contents($file, json_encode(['keys' => 'nope', 'expires' => time()]));
        same(null, wallpaper_cache_read($file));
    } finally {
        remove_dir($dir);
    }
});

check('listing filters placeholders and non-string keys, then caches', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function () use ($dir): void {
            $lister = new FakeLister(fn (): array => [
                'Contents' => [
                    ['Key' => 'p/'],
                    ['Key' => 'p/sub/'],
                    ['Key' => 'p/b.jpg'],
                    ['Key' => 'p/a.jpg'],
                    ['Key' => 'p/c.jpg'],
                    ['Key' => 123],
                    ['Size' => 10],
                    ['Key' => 'p/a.jpg'], // duplicate
                ],
            ]);

            $keys = wallpaper_list_keys($lister, 'bucket', 'p/', 60);
            same(['p/a.jpg', 'p/b.jpg', 'p/c.jpg'], $keys);
            same(1, count($lister->calls), 'first call hits COS');

            $again = wallpaper_list_keys($lister, 'bucket', 'p/', 60);
            same($keys, $again);
            same(1, count($lister->calls), 'second call is served from cache');
        });
    } finally {
        remove_dir($dir);
    }
});

check('listing paginates with Marker', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function (): void {
            $lister = new FakeLister(function (array $params): array {
                if (($params['Marker'] ?? null) === null) {
                    return [
                        'Contents' => [['Key' => 'p/a.jpg']],
                        'IsTruncated' => true,
                        'NextMarker' => 'p/a.jpg',
                    ];
                }

                return [
                    'Contents' => [['Key' => 'p/b.jpg']],
                    'IsTruncated' => false,
                ];
            });

            $keys = wallpaper_list_keys($lister, 'bucket', 'p/', 60);
            same(['p/a.jpg', 'p/b.jpg'], $keys);
            same(2, count($lister->calls));
            same('p/a.jpg', $lister->calls[1]['Marker'] ?? null);
        });
    } finally {
        remove_dir($dir);
    }
});

check('stale cache is served when COS fails', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function () use ($dir): void {
            $file = wallpaper_cache_file('bucket', 'p/');
            wallpaper_cache_write($file, ['p/old.jpg'], 0); // already expired

            $lister = new FakeLister(fn (): array => throw new RuntimeException('COS is down'));
            $keys = wallpaper_list_keys($lister, 'bucket', 'p/', 60);

            same(['p/old.jpg'], $keys);
        });
    } finally {
        remove_dir($dir);
    }
});

check('COS failure without cache propagates', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function (): void {
            $lister = new FakeLister(fn (): array => throw new RuntimeException('COS is down'));
            throws(fn () => wallpaper_list_keys($lister, 'bucket', 'p/', 60), RuntimeException::class);
        });
    } finally {
        remove_dir($dir);
    }
});

check('empty listing is returned and not cached', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function () use ($dir): void {
            $lister = new FakeLister();
            same([], wallpaper_list_keys($lister, 'bucket', 'p/', 60));
            same(false, is_file($dir . '/' . hash('sha256', 'bucket|p/') . '.json'));
        });
    } finally {
        remove_dir($dir);
    }
});

check('configuration precedence: env > local file > legacy file', function (): void {
    $dir = temp_dir();
    try {
        file_put_contents(
            $dir . '/Tencent Key.php',
            "<?php\n\$bucket = 'legacy-bucket';\n\$region = 'legacy-region';\n"
            . "\$secretId = 'legacy-id';\n\$secretKey = 'legacy-key';\n"
        );
        file_put_contents(
            $dir . '/config.local.php',
            "<?php return ['bucket' => 'local-bucket', 'domain' => 'local.example.com'];\n"
        );

        with_env([
            'COS_BUCKET' => null,
            'COS_REGION' => null,
            'COS_SECRET_ID' => null,
            'COS_SECRET_KEY' => null,
            'COS_DOMAIN' => null,
            'COS_CACHE_TTL' => null,
        ], function () use ($dir): void {
            $config = wallpaper_config($dir);
            same('local-bucket', $config['bucket']);
            same('legacy-region', $config['region']);
            same('legacy-id', $config['secretId']);
            same('legacy-key', $config['secretKey']);
            same('local.example.com', $config['domain']);
            same(300, $config['ttl']);
        });

        with_env([
            'COS_BUCKET' => 'env-bucket',
            'COS_DOMAIN' => 'env.example.com',
            'COS_CACHE_TTL' => '42',
        ], function () use ($dir): void {
            $config = wallpaper_config($dir);
            same('env-bucket', $config['bucket']);
            same('env.example.com', $config['domain']);
            same(42, $config['ttl']);
        });
    } finally {
        remove_dir($dir);
    }
});

check('unreadable legacy key file does not block local config', function (): void {
    $dir = temp_dir();
    try {
        $legacy = $dir . '/Tencent Key.php';
        file_put_contents($legacy, "<?php\n\$bucket = 'legacy-bucket';\n");
        chmod($legacy, 0000);
        if (is_readable($legacy)) {
            return; // Running as root bypasses permission bits; nothing to assert.
        }
        file_put_contents(
            $dir . '/config.local.php',
            "<?php return ['bucket' => 'local-bucket', 'region' => 'r', 'secretId' => 'i', 'secretKey' => 'k'];\n"
        );
        with_env([
            'COS_BUCKET' => null,
            'COS_REGION' => null,
            'COS_SECRET_ID' => null,
            'COS_SECRET_KEY' => null,
            'COS_DOMAIN' => null,
            'COS_CACHE_TTL' => null,
        ], function () use ($dir): void {
            same('local-bucket', wallpaper_config($dir)['bucket']);
        });
    } finally {
        @chmod($dir . '/Tencent Key.php', 0644);
        remove_dir($dir);
    }
});

check('cache TTL is validated and clamped', function (): void {
    with_env(['COS_CACHE_TTL' => '-10'], function (): void {
        same(0, wallpaper_config()['ttl']);
    });
    with_env(['COS_CACHE_TTL' => '999999'], function (): void {
        same(86400, wallpaper_config()['ttl']);
    });
    with_env(['COS_CACHE_TTL' => 'nonsense'], function (): void {
        same(300, wallpaper_config()['ttl']);
    });
});

check('missing credentials fail without echoing values', function (): void {
    throws(function (): void {
        wallpaper_client(['bucket' => '', 'region' => '', 'secretId' => '', 'secretKey' => '', 'domain' => 'x', 'ttl' => 1]);
    }, RuntimeException::class);
});

check('resolve builds a URL from the right prefix', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function (): void {
            $lister = new FakeLister(function (array $params): array {
                same('catgirl_wallpapers/', $params['Prefix']);

                return ['Contents' => [['Key' => 'catgirl_wallpapers/one.jpg']]];
            });

            $url = wallpaper_resolve(BASE_CONFIG, 'vertical', 'image/png', $lister);
            same('https://image.example.com/catgirl_wallpapers/one.jpg', $url);
        });
    } finally {
        remove_dir($dir);
    }
});

check('resolve treats an array type as unknown', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function (): void {
            $lister = new FakeLister(function (array $params): array {
                same('horizontal_wallpapers/', $params['Prefix']);

                return ['Contents' => [['Key' => 'horizontal_wallpapers/a.jpg']]];
            });

            $url = wallpaper_resolve(BASE_CONFIG, ['vertical'], null, $lister);
            same('https://image.example.com/horizontal_wallpapers/a.jpg', $url);
        });
    } finally {
        remove_dir($dir);
    }
});

check('resolve rejects an empty folder', function (): void {
    $dir = temp_dir();
    try {
        with_env(['COS_CACHE_DIR' => $dir], function (): void {
            throws(fn () => wallpaper_resolve(BASE_CONFIG, 'horizontal', null, new FakeLister()), RuntimeException::class);
        });
    } finally {
        remove_dir($dir);
    }
});

echo "\n";
if ($failures === 0) {
    echo "All {$tests} tests passed.\n";
    exit(0);
}

echo "{$failures} of {$tests} tests failed.\n";
exit(1);
