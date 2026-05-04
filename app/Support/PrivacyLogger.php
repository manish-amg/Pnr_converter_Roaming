<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Support;

use RoamingNepal\PnrConverter\Parser\ParseResult;

final class PrivacyLogger
{
    public static function log(ParseResult $result, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        $entry = [
            'timestamp' => gmdate('c'),
            'success' => $result->isRenderable(),
            'source_format' => $result->sourceFormat,
            'confidence' => $result->confidence,
            'segment_count' => count($result->segments),
        ];

        file_put_contents($dir . '/technical.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
