<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Support;

final class Html
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function checked(bool $value): string
    {
        return $value ? ' checked' : '';
    }
}
