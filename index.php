<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Parser\PnrParserFactory;
use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\Html;
use RoamingNepal\PnrConverter\Support\PrivacyLogger;

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireLogin('login.php');

function checkRateLimit(): bool
{
    $ip = hash('sha256', (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = sys_get_temp_dir() . '/pnr_rl_' . $ip . '.json';
    $now = time();
    $window = 3600;
    $limit = 40;

    $data = ['count' => 0, 'reset' => $now + $window];
    if (is_readable($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $loaded = @json_decode($raw, true);
            if (is_array($loaded) && isset($loaded['count'], $loaded['reset']) && (int) $loaded['reset'] > $now) {
                $data = ['count' => (int) $loaded['count'], 'reset' => (int) $loaded['reset']];
            }
        }
    }

    if ($data['count'] >= $limit) {
        return false;
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

$settings = load_settings();
$features = $settings['features'] ?? [];
$rawInput = '';
$result = null;

$rateLimited = false;
$dailyLimitReached = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = isset($_POST['pnr_text']) ? (string) $_POST['pnr_text'] : '';
    if (trim($rawInput) !== '' && !checkRateLimit()) {
        $rateLimited = true;
    }

    // Only count against the daily cap when the PNR text actually changed —
    // re-submits triggered by toggling display options must not burn a credit.
    $inputHash = trim($rawInput) !== '' ? hash('sha256', trim($rawInput)) : null;
    $isNewConversion = $inputHash !== null && $inputHash !== ($_SESSION['pnrc_last_hash'] ?? null);

    if (trim($rawInput) !== '' && !$rateLimited && $isNewConversion && Auth::dailyLimitReached()) {
        $dailyLimitReached = true;
    }

    if (trim($rawInput) !== '' && !$rateLimited && !$dailyLimitReached) {
        $result = PnrParserFactory::parse($rawInput);
        PrivacyLogger::log($result, (bool) ($settings['privacy_logging_enabled'] ?? false));
        if ($isNewConversion) {
            Auth::recordConversion();
            $_SESSION['pnrc_last_hash'] = $inputHash;
        }
    }
    foreach ($features as $key => $value) {
        if (array_key_exists($key, $_POST)) {
            if ($key === 'distance_unit') {
                $posted = (string) $_POST[$key];
                $features[$key] = in_array($posted, ['off', 'km', 'miles'], true) ? $posted : 'off';
                continue;
            }

            if ($key === 'result_format') {
                $posted = (string) $_POST[$key];
                $allowed = ['detailed', 'compact', 'table', 'whatsapp', 'two_lines', 'two_lines_reordered', 'three_lines', 'three_lines_reordered'];
                $features[$key] = in_array($posted, $allowed, true) ? $posted : 'detailed';
                continue;
            }

            $features[$key] = $_POST[$key] === '1';
        }
    }

    // Handle boolean feature keys that come from the UI but may not exist in config/settings.php
    foreach (['show_must_read'] as $extraKey) {
        if (array_key_exists($extraKey, $_POST)) {
            $features[$extraKey] = $_POST[$extraKey] === '1';
        }
    }

    if (isset($features['use_12_hour_clock'])) {
        $features['use_24_hour_time'] = !(bool) $features['use_12_hour_clock'];
    }
}

require __DIR__ . '/app/View/page.php';
