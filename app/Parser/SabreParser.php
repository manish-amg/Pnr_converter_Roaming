<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

use RoamingNepal\PnrConverter\Support\Metadata;

final class SabreParser extends BaseParser
{
    public function detect(string $raw): int
    {
        $score = 0;
        if (preg_match('/\b(?:SABRE|1S)\b/i', $raw) === 1) {
            $score += 35;
        }
        if (preg_match('/^\s*\d+\s+[A-Z0-9]{2}\s*\d{1,4}[A-Z]\s+\d{1,2}[A-Z]{3}\s+[A-Z]{3}[A-Z]{3}/mi', $raw) === 1) {
            $score += 40;
        }
        if (preg_match('/^\s*\d+\.\d+[A-Z]+\/[A-Z]+/mi', $raw) === 1) {
            $score += 20;
        }
        if (preg_match('/\b(?:TKT\/TIME LIMIT|PHONES|RECEIVED FROM)\b/i', $raw) === 1) {
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
        $warnings = $confidence === 'medium' ? ['Some Sabre lines were not parsed. Confirm details before sending.'] : [];
        if ($confidence === 'low') {
            $warnings[] = 'The parser could not safely create a passenger-ready itinerary.';
        }

        return new ParseResult(
            'Sabre style',
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
        $pattern = '/^\s*\d+\s+([A-Z0-9]{2})\s*([0-9]{1,4})([A-Z])\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?)\s+([A-Z]{3})\s*([A-Z]{3})\s+([A-Z]{2}\d?)(?:\s+\d+)?\s+(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)\s+(#?\d{3,4}(?:[APMapm]{1,2})?(?:\+\d+)?)(?:\s+(\d{1,2}[A-Z]{3}(?:\d{2,4})?))?/i';
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
            $line
        );
    }

    private function looksImportantButUnhandled(string $line): bool
    {
        return preg_match('/^\s*\d+\s+[A-Z0-9]{2}|\b(?:SEGMENT|HK|HL|OPERATED)\b/i', $line) === 1;
    }
}
