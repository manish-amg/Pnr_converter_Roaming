<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

final class ParseResult
{
    /** @param Passenger[] $passengers @param Segment[] $segments @param string[] $warnings @param string[] $unparsedLines */
    public function __construct(
        public readonly string $sourceFormat,
        public readonly string $confidence,
        public readonly int $score,
        public readonly array $passengers = [],
        public readonly array $segments = [],
        public readonly ?string $recordLocator = null,
        public readonly array $warnings = [],
        public readonly array $unparsedLines = [],
    ) {
    }

    public function isRenderable(): bool
    {
        return count($this->segments) > 0;
    }
}
