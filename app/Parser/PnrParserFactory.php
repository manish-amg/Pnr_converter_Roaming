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
            $genericResult = (new GenericAirSegmentParser())->parse($raw);
            return count($genericResult->segments) > 0 ? $genericResult : (new UnknownParser())->parse($raw);
        }

        $result = $bestParser->parse($raw);
        if ($result->isRenderable()) {
            return $result;
        }

        $genericResult = (new GenericAirSegmentParser())->parse($raw);
        if (count($genericResult->segments) > count($result->segments) || $genericResult->isRenderable()) {
            return $genericResult;
        }

        return $result;
    }
}
