<?php

declare(strict_types=1);

/**
 * Build the deployable archive: `php tools/build.php` (or `composer build`).
 *
 * Uses ext-zip instead of a platform-specific `zip`/`Compress-Archive` binary,
 * so the same command works on Linux, macOS and Windows CI.
 */

$root = dirname(__DIR__);
$zipPath = $root . '/cos-api.zip';

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The zip extension (ext-zip) is required to build the archive.\n");
    exit(1);
}

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are missing. Run \"composer install --no-dev --prefer-dist\" first.\n");
    exit(1);
}

if (is_file($zipPath) && !@unlink($zipPath)) {
    fwrite(STDERR, "Cannot replace existing archive: {$zipPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create archive: {$zipPath}\n");
    exit(1);
}

$entries = ['index.php', 'api', 'lib', 'key', 'styles.css', 'favicon.ico', 'vendor'];
$excluded = ['key/config.local.php'];

$add = function (string $path) use (&$add, $zip, $root, $excluded): void {
    $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));

    if ($relative === '' || in_array($relative, $excluded, true) || basename($relative) === '.DS_Store') {
        return;
    }

    if (is_dir($path)) {
        $zip->addEmptyDir($relative);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $add($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        return;
    }

    if (is_file($path)) {
        $zip->addFile($path, $relative);
    }
};

foreach ($entries as $entry) {
    $add($root . DIRECTORY_SEPARATOR . $entry);
}

if (!$zip->close()) {
    fwrite(STDERR, "Failed to write archive: {$zipPath}\n");
    exit(1);
}

printf("Built %s (%s bytes)\n", $zipPath, number_format((int) filesize($zipPath)));
