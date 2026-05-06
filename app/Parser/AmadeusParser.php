<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

use RoamingNepal\PnrConverter\Support\Metadata;

final class AmadeusParser extends BaseParser
{
    public function detect(string $raw): int
    {
        $score = 0;
        if (preg_match('/\bRP\/[A-Z0-9]+\/(?:[A-Z0-9]+)?\/?/i', $raw) === 1) {
            $score += 35;
        }
        if (preg_match('/^\s*---\s*[A-Z0-9]+\s*---/mi', $raw) === 1) {
            $score += 15;
        }
        if (preg_match('/\bNM\d+[A-Z]+\/[A-Z]+/i', $raw) === 1) {
            $score += 25;
        }
        if (preg_match('/^\s*\d+\s+[A-Z0-9]{2}\s*\d{1,4}(?:\s+[A-Z])?\s+\d{1,2}[A-Z]{3}\s+(?:\d\*)?[A-Z]{6}\s+[A-Z]{2}\d?/mi', $raw) === 1) {
            $score += 35;
        }
        if (preg_match('/\bAPIS|FA PAX|TK OK|SSR DOCS\b/i', $raw) === 1) {
            $score += 10;
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

        foreach ($lines as $index => $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }

            $segment = $this->parseSegmentLine($line, $ticket, $seat);
            if ($segment !== null) {
                $segment = $this->withContinuationAircraft($segment, $lines[$index + 1] ?? null);
                $segments[] = $segment;
                continue;
            }

            if ($this->looksImportantButUnhandled($line)) {
                $unparsed[] = $line;
            }
        }

        $segments = $this->withLayovers($segments);
        [$confidence, $score] = $this->confidence($segments, $unparsed, $this->detect($raw));
        $warnings = $confidence === 'medium' ? ['Some lines were not understood. Review the itinerary before sending.'] : [];
        if ($confidence === 'low') {
            $warnings[] = 'The parser could not safely create a passenger-ready itinerary.';
        }

        return new ParseResult(
            'Amadeus style',
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
        // Pattern handles optional Amadeus terminal prefix: e.g. 7*MELKUL (Terminal 7 at MEL)
        // Booking class ([A-Z]) after flight number is optional to support formats like:
        //   1  TG 585  09MAY 6*KTIBKK DK1  2115 2235  09MAY  E  0 320 M
        $pattern = '/^\s*\d+\s+([A-Z0-9]{2})\s*([0-9]{1,4})(?:\s+([A-Z]))?\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+(?:\d\*\s*)?([A-Z]{3})\s*(?:\d\*\s*)?([A-Z]{3})\s+([A-Z]{2}\d?)(?:\s+\d+)?\s+([#0-9APMapm:]{3,8})\s+([#0-9APMapm:]{3,8})(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?/i';
        if (preg_match($pattern, $line, $m) !== 1) {
            return null;
        }

        $airlineCode = strtoupper($m[1]);
        return new Segment(
            $airlineCode,
            $m[2],
            Metadata::airlineName($airlineCode),
            strtoupper($m[7]),
            strtoupper($m[5]),
            strtoupper($m[6]),
            $this->normalizeDate($m[4]),
            $this->normalizeTime($m[8]),
            isset($m[10]) && $m[10] !== '' ? $this->normalizeDate($m[10]) : null,
            $this->normalizeTime($m[9]),
            strtoupper($m[3]),
            $this->cabinFromClass($m[3]),
            null,
            $this->extractOperatedBy($line),
            $ticket,
            $seat,
            $this->extractAircraft($line),
            $line,
            $this->extractAmadeusTerminal($line),
        );
    }

    private function extractAmadeusTerminal(string $line): ?string
    {
        if (preg_match('/\b(\d)\*[A-Z]{3}/i', $line, $m) === 1) {
            return 'T' . $m[1];
        }
        return null;
    }

    private function withContinuationAircraft(Segment $segment, ?string $nextLine): Segment
    {
        if ($nextLine === null || $segment->aircraft !== null) {
            return $segment;
        }

        if (preg_match('/^\s*([A-Z0-9]{3,4})\s+[A-Z]\s*$/i', $nextLine, $m) !== 1) {
            return $segment;
        }

        return new Segment(
            $segment->airlineCode,
            $segment->flightNumber,
            $segment->airlineName,
            $segment->status,
            $segment->departureAirport,
            $segment->arrivalAirport,
            $segment->departureDate,
            $segment->departureTime,
            $segment->arrivalDate,
            $segment->arrivalTime,
            $segment->bookingClass,
            $segment->cabin,
            $segment->layoverDuration,
            $segment->operatedBy,
            $segment->ticketNumber,
            $segment->seatNumber,
            strtoupper($m[1]),
            $segment->rawLine,
            $segment->departureTerminal,
        );
    }

    private function looksImportantButUnhandled(string $line): bool
    {
        // Skip known Amadeus continuation / status lines
        if (preg_match('/^\s*(?:SEE\s|SI\.|OS\s|FA\s|TK\s|---\s*[A-Z]|RP\/)/i', $line) === 1) {
            return false;
        }
        return preg_match('/^\s*\d+\s+[A-Z0-9]{2}|\b(?:FLT|FLIGHT|ARR|DEP|HK|HL|HX|UC)\b/i', $line) === 1;
    }
}
