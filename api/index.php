<?php

declare(strict_types=1);

/**
 * Vercel serverless filesystem is read-only except /tmp.
 * Point Laravel cache artifact paths to a writable directory.
 */
$runtimeCacheDir = sys_get_temp_dir() . '/laravel-bootstrap-cache';

if (!is_dir($runtimeCacheDir)) {
    @mkdir($runtimeCacheDir, 0777, true);
}

$cachePaths = [
    'APP_CONFIG_CACHE' => $runtimeCacheDir . '/config.php',
    'APP_EVENTS_CACHE' => $runtimeCacheDir . '/events.php',
    'APP_PACKAGES_CACHE' => $runtimeCacheDir . '/packages.php',
    'APP_ROUTES_CACHE' => $runtimeCacheDir . '/routes.php',
    'APP_SERVICES_CACHE' => $runtimeCacheDir . '/services.php',
    'VIEW_COMPILED_PATH' => $runtimeCacheDir . '/views',
];

foreach ($cachePaths as $envKey => $cachePath) {
    if (!isset($_ENV[$envKey]) || $_ENV[$envKey] === '') {
        $_ENV[$envKey] = $cachePath;
        $_SERVER[$envKey] = $cachePath;
        putenv($envKey . '=' . $cachePath);
    }
}

$compiledViewsPath = $_ENV['VIEW_COMPILED_PATH'] ?? ($runtimeCacheDir . '/views');
if (!is_dir($compiledViewsPath)) {
    @mkdir($compiledViewsPath, 0777, true);
}

require __DIR__ . '/../public/index.php';
