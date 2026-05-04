<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

interface ParserInterface
{
    public function detect(string $raw): int;

    public function parse(string $raw): ParseResult;
}
