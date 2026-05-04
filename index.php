<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Parser\PnrParserFactory;
use RoamingNepal\PnrConverter\Support\Html;
use RoamingNepal\PnrConverter\Support\PrivacyLogger;

require_once __DIR__ . '/app/bootstrap.php';

$settings = load_settings();
$features = $settings['features'] ?? [];
$rawInput = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = isset($_POST['pnr_text']) ? (string) $_POST['pnr_text'] : '';
    if (trim($rawInput) !== '') {
        $result = PnrParserFactory::parse($rawInput);
        PrivacyLogger::log($result, (bool) ($settings['privacy_logging_enabled'] ?? false));
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

    if (isset($features['use_12_hour_clock'])) {
        $features['use_24_hour_time'] = !(bool) $features['use_12_hour_clock'];
    }
}

require __DIR__ . '/app/View/page.php';
