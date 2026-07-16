<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

abstract class BaseParser implements ParserInterface
{
    private const SENSITIVE_PATTERNS = [
        '/\b(FOP|FP|FOID|DOCS|DOCA|DOCO|CTCE|CTCM|CTCR)\b/i',
        '/\b(OSI|SSR)\b.*\b(PHONE|EMAIL|MAIL|MOBILE|PASSPORT|DOB|DOC|FOID|CTC)\b/i',
        '/\b(AP|APE|APM|AB\/|AM\/)\b/i',
        '/\b(PRIVATE|REMARK|RMK)\b/i',
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        '/(?:\+?\d[\s-]?){9,}/',
    ];

    protected function lines(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = $this->stripInvisibleCharacters($raw);
        $lines = array_map(static fn (string $line): string => trim($line), explode("\n", $raw));
        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * Copy-pasting from Word, Outlook, or a numbered-list editor often embeds
     * invisible Unicode characters (word joiners, zero-width spaces, BOM)
     * around list numbers. These aren't ASCII whitespace, so \s in our
     * parsing regexes silently fails to match past them. Strip them and
     * normalize non-breaking spaces to regular spaces before any parsing.
     */
    private function stripInvisibleCharacters(string $raw): string
    {
        $raw = str_replace("\u{00A0}", ' ', $raw);
        return preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $raw) ?? $raw;
    }

    protected function isSensitiveLine(string $line): bool
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeTime(string $time): string
    {
        $time = strtoupper(trim($time));
        $time = preg_replace('/\+\d+$/', '', $time) ?? $time;
        $time = ltrim($time, '#');
        $time = preg_replace('/[^0-9APM]/', '', $time) ?? $time;

        if (preg_match('/^(\d{1,2})(\d{2})(A|P|AM|PM)$/', $time, $m) === 1) {
            $hour = (int) $m[1];
            $minute = $m[2];
            $period = str_starts_with($m[3], 'P') ? 'PM' : 'AM';
            if ($period === 'PM' && $hour < 12) {
                $hour += 12;
            }
            if ($period === 'AM' && $hour === 12) {
                $hour = 0;
            }
            return sprintf('%02d:%s', $hour, $minute);
        }

        if (preg_match('/^(\d{1,2})(\d{2})$/', $time, $m) === 1) {
            return sprintf('%02d:%s', (int) $m[1], $m[2]);
        }

        return $time;
    }

    protected function normalizeDate(string $date): string
    {
        $date = strtoupper(trim($date));
        if (preg_match('/^(\d{1,2})([A-Z]{3})(\d{2,4})?$/', $date, $m) !== 1) {
            return $date;
        }

        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $year = $m[3] ?? '';
        if (strlen($year) === 2) {
            $year = '20' . $year;
        }
        if ($year === '') {
            $year = (string) $this->inferYear((int) $day, strtoupper($m[2]));
        }

        return $day . strtoupper($m[2]) . $year;
    }

