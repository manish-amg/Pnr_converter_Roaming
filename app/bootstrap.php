<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'RoamingNepal\\PnrConverter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function app_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function load_settings(): array
{
    $settingsPath = app_path('config/settings.php');
    if (!is_file($settingsPath)) {
        $settingsPath = app_path('config/settings.example.php');
    }

    $settings = require $settingsPath;
    if (!is_array($settings)) {
        return [];
    }

    return $settings;
}
