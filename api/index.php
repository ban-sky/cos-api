<?php

declare(strict_types=1);

/**
 * Redirect endpoint: /api/?type=horizontal|vertical
 *
 * Nothing may be written to the response before the Location header, otherwise
 * PHP refuses to send it ("headers already sent"). This file used to emit an
 * HTML document first, which made every redirect fail.
 */

require dirname(__DIR__) . '/lib/bootstrap.php';

try {
    $url = wallpaper_resolve(
        wallpaper_config(),
        $_GET['type'] ?? null,
        $_SERVER['HTTP_ACCEPT'] ?? null
    );
} catch (Throwable $e) {
    cos_api_fail($e);
}

header('Location: ' . $url, true, 302);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
exit;
