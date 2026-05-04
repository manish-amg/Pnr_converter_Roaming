<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

final class PnrParserFactory
{
    /** @return ParserInterface[] */
    public static function parsers(): array
    {
        return [
            new AmadeusParser(),
            new TravelportParser(),
            new SabreParser(),
        ];
    }

    public static function parse(string $raw): ParseResult
    {
        $genericResult = (new GenericAirSegmentParser())->parse($raw);
        if ($genericResult->isRenderable()) {
            return $genericResult;
        }

        $bestParser = null;
        $bestScore = -1;

        foreach (self::parsers() as $parser) {
            $score = $parser->detect($raw);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestParser = $parser;
            }
        }

        if ($bestParser === null || $bestScore < 20) {
            return count($genericResult->segments) > 0 ? $genericResult : (new UnknownParser())->parse($raw);
        }

        $result = $bestParser->parse($raw);
        if (
            count($genericResult->segments) > 0
            && (
                !$result->isRenderable()
                || $genericResult->isRenderable()
                || count($genericResult->segments) >= count($result->segments)
            )
        ) {
            return $genericResult;
        }

        return $result;
    }
}
