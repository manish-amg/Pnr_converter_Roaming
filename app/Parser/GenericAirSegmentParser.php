<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

use RoamingNepal\PnrConverter\Support\Metadata;

final class GenericAirSegmentParser extends BaseParser
{
    public function detect(string $raw): int
    {
        $matches = preg_match_all('/^\s*\d+\s*\.?\s*[A-Z0-9]{2}\s*\d{1,4}[A-Z]?(?:\s+[A-Z])?\s+\d{1,2}[A-Z]{3}(?:\d{2,4})?\s+(?:[1-7]\*?)?[A-Z]{3}\s*[A-Z]{3}\s+[A-Z]{2}\d?\s+\d{3,4}\s+#?\d{3,4}/mi', $raw);
        if ($matches === false || $matches === 0) {
            return 0;
        }

        return min(70, 30 + ($matches * 15));
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

            if ($this->looksLikeAirSegment($line)) {
                $unparsed[] = $line;
            }
        }

        $segments = $this->withLayovers($segments);
        [$confidence, $score] = $this->confidence($segments, $unparsed, $this->detect($raw));
        $warnings = [];
        if ($confidence === 'medium') {
            $warnings[] = 'The itinerary was parsed with the flexible GDS fallback. Please review before sending.';
        }
        if ($confidence === 'low') {
            $warnings[] = 'The parser could not safely create a passenger-ready itinerary.';
        }

        return new ParseResult(
            'Generic GDS air segment style',
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
        $pattern = '/^\s*\d+\s*\.?\s*([A-Z0-9]{2})\s*([0-9]{1,4})([A-Z])?(?:\s+([A-Z]))?\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+(?:[1-7]\*?)?([A-Z]{3})\s*([A-Z]{3})\s+([A-Z]{2}\d?)(?:\s+\d+)?\s+([#0-9APMapm:]{3,8})\s+([#0-9APMapm:]{3,8})(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?/i';
        if (preg_match($pattern, $line, $m) !== 1) {
            return null;
        }

        $airlineCode = strtoupper($m[1]);
        $bookingClass = strtoupper($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
        $bookingClass = $bookingClass !== '' ? $bookingClass : null;
        $departureDate = $this->normalizeDate($m[5]);
        $arrivalTimeRaw = $m[10];

        return new Segment(
            $airlineCode,
            $m[2],
            Metadata::airlineName($airlineCode),
            strtoupper($m[8]),
            strtoupper($m[6]),
            strtoupper($m[7]),
            $departureDate,
            $this->normalizeTime($m[9]),
            $this->arrivalDate($departureDate, $arrivalTimeRaw, $m[11] ?? null),
            $this->normalizeTime($arrivalTimeRaw),
            $bookingClass,
            $this->cabinFromClass($bookingClass),
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

        if (!str_starts_with(trim($arrivalTimeRaw), '#')) {
            return null;
        }

        return $this->addOneDay($departureDate);
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

    private function looksLikeAirSegment(string $line): bool
    {
        return preg_match('/^\s*\d+\s*\.?\s*[A-Z0-9]{2}\s*\d{1,4}[A-Z]?\b/i', $line) === 1;
    }
}
