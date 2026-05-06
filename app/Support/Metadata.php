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

    public static function airportTimezone(string $code): ?string
    {
        static $map = [
            // Nepal
            'KTM' => 'Asia/Kathmandu', 'PKR' => 'Asia/Kathmandu', 'BWA' => 'Asia/Kathmandu',
            // Middle East
            'DXB' => 'Asia/Dubai', 'AUH' => 'Asia/Dubai', 'SHJ' => 'Asia/Dubai',
            'DOH' => 'Asia/Qatar', 'BAH' => 'Asia/Bahrain', 'KWI' => 'Asia/Kuwait',
            'AMM' => 'Asia/Amman', 'RUH' => 'Asia/Riyadh', 'JED' => 'Asia/Riyadh', 'MED' => 'Asia/Riyadh',
            'MCT' => 'Asia/Muscat', 'ADE' => 'Asia/Aden',
            // Europe
            'LHR' => 'Europe/London', 'LGW' => 'Europe/London', 'MAN' => 'Europe/London',
            'STN' => 'Europe/London', 'LTN' => 'Europe/London', 'BHX' => 'Europe/London',
            'CDG' => 'Europe/Paris', 'ORY' => 'Europe/Paris',
            'FRA' => 'Europe/Berlin', 'MUC' => 'Europe/Berlin', 'DUS' => 'Europe/Berlin', 'HAM' => 'Europe/Berlin', 'BER' => 'Europe/Berlin',
            'AMS' => 'Europe/Amsterdam',
            'ZRH' => 'Europe/Zurich', 'GVA' => 'Europe/Zurich',
            'VIE' => 'Europe/Vienna',
            'FCO' => 'Europe/Rome', 'MXP' => 'Europe/Rome', 'NAP' => 'Europe/Rome',
            'BCN' => 'Europe/Madrid', 'MAD' => 'Europe/Madrid',
            'IST' => 'Europe/Istanbul', 'SAW' => 'Europe/Istanbul',
            'OSL' => 'Europe/Oslo', 'BGO' => 'Europe/Oslo',
            'CPH' => 'Europe/Copenhagen',
            'ARN' => 'Europe/Stockholm', 'GOT' => 'Europe/Stockholm',
            'HEL' => 'Europe/Helsinki',
            'BRU' => 'Europe/Brussels',
            'WAW' => 'Europe/Warsaw',
            'PRG' => 'Europe/Prague',
            'BUD' => 'Europe/Budapest',
            'ATH' => 'Europe/Athens',
            'LIS' => 'Europe/Lisbon',
            'DUB' => 'Europe/Dublin',
            // Russia / CIS
            'SVO' => 'Europe/Moscow', 'DME' => 'Europe/Moscow', 'VKO' => 'Europe/Moscow',
            // Caucasus / Central Asia
            'GYD' => 'Asia/Baku', 'TBS' => 'Asia/Tbilisi', 'EVN' => 'Asia/Yerevan',
            'TAS' => 'Asia/Tashkent', 'FRU' => 'Asia/Bishkek', 'ASB' => 'Asia/Ashgabat',
            'ALA' => 'Asia/Almaty', 'NQZ' => 'Asia/Almaty',
            // Australia / Pacific
            'MEL' => 'Australia/Melbourne', 'AVV' => 'Australia/Melbourne',
            'SYD' => 'Australia/Sydney', 'CBR' => 'Australia/Sydney',
            'BNE' => 'Australia/Brisbane', 'CNS' => 'Australia/Brisbane',
            'PER' => 'Australia/Perth',
            'ADL' => 'Australia/Adelaide',
            'AKL' => 'Pacific/Auckland', 'CHC' => 'Pacific/Auckland',
            // Americas
            'JFK' => 'America/New_York', 'EWR' => 'America/New_York', 'BOS' => 'America/New_York',
            'IAD' => 'America/New_York', 'MIA' => 'America/New_York', 'ATL' => 'America/New_York', 'DTW' => 'America/New_York',
            'ORD' => 'America/Chicago', 'DFW' => 'America/Chicago', 'IAH' => 'America/Chicago',
            'DEN' => 'America/Denver',
            'LAX' => 'America/Los_Angeles', 'SFO' => 'America/Los_Angeles', 'SEA' => 'America/Los_Angeles',
            'YYZ' => 'America/Toronto', 'YUL' => 'America/Toronto',
            'YVR' => 'America/Vancouver',
            'GRU' => 'America/Sao_Paulo', 'EZE' => 'America/Argentina/Buenos_Aires',
            'BOG' => 'America/Bogota', 'LIM' => 'America/Lima', 'SCL' => 'America/Santiago',
            'MEX' => 'America/Mexico_City', 'CUN' => 'America/Cancun',
            'JNB' => 'Africa/Johannesburg', 'CPT' => 'Africa/Johannesburg',
            'NBO' => 'Africa/Nairobi', 'ADD' => 'Africa/Addis_Ababa',
            'CAI' => 'Africa/Cairo', 'CMN' => 'Africa/Casablanca',
            'LOS' => 'Africa/Lagos', 'ACC' => 'Africa/Accra',
            // South Asia
            'DEL' => 'Asia/Kolkata', 'BOM' => 'Asia/Kolkata', 'CCU' => 'Asia/Kolkata',
            'MAA' => 'Asia/Kolkata', 'BLR' => 'Asia/Kolkata', 'HYD' => 'Asia/Kolkata',
            'AMD' => 'Asia/Kolkata', 'GOI' => 'Asia/Kolkata', 'COK' => 'Asia/Kolkata',
            'CMB' => 'Asia/Colombo',
            'DAC' => 'Asia/Dhaka', 'ZYL' => 'Asia/Dhaka',
            'MLE' => 'Indian/Maldives',
            'KHI' => 'Asia/Karachi', 'LHE' => 'Asia/Karachi', 'ISB' => 'Asia/Karachi',
            // East / Southeast Asia
            'BKK' => 'Asia/Bangkok', 'DMK' => 'Asia/Bangkok', 'HKT' => 'Asia/Bangkok',
            'SIN' => 'Asia/Singapore',
            'KUL' => 'Asia/Kuala_Lumpur', 'PEN' => 'Asia/Kuala_Lumpur', 'LGK' => 'Asia/Kuala_Lumpur',
            'HKG' => 'Asia/Hong_Kong',
            'NRT' => 'Asia/Tokyo', 'HND' => 'Asia/Tokyo', 'KIX' => 'Asia/Tokyo', 'ITM' => 'Asia/Tokyo',
            'ICN' => 'Asia/Seoul', 'GMP' => 'Asia/Seoul',
            'PVG' => 'Asia/Shanghai', 'PEK' => 'Asia/Shanghai', 'CAN' => 'Asia/Shanghai', 'CTU' => 'Asia/Shanghai',
            'TPE' => 'Asia/Taipei',
            'MNL' => 'Asia/Manila',
            'CGK' => 'Asia/Jakarta', 'DPS' => 'Asia/Makassar',
            'SGN' => 'Asia/Ho_Chi_Minh', 'HAN' => 'Asia/Bangkok',
            'RGN' => 'Asia/Rangoon',
            'KTI' => 'Asia/Phnom_Penh', 'PNH' => 'Asia/Phnom_Penh',
            'VTE' => 'Asia/Vientiane',
            'REP' => 'Asia/Phnom_Penh',
        ];
        return $map[strtoupper($code)] ?? null;
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
