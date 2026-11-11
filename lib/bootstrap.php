<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the two HTTP entry points.
 *
 * Loads Composer, then the wallpaper library, and keeps PHP error output away
 * from HTTP responses so that stack traces and paths never reach clients.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit("This application requires PHP 8.1 or newer.\n");
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit("Dependencies are not installed. Run \"composer install\".\n");
}

require $autoload;
require __DIR__ . '/wallpaper.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!headers_sent()) {
    header_remove('X-Powered-By');
}

/**
 * Turn an unexpected failure into a small, cache-free 503 response.
 */
function cos_api_fail(Throwable $e): never
{
    error_log('cos-api: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    echo "Wallpaper service temporarily unavailable.\n";

    exit;
}
