<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

use RoamingNepal\PnrConverter\Support\Metadata;

final class GenericAirSegmentParser extends BaseParser
{
    public function detect(string $raw): int
    {
        $matches = preg_match_all('/^\s*\d+\s*\.?\s*[A-Z0-9]{2}\s*\d{1,4}[A-Z]?(?:\s+[A-Z])?\s+\d{1,2}[A-Z]{3}(?:\d{2,4})?\s+(?:[1-7]\*?\s*)?[A-Z]{3}\s*[A-Z]{3}\s+[A-Z]{2}\d?\s+\d{3,4}\s+#?\d{3,4}/mi', $raw);
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

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($this->isSensitiveLine($line)) {
                continue;
            }

            $segment = $this->parseSegmentLine($line, $ticket, $seat);
            if ($segment !== null) {
                // Look ahead for "OPERATED BY ..." continuation lines
                while (isset($lines[$i + 1]) && $this->isContinuationLine($lines[$i + 1])) {
                    $i++;
                    $cont = $lines[$i];
                    if (preg_match('/^\s*OPERATED\s+BY\s+(.+)/i', $cont, $cm) === 1) {
                        $operatedBy = trim($cm[1]);
                        $segment = new Segment(
                            $segment->airlineCode, $segment->flightNumber, $segment->airlineName,
                            $segment->status, $segment->departureAirport, $segment->arrivalAirport,
                            $segment->departureDate, $segment->departureTime,
                            $segment->arrivalDate, $segment->arrivalTime,
                            $segment->bookingClass, $segment->cabin,
                            $segment->layoverDuration, $operatedBy,
                            $segment->ticketNumber, $segment->seatNumber,
                            $segment->aircraft, $segment->rawLine,
                            $segment->departureTerminal
                        );
                    }
                }
                $segments[] = $segment;
                continue;
            }

            if ($this->looksLikeAirSegment($line)) {
                $unparsed[] = $line;
            }
        }

        $segments = $this->withLayovers($segments);
        [$confidence, $score] = $this->confidence($segments, $unparsed, $this->detect($raw));
        if (count($segments) > 0 && $confidence === 'low') {
            $confidence = 'medium';
            $score = max(50, $score);
        }
        $warnings = count($segments) > 0 ? ['Parsed with the flexible GDS fallback. Please review before sending.'] : [];
        if (count($segments) === 0) {
            $warnings[] = 'The parser could not safely create a passenger-ready itinerary.';
        }

        return new ParseResult(
            $this->sourceLabel($raw),
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
        $pattern = '/^\s*\d+\s*\.?\s*([A-Z0-9]{2})\s*([0-9]{1,4})([A-Z])?(?:\s+([A-Z]))?\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+(?:[1-7]\*?\s*)?([A-Z]{3})\s*([A-Z]{3})\s+([A-Z]{2}\d?)(?:\s+\d+)?\s+(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)\s+(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?(.*)$/i';
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
            $this->aircraftFromTail($m[12] ?? '') ?: $this->extractAircraft($line),
            $line
        );
    }

    private function aircraftFromTail(string $tail): ?string
    {
        $tail = strtoupper(trim($tail));
        if ($tail === '') {
            return null;
        }

        if (preg_match('/\b([A-Z0-9]{3})\b(?:\s+[A-Z])?$/', $tail, $m) === 1) {
            return $m[1];
        }

        return null;
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

    private function isContinuationLine(string $line): bool
    {
        return preg_match('/^\s*(?:OPERATED\s+BY|SEE\s+RTSVC|NOTE|CHANGE\s+OF\s+GAUGE|CODESHARE)/i', $line) === 1;
    }

    private function looksLikeAirSegment(string $line): bool
    {
        return preg_match('/^\s*\d+\s*\.?\s*[A-Z0-9]{2}\s*\d{1,4}[A-Z]?\b/i', $line) === 1;
    }

    private function sourceLabel(string $raw): string
    {
        if (preg_match('/\bRP\/[A-Z0-9]+\/|^\s*---\s*MSC\s*---/mi', $raw) === 1) {
            return 'Amadeus / universal GDS style';
        }
        if (preg_match('/\b(?:GALILEO|SMARTPOINT|WORLDSPAN|TRAVELPORT|RLOC|VENDOR LOCATOR)\b/i', $raw) === 1) {
            return 'Travelport Galileo / Smartpoint / Worldspan style';
        }
        if (preg_match('/\b(?:SABRE|1S|RECEIVED FROM|TKT\/TIME LIMIT)\b/i', $raw) === 1) {
            return 'Sabre / universal GDS style';
        }

        return 'Universal GDS air segment style';
    }
}
