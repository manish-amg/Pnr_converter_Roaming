<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

use RoamingNepal\PnrConverter\Support\Metadata;

/**
 * Parses casual / plain-text itinerary formats that lack GDS boilerplate.
 *
 * Handles patterns like:
 *   TK 727  10JUN KTMIST   0740 1300  10JUN
 *   QR 647 21MAY KTM DOH 1910 2200
 *   EK 385 15JUN DXB SYD 0230 2110 16JUN
 *   9N 701 12JUN KTM PKR 0615 0645
 */
final class CasualFormatParser extends BaseParser
{
    public function detect(string $raw): int
    {
        // Must NOT look like a GDS PNR (no line numbers, no status codes like HK/DK/SS)
        if (preg_match('/^\s*\d+\s+[A-Z0-9]{2}\s*\d{1,4}.*\b(?:HK|DK|SS|HL|HX|UC)\d+\b/mi', $raw) === 1) {
            return 0; // Looks like structured GDS — let other parsers handle it
        }
        if (preg_match('/\bRP\/[A-Z0-9]+\//i', $raw) === 1) {
            return 0; // Amadeus header
        }

        $matches = preg_match_all(
            '/^\s*[A-Z0-9]{2}\s+\d{1,4}[A-Z]?\s+\d{1,2}[A-Z]{3}(?:\d{2,4})?\s+[A-Z]{3}\s*[A-Z]{3}\s+\d{3,4}\s+\d{3,4}/mi',
            $raw
        );
        if ($matches === false || $matches === 0) {
            return 0;
        }

        return min(85, 40 + ($matches * 20));
    }

    public function parse(string $raw): ParseResult
    {
        $lines    = $this->lines($raw);
        $segments = [];
        $unparsed = [];
        $ticket   = $this->extractTicketNumber($lines);
        $seat     = $this->extractSeatNumber($lines);

        foreach ($lines as $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }

            $segment = $this->parseSegmentLine($line, $ticket, $seat);
            if ($segment !== null) {
                $segments[] = $segment;
                continue;
            }

            if ($this->looksLikeSegment($line)) {
                $unparsed[] = $line;
            }
        }

        $segments = $this->withLayovers($segments);
        [$confidence, $score] = $this->confidence($segments, $unparsed, $this->detect($raw));
        $warnings = [];
        if ($confidence === 'low' && count($segments) > 0) {
            $confidence = 'medium';
            $score      = max(50, $score);
        }
        if (count($segments) === 0) {
            $warnings[] = 'The parser could not safely create a passenger-ready itinerary.';
        }

        return new ParseResult(
            'Casual / plain-text format',
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
        // Pattern: AIRLINE FLIGHT  DATE  DEP[space]?ARR  DEPTIME ARRTIME  [ARRDATE]
        // Airports can be concatenated (KTMIST) or space-separated (KTM IST)
        $pattern = '/^\s*([A-Z0-9]{2,3})\s+(\d{1,4}[A-Z]?)\s+'      // airline + flight
            . '(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+'                      // dep date
            . '([A-Z]{3})\s*([A-Z]{3})\s+'                            // dep + arr airports
            . '(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)\s+'           // dep time
            . '(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)'              // arr time
            . '(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?/i';             // optional arr date

        if (preg_match($pattern, $line, $m) !== 1) {
            // Try 6-char concatenated airport block: KTMIST
            $pattern2 = '/^\s*([A-Z0-9]{2,3})\s+(\d{1,4}[A-Z]?)\s+'
                . '(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+'
                . '([A-Z]{6})\s+'
                . '(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)\s+'
                . '(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)'
                . '(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?/i';

            if (preg_match($pattern2, $line, $m2) !== 1) {
                return null;
            }

            // Map 6-char groups into the same positions as the first pattern
            $m = [
                $m2[0],
                $m2[1],                     // airline
                $m2[2],                     // flight number
                $m2[3],                     // dep date
                substr(strtoupper($m2[4]), 0, 3), // dep airport (first 3)
                substr(strtoupper($m2[4]), 3, 3), // arr airport (last 3)
                $m2[5],                     // dep time
                $m2[6],                     // arr time
                $m2[7] ?? '',               // optional arr date
            ];
        }

        $airlineCode    = strtoupper($m[1]);
        $departureDate  = $this->normalizeDate($m[3]);
        $arrivalTimeRaw = $m[7];
        $explicitArrDate = isset($m[8]) && $m[8] !== '' ? $m[8] : null;

        // Determine arrival date
        $arrivalDate = null;
        if ($explicitArrDate !== null) {
            $arrivalDate = $this->normalizeDate($explicitArrDate);
        } elseif (str_starts_with(trim($arrivalTimeRaw), '#')) {
            $arrivalDate = $this->addOneDay($departureDate);
        }

        return new Segment(
            $airlineCode,
            $m[2],
            Metadata::airlineName($airlineCode),
            'OK',
            strtoupper($m[4]),
            strtoupper($m[5]),
            $departureDate,
            $this->normalizeTime($m[6]),
            $arrivalDate,
            $this->normalizeTime($arrivalTimeRaw),
            null,
            null,
            null,
            $this->extractOperatedBy($line),
            $ticket,
            $seat,
            $this->extractAircraft($line),
            $line
        );
    }

    private function looksLikeSegment(string $line): bool
    {
        return preg_match('/^\s*[A-Z0-9]{2}\s+\d{1,4}/i', $line) === 1;
    }

    private function addOneDay(string $date): string
    {
        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
            'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
        if (preg_match('/^(\d{2})([A-Z]{3})(\d{4})?$/', strtoupper($date), $m) !== 1) {
            return $date;
        }

        $month = $months[$m[2]] ?? null;
        if ($month === null) {
            return $date;
        }

        $year    = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');
        $nextDay = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, (int) $m[1])))->modify('+1 day');
        $formatted = strtoupper($nextDay->format('dM'));

        return isset($m[3]) && $m[3] !== '' ? $formatted . $nextDay->format('Y') : $formatted;
    }
}
