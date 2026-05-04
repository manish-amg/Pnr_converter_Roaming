<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Parser\PnrParserFactory;

require_once __DIR__ . '/../app/bootstrap.php';

$cases = [
    'amadeus-oneway.txt' => ['format' => 'Amadeus style', 'segments' => 1, 'locator' => 'AB12CD'],
    'amadeus-return.txt' => ['format' => 'Amadeus style', 'segments' => 4, 'locator' => 'CD34EF'],
    'amadeus-mh-mel-kul-ktm.txt' => ['format' => 'Amadeus style', 'segments' => 2, 'locator' => null],
    'travelport-oneway.txt' => ['format' => 'Travelport Galileo / Smartpoint / Worldspan style', 'segments' => 2, 'locator' => 'GH56IJ'],
    'travelport-multicity.txt' => ['format' => 'Travelport Galileo / Smartpoint / Worldspan style', 'segments' => 4, 'locator' => 'IJ78KL'],
    'travelport-roaming-qr.txt' => ['format' => 'Travelport Galileo / Smartpoint / Worldspan style', 'segments' => 4, 'locator' => null],
    'travelport-cx-ktm-hkg-syd.txt' => ['format' => 'Travelport Galileo / Smartpoint / Worldspan style', 'segments' => 2, 'locator' => null],
    'sabre-oneway.txt' => ['format' => 'Sabre style', 'segments' => 1, 'locator' => 'ABC123'],
    'sabre-overnight.txt' => ['format' => 'Sabre style', 'segments' => 2, 'locator' => 'KL90MN'],
];

$failed = 0;
foreach ($cases as $file => $expected) {
    $raw = file_get_contents(__DIR__ . '/fixtures/' . $file);
    if ($raw === false) {
        throw new RuntimeException('Could not read fixture ' . $file);
    }

    $result = PnrParserFactory::parse($raw);
    $checks = [
        'format' => $result->sourceFormat === $expected['format'],
        'segments' => count($result->segments) === $expected['segments'],
        'locator' => $expected['locator'] === null || $result->recordLocator === $expected['locator'],
        'confidence' => in_array($result->confidence, ['high', 'medium'], true),
    ];
    $passed = !in_array(false, $checks, true);
    if (!$passed) {
        $failed++;
    }

    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $file . PHP_EOL;
    echo '  Format: ' . $result->sourceFormat . PHP_EOL;
    echo '  Confidence: ' . $result->confidence . ' (' . $result->score . ')' . PHP_EOL;
    echo '  Record locator: ' . ($result->recordLocator ?? 'not detected') . PHP_EOL;
    echo '  Passengers: ' . (count($result->passengers) > 0 ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers)) : 'none') . PHP_EOL;
    echo '  Segments: ' . count($result->segments) . PHP_EOL;
    foreach ($result->segments as $segment) {
        echo '    - ' . $segment->airlineCode . $segment->flightNumber . ' ' . $segment->departureAirport . ' ' . $segment->departureDate . ' ' . $segment->departureTime . ' -> ' . $segment->arrivalAirport . ' ' . ($segment->arrivalDate ?: $segment->departureDate) . ' ' . $segment->arrivalTime . PHP_EOL;
    }
    if (count($result->unparsedLines) > 0) {
        echo '  Unparsed lines: ' . count($result->unparsedLines) . PHP_EOL;
    }
    echo PHP_EOL;
}

echo $failed === 0 ? 'All tests passed.' . PHP_EOL : $failed . ' test(s) failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
