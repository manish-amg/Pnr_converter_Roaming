<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Support;

final class Metadata
{
    private static ?array $airports = null;
    private static ?array $airlines = null;

    public static function airport(string $code): ?array
    {
        $code = strtoupper($code);
        $airports = self::load('airports');
        return $airports[$code] ?? null;
    }

    public static function airportLabel(string $code): string
    {
        $metadata = self::airport($code);
        if ($metadata === null) {
            return strtoupper($code);
        }

        $parts = array_filter([
            $metadata['city'] ?? null,
            $metadata['name'] ?? null,
            $metadata['country'] ?? null,
        ]);

        return strtoupper($code) . ' - ' . implode(', ', $parts);
    }

    public static function airlineName(string $code): ?string
    {
        $code = strtoupper($code);
        $airlines = self::load('airlines');
        return $airlines[$code]['name'] ?? null;
    }

    public static function distanceLabel(string $from, string $to, string $unit): ?string
    {
        $kilometers = self::distanceKm($from, $to);
        if ($kilometers === null || $unit === 'off') {
            return null;
        }

        if ($unit === 'miles') {
            return number_format($kilometers * 0.621371, 0) . ' miles';
        }

        return number_format($kilometers, 0) . ' km';
    }

    public static function distanceKm(string $from, string $to): ?float
    {
        $origin = self::airport($from);
        $destination = self::airport($to);
        if (!isset($origin['lat'], $origin['lon'], $destination['lat'], $destination['lon'])) {
            return null;
        }

        $earthRadiusKm = 6371;
        $lat1 = deg2rad((float) $origin['lat']);
        $lat2 = deg2rad((float) $destination['lat']);
        $deltaLat = deg2rad((float) $destination['lat'] - (float) $origin['lat']);
        $deltaLon = deg2rad((float) $destination['lon'] - (float) $origin['lon']);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return $earthRadiusKm * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private static function load(string $type): array
    {
        if ($type === 'airports') {
            if (self::$airports !== null) {
                return self::$airports;
            }
            $path = dirname(__DIR__, 2) . '/data/airports.php';
            self::$airports = is_file($path) ? (require $path) : [];
            return is_array(self::$airports) ? self::$airports : [];
        }

        if (self::$airlines !== null) {
            return self::$airlines;
        }
        $path = dirname(__DIR__, 2) . '/data/airlines.php';
        self::$airlines = is_file($path) ? (require $path) : [];
        return is_array(self::$airlines) ? self::$airlines : [];
    }
}
