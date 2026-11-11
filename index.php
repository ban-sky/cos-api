<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

try {
    $url = wallpaper_resolve(
        wallpaper_config(),
        $_GET['type'] ?? null,
        $_SERVER['HTTP_ACCEPT'] ?? null
    );
} catch (Throwable $e) {
    cos_api_fail($e);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'none'; img-src https:; style-src 'self'; "
    . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wallpaper</title>
    <link rel="icon" href="favicon.ico">
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body>
<img class="wallpaper" src="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Wallpaper">
</body>
</html>