    /**
     * GDS date fields almost never carry an explicit year — the airline system
     * assumes "the next occurrence of this day/month". Defaulting blindly to
     * the current calendar year breaks any date that's already passed (e.g.
     * booking in Jul 2026 for 15MAR with no year must mean 15MAR2027, not
     * 15MAR2026) — the weekday shown then belongs to the wrong year entirely.
     */
    private function inferYear(int $day, string $monthAbbr): int
    {
        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
            'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
        $month = $months[$monthAbbr] ?? null;
        $currentYear = (int) date('Y');
        if ($month === null) {
            return $currentYear;
        }

        try {
            $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $currentYear, $month, $day));
        } catch (\Exception) {
            return $currentYear;
        }

        // More than a month in the past this year — assume it means next year.
        $today = new \DateTimeImmutable('today');
        if ($candidate < $today->modify('-30 days')) {
            return $currentYear + 1;
        }

        return $currentYear;
    }

    protected function cabinFromClass(?string $class): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }

        $letter = strtoupper($class[0]);
        return match (true) {
            in_array($letter, ['F', 'A', 'P'], true) => 'First',
            in_array($letter, ['J', 'C', 'D', 'I', 'Z'], true) => 'Business',
            $letter === 'W' => 'Premium Economy',
            default => 'Economy',
        };
    }

    protected function extractRecordLocator(array $lines): ?string
    {
        foreach ($lines as $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }
            if (preg_match('/\b(?:RLOC|RECLOC|RECORD LOCATOR|BOOKING REF(?:ERENCE)?|PNR|LOCATOR)\s*[:\/-]?\s*([A-Z0-9]{5,8})\b/i', $line, $m) === 1) {
                return strtoupper($m[1]);
            }
            if (preg_match('/\b(?:RP\/[A-Z0-9]+\/[A-Z0-9]+\/)?([A-Z0-9]{6})\s*$/', $line, $m) === 1 && preg_match('/[A-Z]/', $m[1]) === 1 && preg_match('/\d/', $m[1]) === 1) {
                return strtoupper($m[1]);
            }
        }

        return null;
    }

    /** @return Passenger[] */
    protected function extractPassengers(array $lines): array
    {
        $passengers = [];
        foreach ($lines as $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }

            if (preg_match('/^NM\d+\s*(.+)$/i', $line, $m) === 1) {
                $parts = array_values(array_filter(explode('/', strtoupper($m[1]))));
                for ($i = 0; $i + 1 < count($parts); $i += 2) {
                    $passengers[] = new Passenger($this->formatPassengerName($parts[$i] . '/' . $parts[$i + 1]));
                }
                continue;
            }

            // Skip GDS remark/OSI lines: "299 OD/..." — 3-digit element numbers are not passenger entries
            if (preg_match('/^\s*\d{3,}\s+[A-Z0-9]{2}[\/\s]/i', $line) === 1) {
                continue;
            }
            // Passenger prefix is 1–2 digits max; surname must be ≥3 letters (2-letter codes are airlines)
            if (preg_match_all('/(?:^|(?<=\s))(?:NM\d+|\d{1,2}\.\d*|\d{1,2})\s*([A-Z][A-Z-]{2,}(?: [A-Z][A-Z-]*)*\/[A-Z][A-Z-]*(?: [A-Z][A-Z-]*)*(?:\s+(?:MR|MRS|MS|MSTR|MISS|CHD|INF))?)/i', $line, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $passengers[] = new Passenger($this->formatPassengerName($name));
                }
                continue;
            }

            if (preg_match('/^N(?:AME)?\s*[:\-]\s*(.+)$/i', $line, $m) === 1) {
                $names = preg_split('/\s*[,;]\s*/', $m[1]) ?: [];
                foreach ($names as $name) {
                    if (str_contains($name, '/')) {
                        $passengers[] = new Passenger($this->formatPassengerName($name));
                    }
                }
            }
        }

        $unique = [];
        foreach ($passengers as $passenger) {
            $unique[$passenger->name] = $passenger;
        }

        return array_values($unique);
    }

    protected function formatPassengerName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', strtoupper($name)) ?? $name);
        if (!str_contains($name, '/')) {
            return $name;
        }

        [$last, $rest] = array_pad(explode('/', $name, 2), 2, '');
        $rest = trim($rest);
        $last = trim($last);

        // Move salutation to front: KARKI/RUSHMIT MR → MR RUSHMIT KARKI
        $salutation = '';
        if (preg_match('/^(.*?)\s+(MR|MRS|MS|MISS|MSTR|MASTER|DR|PROF|CHD|INF)\.?\s*$/i', $rest, $m)) {
            $salutation = strtoupper($m[2]) . ' ';
            $rest = trim($m[1]);
        }

        return trim($salutation . $rest . ' ' . $last);
    }

    protected function extractOperatedBy(string $line): ?string
    {
        if (preg_match('/(?:OPERATED BY|OP BY|O\/B|OPER)\s*[:\-]?\s*([A-Z0-9 .-]{2,40})/i', $line, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    protected function extractTicketNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }
            if (preg_match('/\b(?:TKT|TKNE|ETKT|TICKET)\s*[:\/-]?\s*(\d{3}[- ]?\d{10})\b/i', $line, $m) === 1) {
                return preg_replace('/\D/', '', $m[1]) ?: null;
            }
        }

        return null;
    }

    protected function extractSeatNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            if ($this->isSensitiveLine($line)) {
                continue;
            }
            if (preg_match('/\b(?:SEAT|ST)\s*[:\/-]?\s*([0-9]{1,2}[A-Z])\b/i', $line, $m) === 1) {
                return strtoupper($m[1]);
            }
        }

        return null;
    }

    /** @param Segment[] $segments */
    protected function withLayovers(array $segments): array
    {
        if (count($segments) < 2) {
            return $segments;
        }

        $withLayovers = [];
        foreach ($segments as $index => $segment) {
            $layover = null;
            $next = $segments[$index + 1] ?? null;
            if ($next !== null && $segment->arrivalAirport === $next->departureAirport) {
                $layover = $this->calculateLayover($segment, $next);
            }

            $withLayovers[] = new Segment(
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
                $layover,
                $segment->operatedBy,
                $segment->ticketNumber,
                $segment->seatNumber,
                $segment->aircraft,
                $segment->rawLine,
                $segment->departureTerminal,
            );
        }

        return $withLayovers;
    }

    protected function extractAircraft(string $line): ?string
    {
        if (preg_match('/\b(?:AIRCRAFT|AIRCRAFT TYPE|EQUIPMENT|EQUIP|EQP|ACFT)\s*[:\/-]?\s*([A-Z0-9]{2,4})\b/i', $line, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function calculateLayover(Segment $current, Segment $next): ?string
    {
        $arrivalDate = $current->arrivalDate ?: $current->departureDate;
        $arrival = $this->dateTimeFromGds($arrivalDate, $current->arrivalTime);
        $departure = $this->dateTimeFromGds($next->departureDate, $next->departureTime);
        if ($arrival === null || $departure === null) {
            return null;
        }
        if ($departure < $arrival) {
            $departure = $departure->modify('+1 day');
        }

        $minutes = (int) round(($departure->getTimestamp() - $arrival->getTimestamp()) / 60);
        if ($minutes < 0 || $minutes > 48 * 60) {
            return null;
        }

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    private function dateTimeFromGds(string $date, string $time): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{2})([A-Z]{3})(\d{4})?$/', strtoupper($date), $dateParts) !== 1) {
            return null;
        }
        if (preg_match('/^(\d{2}):?(\d{2})$/', $time, $timeParts) !== 1) {
            return null;
        }

        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
        $month = $months[$dateParts[2]] ?? null;
        if ($month === null) {
            return null;
        }
        $year = isset($dateParts[3]) && $dateParts[3] !== '' ? (int) $dateParts[3] : (int) date('Y');

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, (int) $dateParts[1], (int) $timeParts[1], (int) $timeParts[2]));
    }

    /** @param Segment[] $segments */
    protected function confidence(array $segments, array $unparsedLines, int $detectionScore): array
    {
        if (count($segments) === 0) {
            return ['low', min(35, $detectionScore)];
        }

        $score = min(100, $detectionScore + (count($segments) * 18));
        if (count($unparsedLines) > 0) {
            $score -= min(30, count($unparsedLines) * 4);
        }
        $score = max(0, $score);

        if ($score >= 75) {
            return ['high', $score];
        }
        if ($score >= 50) {
            return ['medium', $score];
        }

        return ['low', $score];
    }
}
