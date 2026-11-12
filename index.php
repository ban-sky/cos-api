<?php
declare(strict_types=1);

use Qcloud\Cos\Client;

// 生产环境不要把错误详情返回给访客，只写入服务器日志。
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

/**
 * 统一返回 503，避免把路径、堆栈、密钥等信息暴露给访客。
 */
$fail = static function (Throwable $e) use ($obLevel): void {
    error_log('cos-api: ' . $e->getMessage());

    while (ob_get_level() > $obLevel) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<title>服务暂不可用</title></head><body>'
        . '<p>壁纸服务暂不可用，请稍后再试。</p></body></html>';
    exit;
};

try {
    require __DIR__ . '/vendor/autoload.php';
} catch (Throwable $e) {
    $fail($e);
}

// 默认值保证旧版配置文件（没有 $domain）仍可原样使用。
$bucket = '';
$region = '';
$secretId = '';
$secretKey = '';
$domain = WALLPAPER_DEFAULT_DOMAIN;

// 兼容原版 key/Tencent Key.php（变量写法），文件不可读时不再致命。
$configFile = __DIR__ . '/key/Tencent Key.php';
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

// 可选：真实密钥放在 key/config.local.php（数组或变量写法均可），避免提交到 Git。
$localFile = __DIR__ . '/key/config.local.php';
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

    // 只允许固定的两种值；数组等异常输入回退到横屏，避免 TypeError。
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

    // 过滤目录占位对象和异常返回，避免空数组触发 array_rand 错误。
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

    // 对每一段路径编码，空格、#、?、中文等不会再破坏 URL 或形成注入。
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
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'none'; img-src https:; style-src 'self'; base-uri 'none'; form-action 'none'");
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>随机壁纸</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body>
<img class="wallpaper" src="<?= htmlspecialchars($wallpaperUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Wallpaper">
</body>
</html>
