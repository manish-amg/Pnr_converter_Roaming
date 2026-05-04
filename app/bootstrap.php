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
    $defaultSettings = [];
    $defaultPath = app_path('config/settings.example.php');
    if (is_file($defaultPath)) {
        $defaultSettings = require $defaultPath;
    }

    $settings = [];
    $settingsPath = app_path('config/settings.php');
    if (is_file($settingsPath)) {
        $settings = require $settingsPath;
    }

    if (!is_array($defaultSettings)) {
        $defaultSettings = [];
    }
    if (!is_array($settings)) {
        $settings = [];
    }

    return merge_settings($defaultSettings, $settings);
}

function merge_settings(array $defaults, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
            $defaults[$key] = merge_settings($defaults[$key], $value);
            continue;
        }

        $defaults[$key] = $value;
    }

    return $defaults;
}
