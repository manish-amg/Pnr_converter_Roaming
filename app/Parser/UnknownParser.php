<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

final class UnknownParser extends BaseParser
{
    public function detect(string $raw): int
    {
        return 0;
    }

    public function parse(string $raw): ParseResult
    {
        $lines = array_values(array_filter($this->lines($raw), fn (string $line): bool => !$this->isSensitiveLine($line)));
        return new ParseResult(
            'Unknown',
            'low',
            0,
            [],
            [],
            null,
            ['The source format could not be detected confidently. Please review the pasted text manually.'],
            $lines
        );
    }
}
