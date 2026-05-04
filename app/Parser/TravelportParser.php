<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

use RoamingNepal\PnrConverter\Support\Metadata;

final class TravelportParser extends BaseParser
{
    public function detect(string $raw): int
    {
        $score = 0;
        if (preg_match('/\b(?:GALILEO|SMARTPOINT|WORLDSPAN|TRAVELPORT)\b/i', $raw) === 1) {
            $score += 45;
        }
        if (preg_match('/\b(?:VENDOR LOCATOR|RECORD LOCATOR|RLOC)\b/i', $raw) === 1) {
            $score += 20;
        }
        if (preg_match('/^\s*\d+\s*\.?\s*[A-Z0-9]{2}\s*\d{1,4}\s+[A-Z]\s+\d{1,2}[A-Z]{3}/mi', $raw) === 1) {
            $score += 35;
        }
        if (preg_match('/^\s*\d+\s*\.\s*[A-Z0-9]{2}\s+\d{1,4}(?:\s+[A-Z])?\s+\d{1,2}[A-Z]{3}\s+[A-Z]{6}\s+[A-Z]{2}\d?\s+\d{3,4}\s+#?\d{3,4}/mi', $raw) === 1) {
            $score += 35;
        }
        if (preg_match('/^\s*\d+\.\d+[A-Z]+\/[A-Z]+/mi', $raw) === 1) {
            $score += 15;
        }

        return min(100, $score);
    }

    public function parse(string $raw): ParseResult
    {
        $lines = $this->lines($raw);
        $segments = [];
        $unparsed = [];
        $ticket = $this->extractTicketNumber($lines);
        $seat = $this->extractSeatNumber($lines);

        foreach ($lines as $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }

            $segment = $this->parseSegmentLine($line, $ticket, $seat);
            if ($segment !== null) {
                $segments[] = $segment;
                continue;
            }

            if ($this->looksImportantButUnhandled($line)) {
                $unparsed[] = $line;
            }
        }

        $segments = $this->withLayovers($segments);
        [$confidence, $score] = $this->confidence($segments, $unparsed, $this->detect($raw));
        $warnings = $confidence === 'medium' ? ['Some itinerary lines need manual review before sending.'] : [];
        if ($confidence === 'low') {
            $warnings[] = 'The parser could not safely create a passenger-ready itinerary.';
        }

        return new ParseResult(
            'Travelport Galileo / Smartpoint / Worldspan style',
            $confidence,
            $score,
            $this->extractPassengers($lines),
            $segments,
            $this->extractRecordLocator($lines),
            $warnings,
            $unparsed
        );
    }

    private function parseSegmentLine(string $line, ?string $ticket, ?string $seat): ?Segment
    {
        $classicPattern = '/^\s*\d+\.?\s*([A-Z0-9]{2})\s*([0-9]{1,4})\s+([A-Z])\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+([A-Z]{3})\s*([A-Z]{3})\s+([A-Z]{2}\d?)\s+([#0-9APMapm:]{3,8})\s+([#0-9APMapm:]{3,8})(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?/i';
        if (preg_match($classicPattern, $line, $m) === 1) {
            $airlineCode = strtoupper($m[1]);
            $departureDate = $this->normalizeDate($m[4]);
            $arrivalTimeRaw = $m[9];

            return new Segment(
                $airlineCode,
                $m[2],
                Metadata::airlineName($airlineCode),
                strtoupper($m[7]),
                strtoupper($m[5]),
                strtoupper($m[6]),
                $departureDate,
                $this->normalizeTime($m[8]),
                $this->arrivalDate($departureDate, $arrivalTimeRaw, $m[10] ?? null),
                $this->normalizeTime($arrivalTimeRaw),
                strtoupper($m[3]),
                $this->cabinFromClass($m[3]),
                null,
                $this->extractOperatedBy($line),
                $ticket,
                $seat,
                $this->extractAircraft($line),
                $line
            );
        }

        $compactPattern = '/^\s*\d+\s*\.\s*([A-Z0-9]{2})\s+([0-9]{1,4})(?:\s+([A-Z]))?\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+([A-Z]{3})([A-Z]{3})\s+([A-Z]{2}\d?)\s+([#0-9APMapm:]{3,8})\s+([#0-9APMapm:]{3,8})(?:\s+([A-Z]))?(?:\s+([A-Z]))?/i';
        if (preg_match($compactPattern, $line, $m) !== 1) {
            return null;
        }

        $airlineCode = strtoupper($m[1]);
        $bookingClass = isset($m[3]) && $m[3] !== '' ? strtoupper($m[3]) : (isset($m[10]) && $m[10] !== '' ? strtoupper($m[10]) : null);
        $cabin = $bookingClass !== null ? $this->cabinFromClass($bookingClass) : $this->cabinFromTravelportCode($m[11] ?? null);
        $departureDate = $this->normalizeDate($m[4]);
        $arrivalTimeRaw = $m[9];

        return new Segment(
            $airlineCode,
            $m[2],
            Metadata::airlineName($airlineCode),
            strtoupper($m[7]),
            strtoupper($m[5]),
            strtoupper($m[6]),
            $departureDate,
            $this->normalizeTime($m[8]),
            $this->arrivalDate($departureDate, $arrivalTimeRaw, null),
            $this->normalizeTime($arrivalTimeRaw),
            $bookingClass,
            $cabin,
            null,
            $this->extractOperatedBy($line),
            $ticket,
            $seat,
            $this->extractAircraft($line),
            $line
        );
    }

    private function arrivalDate(string $departureDate, string $arrivalTimeRaw, ?string $explicitArrivalDate): ?string
    {
        if ($explicitArrivalDate !== null && trim($explicitArrivalDate) !== '') {
            return $this->normalizeDate($explicitArrivalDate);
        }

        if (str_starts_with(trim($arrivalTimeRaw), '#')) {
            return $this->addOneDay($departureDate);
        }

        return null;
    }

    private function addOneDay(string $date): string
    {
        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
        if (preg_match('/^(\d{2})([A-Z]{3})(\d{4})?$/', strtoupper($date), $m) !== 1) {
            return $date;
        }

        $month = $months[$m[2]] ?? null;
        if ($month === null) {
            return $date;
        }

        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');
        $nextDay = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, (int) $m[1])))->modify('+1 day');
        $formatted = strtoupper($nextDay->format('dM'));

        return isset($m[3]) && $m[3] !== '' ? $formatted . $nextDay->format('Y') : $formatted;
    }

    private function cabinFromTravelportCode(?string $code): ?string
    {
        return match (strtoupper((string) $code)) {
            'F' => 'First',
            'C', 'J' => 'Business',
            'W', 'P' => 'Premium Economy',
            'Y', 'E' => 'Economy',
            default => null,
        };
    }

    private function looksImportantButUnhandled(string $line): bool
    {
        return preg_match('/^\s*\d+\.?\s+[A-Z0-9]{2}|\b(?:AIR|SEG|HK|HL|RLOC)\b/i', $line) === 1;
    }
}
