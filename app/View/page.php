<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Parser\ParseResult;
use RoamingNepal\PnrConverter\Parser\Segment;
use RoamingNepal\PnrConverter\Support\Html;
use RoamingNepal\PnrConverter\Support\Metadata;

/** @var array $settings */
/** @var array $features */
/** @var string $rawInput */
/** @var ?ParseResult $result */
/** @var bool $rateLimited */

$basePath    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$projectRoot = dirname(__DIR__, 2);
$asset = static function (string $path) use ($basePath, $projectRoot): string {
    $rel = ltrim($path, '/');
    $url = ($basePath === '' ? '' : $basePath) . '/' . $rel;
    if (is_file($projectRoot . '/' . $rel)) {
        $url .= '?v=' . filemtime($projectRoot . '/' . $rel);
    }
    return $url;
};

$agencyName  = (string) ($settings['agency_name'] ?? 'Roaming Nepal');
$logoPath    = (string) ($settings['logo_path'] ?? 'assets/images/logo.svg');
$appVersion  = (string) ($settings['app_version'] ?? '4.0.0');
$rateLimited = (bool) ($rateLimited ?? false);

$distanceUnit = in_array((string) ($features['distance_unit'] ?? 'off'), ['off', 'km', 'miles'], true)
    ? (string) $features['distance_unit'] : 'off';
$resultFormat = in_array((string) ($features['result_format'] ?? 'detailed'),
    ['detailed', 'compact', 'table', 'whatsapp', 'two_lines', 'two_lines_reordered', 'three_lines', 'three_lines_reordered'], true)
    ? (string) $features['result_format'] : 'detailed';
$layoutFormat = match ($resultFormat) {
    'compact'  => 'two_lines',
    'whatsapp' => 'two_lines_reordered',
    'detailed' => 'three_lines',
    default    => $resultFormat,
};
$use24HourTime = array_key_exists('use_12_hour_clock', $features)
    ? !(bool) $features['use_12_hour_clock']
    : (bool) ($features['use_24_hour_time'] ?? true);

$show             = static fn (string $key): bool => (bool) ($features[$key] ?? false);
$showAgencyHeader = $show('show_agency_header');
$showAgencyFooter = $show('show_agency_footer');
$showDisclaimer   = $show('show_disclaimer');

$airlineDisplayName = static fn (Segment $seg): string => $seg->airlineName ?: 'Airline ' . $seg->airlineCode;

$airlineLogo = static function (string $code) use ($projectRoot): ?string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
    if ($code === '') return null;
    foreach (['svg', 'png', 'webp'] as $ext) {
        $p = 'assets/images/airlines/' . $code . '.' . $ext;
        if (is_file($projectRoot . '/' . $p)) return $p;
    }
    return null;
};

$maskTicket = static function (?string $t, bool $mask): ?string {
    if ($t === null || $t === '') return null;
    if (!$mask || strlen($t) < 7) return $t;
    return substr($t, 0, 3) . str_repeat('*', max(0, strlen($t) - 7)) . substr($t, -4);
};

$maskSeat = static function (?string $s, bool $mask): ?string {
    if ($s === null || $s === '') return null;
    return $mask ? preg_replace('/\d/', '*', $s) : $s;
};

$formatTime = static function (string $time, bool $use24): string {
    if ($use24 || preg_match('/^(\d{2}):(\d{2})$/', $time, $m) !== 1) return $time;
    $h = (int) $m[1];
    $p = $h >= 12 ? 'PM' : 'AM';
    $h = $h % 12 ?: 12;
    return sprintf('%d:%s %s', $h, $m[2], $p);
};

$gdsDateTime = static function (string $date, string $time): ?\DateTimeImmutable {
    if (preg_match('/^(\d{2})([A-Z]{3})(\d{4})?$/i', strtoupper($date), $d) !== 1) return null;
    if (preg_match('/^(\d{2}):?(\d{2})$/', $time, $t) !== 1) return null;
    $mo = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
    $mn = $mo[strtoupper($d[2])] ?? null;
    if ($mn === null) return null;
    $y = isset($d[3]) && $d[3] !== '' ? (int) $d[3] : (int) date('Y');
    return new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mn, (int) $d[1], (int) $t[1], (int) $t[2]));
};

$flightDuration = static function (Segment $seg) use ($gdsDateTime): ?string {
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime);
    $arr = $gdsDateTime($seg->arrivalDate ?: $seg->departureDate, $seg->arrivalTime);
    if ($dep === null || $arr === null) return null;
    if ($arr < $dep) $arr = $arr->modify('+1 day');
    $mins = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
    if ($mins <= 0 || $mins > 48 * 60) return null;
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
};

$arrivalOffset = static function (Segment $seg) use ($gdsDateTime): int {
    if (!$seg->arrivalDate) return 0;
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime);
    $arr = $gdsDateTime($seg->arrivalDate, $seg->arrivalTime);
    if ($dep === null || $arr === null) return 0;
    if ($arr <= $dep) return 0;
    return min(2, (int) $dep->diff($arr)->days);
};

$visaHubs = [
    'JFK'=>'US','LAX'=>'US','ORD'=>'US','MIA'=>'US','SFO'=>'US','DFW'=>'US',
    'EWR'=>'US','ATL'=>'US','IAH'=>'US','DEN'=>'US','BOS'=>'US','SEA'=>'US','IAD'=>'US','DTW'=>'US',
    'LHR'=>'UK','LGW'=>'UK','MAN'=>'UK','STN'=>'UK','LTN'=>'UK','BHX'=>'UK',
    'CDG'=>'Schengen','AMS'=>'Schengen','FRA'=>'Schengen','MUC'=>'Schengen','ZRH'=>'Schengen',
    'MAD'=>'Schengen','BCN'=>'Schengen','FCO'=>'Schengen','CPH'=>'Schengen','ARN'=>'Schengen',
    'BRU'=>'Schengen','VIE'=>'Schengen','HEL'=>'Schengen','OSL'=>'Schengen',
];
$transitVisa = static fn (string $ap) use ($visaHubs): ?string
    => isset($visaHubs[$ap]) ? 'Check ' . $visaHubs[$ap] . ' requirements' : null;

$layoverMeta = static function (?string $dur): array {
    if ($dur === null) return ['', ''];
    if (preg_match('/^(\d+)h\s*(\d+)m$/', $dur, $m) !== 1) return ['lo-normal', 'Layover'];
    $mins = ((int) $m[1]) * 60 + (int) $m[2];
    if ($mins < 90)       return ['lo-tight', 'Short layover'];
    if ($mins >= 10 * 60) return ['lo-overnight', 'Overnight layover'];
    if ($mins >= 6 * 60)  return ['lo-long', 'Long layover'];
    return ['lo-normal', 'Layover'];
};

$buildLegs = static function (array $segments): array {
    if (empty($segments)) return [];
    $legs = []; $cur = [$segments[0]];
    for ($i = 1; $i < count($segments); $i++) {
        if ($segments[$i - 1]->arrivalAirport === $segments[$i]->departureAirport) {
            $cur[] = $segments[$i];
        } else {
            $legs[] = $cur; $cur = [$segments[$i]];
        }
    }
    $legs[] = $cur;
    $isRound = count($legs) === 2
        && $legs[0][0]->departureAirport === $legs[1][count($legs[1]) - 1]->arrivalAirport;
    return array_map(static function (array $segs, int $idx) use ($isRound, $legs): array {
        $label = count($legs) === 1 ? null
            : ($isRound ? ($idx === 0 ? 'Outbound' : 'Return') : 'Leg ' . ($idx + 1));
        return ['label' => $label, 'segments' => $segs];
    }, $legs, array_keys($legs));
};

$legs = $result !== null && $result->isRenderable() ? $buildLegs($result->segments) : [];

$optionGroups = [
    'Branding' => [
        'show_agency_header' => 'Agency header',
        'show_agency_footer' => 'Footer/contact',
        'show_disclaimer'    => 'Disclaimer',
    ],
    'Flight Details' => [
        'show_airline_name'  => 'Airline name',
        'show_airline_logo'  => 'Airline logo',
        'show_transit_time'  => 'Layover time',
        'use_12_hour_clock'  => '12-hour clock',
        'show_operated_by'   => 'Operated by',
        'show_aircraft'      => 'Aircraft type',
    ],
    'Booking Data' => [
        'show_booking_class'     => 'Booking class',
        'show_cabin'             => 'Cabin',
        'show_booking_reference' => 'Booking ref',
        'show_ticket_numbers'    => 'Ticket numbers',
        'show_seat_numbers'      => 'Seat numbers',
    ],
];

// Build plain-text version
$buildTextVersion = static function () use ($result, $show, $showDisclaimer, $settings, $agencyName, $formatTime, $use24HourTime): string {
    if ($result === null || !$result->isRenderable()) return '';
    $lines   = [];
    $lines[] = $agencyName . ' — Flight Itinerary';
    $lines[] = str_repeat('-', 44);
    if ($show('show_booking_reference') && $result->recordLocator !== null) {
        $lines[] = 'Booking Ref: ' . $result->recordLocator;
    }
    $pax = count($result->passengers) > 0
        ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers))
        : 'Passenger not detected';
    $lines[] = 'Passenger: ' . $pax;
    $lines[] = '';
    foreach ($result->segments as $i => $seg) {
        $dep = $formatTime($seg->departureTime, $use24HourTime);
        $arr = $formatTime($seg->arrivalTime, $use24HourTime);
        $lines[] = 'Flight ' . ($i + 1) . ': ' . $seg->airlineCode . ' ' . $seg->flightNumber
            . ($seg->airlineName ? ' (' . $seg->airlineName . ')' : '');
        $lines[] = '  ' . $seg->departureAirport . '  ' . $seg->departureDate . ' ' . $dep
            . '  →  ' . $seg->arrivalAirport . '  ' . ($seg->arrivalDate ?: $seg->departureDate) . ' ' . $arr;
        if ($seg->layoverDuration) {
            $lines[] = '  Layover at ' . $seg->arrivalAirport . ': ' . $seg->layoverDuration;
        }
    }
    if ($showDisclaimer) {
        $lines[] = '';
        $lines[] = $settings['default_disclaimer'] ?? 'Please verify schedule with airline.';
    }
    return implode("\n", $lines);
};

// Build WhatsApp-formatted version
$buildWaVersion = static function () use ($result, $show, $showDisclaimer, $showAgencyFooter, $settings, $agencyName, $formatTime, $use24HourTime): string {
    if ($result === null || !$result->isRenderable()) return '';
    $lines   = [];
    $lines[] = '✈️ *FLIGHT ITINERARY*';
    if ($show('show_agency_header')) $lines[] = '📌 *' . $agencyName . '*';
    $lines[] = '';
    $pax = count($result->passengers) > 0
        ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers))
        : 'Passenger not detected';
    $lines[] = '👤 *Passenger:* ' . $pax;
    if ($show('show_booking_reference') && $result->recordLocator !== null) {
        $lines[] = '🔖 *Booking Ref:* ' . $result->recordLocator;
    }
    $lines[] = '';
    foreach ($result->segments as $i => $seg) {
        $dep = $formatTime($seg->departureTime, $use24HourTime);
        $arr = $formatTime($seg->arrivalTime, $use24HourTime);
        $lines[] = '══════════════════════';
        $lines[] = '✈️ *Flight ' . ($i + 1) . ':* ' . $seg->airlineCode . ' ' . $seg->flightNumber
            . ($seg->airlineName ? ' — ' . $seg->airlineName : '');
        $lines[] = '📍 *Departs:* ' . $seg->departureAirport . '  ' . $seg->departureDate . '  ' . $dep;
        $lines[] = '🏁 *Arrives:*  ' . $seg->arrivalAirport . '  ' . ($seg->arrivalDate ?: $seg->departureDate) . '  ' . $arr;
        if ($show('show_cabin') && $seg->cabin) $lines[] = '💺 *Cabin:* ' . $seg->cabin;
        if (($show('show_transit_time') || $show('show_layover')) && $seg->layoverDuration) {
            $lines[] = '⏱️ *Layover at ' . $seg->arrivalAirport . ':* ' . $seg->layoverDuration;
        }
    }
    $lines[] = '══════════════════════';
    if ($showDisclaimer) {
        $lines[] = '';
        $lines[] = '⚠️ ' . ($settings['default_disclaimer'] ?? 'Please verify schedule with airline before travel.');
    }
    if ($showAgencyFooter) {
        $lines[] = '';
        $footer = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
        $ho     = is_array($footer['head_office'] ?? null) ? $footer['head_office'] : [];
        if (!empty($ho['lines'])) {
            foreach ((array) $ho['lines'] as $ln) $lines[] = (string) $ln;
        }
    }
    return implode("\n", $lines);
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>PNR Converter — <?= Html::e($agencyName) ?></title>
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/styles.css')) ?>">
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/print.css')) ?>" media="print">
</head>
<body>
<div class="share-hint no-print" aria-hidden="true">Press Esc to exit share view</div>

<header class="topbar no-share">
    <div class="brand">
        <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?> logo" class="brand-logo">
        <div class="brand-meta">
            <h1>PNR Converter</h1>
            <p class="build-label">v<?= Html::e($appVersion) ?></p>
        </div>
    </div>
    <div class="top-actions">
        <span class="privacy-badge" title="Raw PNR text is never stored or logged">No PNR storage</span>
        <button class="button button-outline" type="button" id="shareModeBtn">Share view</button>
    </div>
</header>

<main class="app-shell">
    <section class="workspace no-share" aria-labelledby="input-heading">
        <form method="post" class="panel" autocomplete="off">

            <div class="panel-head">
                <div>
                    <span class="step-label">Step 1</span>
                    <h2 id="input-heading">Paste PNR</h2>
                    <p class="panel-sub">Amadeus, Galileo, Sabre, Worldspan — paste the segment display as-is.</p>
                </div>
                <?php if ($result !== null): ?>
                    <span class="badge badge-<?= Html::e($result->confidence) ?>">
                        <?= Html::e($result->sourceFormat) ?> &middot; <?= Html::e(strtoupper($result->confidence)) ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="input-shell">
                <label class="field-label" for="pnr_text">GDS itinerary text</label>
                <textarea id="pnr_text" name="pnr_text" rows="14" spellcheck="false"
                    placeholder="Example:&#10;1  QR 647  21MAY KTMDOH HK1 1910 2200&#10;2  QR 007  21MAY DOHLHR HK1 2355 0545+1"><?= Html::e($rawInput) ?></textarea>
                <div class="input-chips">
                    <span>In-memory only</span>
                    <span>Payment &amp; passport lines ignored</span>
                </div>
            </div>

            <div class="settings-panel">
                <div class="settings-header">
                    <div>
                        <span class="step-label">Step 2</span>
                        <h3>Display options</h3>
                    </div>
                    <div class="preset-row" aria-label="Presets">
                        <button type="button" class="preset-btn" data-preset="branded">
                            <strong>Roaming</strong><span>Full brand</span>
                        </button>
                        <button type="button" class="preset-btn" data-preset="neutral">
                            <strong>Neutral</strong><span>No branding</span>
                        </button>
                        <button type="button" class="preset-btn" data-preset="whatsapp">
                            <strong>WhatsApp</strong><span>Compact</span>
                        </button>
                    </div>
                </div>

                <?php foreach ($optionGroups as $groupTitle => $optionLabels): ?>
                    <div class="option-group">
                        <h4><?= Html::e($groupTitle) ?></h4>
                        <div class="option-list">
                            <?php foreach ($optionLabels as $key => $label): ?>
                                <label class="toggle">
                                    <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                                    <input type="checkbox" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                                    <span class="toggle-track" aria-hidden="true"></span>
                                    <span><?= Html::e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="option-group">
                    <h4>Distance</h4>
                    <div class="seg-control" role="radiogroup" aria-label="Distance unit">
                        <?php foreach (['off' => 'Off', 'km' => 'km', 'miles' => 'mi'] as $val => $lbl): ?>
                            <label>
                                <input type="radio" name="distance_unit" value="<?= Html::e($val) ?>"<?= Html::checked($distanceUnit === $val) ?>>
                                <span><?= Html::e($lbl) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="option-group option-wide">
                    <h4>Format</h4>
                    <div class="format-grid" role="radiogroup" aria-label="Output format">
                        <?php foreach (['detailed' => 'Detailed', 'compact' => 'Compact', 'table' => 'Table', 'whatsapp' => 'WhatsApp'] as $val => $lbl): ?>
                            <label class="radio-tile">
                                <input type="radio" name="result_format" value="<?= Html::e($val) ?>"<?= Html::checked($resultFormat === $val) ?>>
                                <span><?= Html::e($lbl) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="actions">
                <button class="button button-primary" type="submit">Convert</button>
                <button class="button" type="reset" id="resetBtn">Clear</button>
                <button class="button" type="button" id="waBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>WhatsApp copy</button>
                <button class="button" type="button" id="copyTextBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>Copy text</button>
                <button class="button" type="button" id="copyImageBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>Copy image</button>
                <button class="button" type="button" id="downloadPngBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>Download PNG</button>
                <button class="button" type="button" id="printBtn">Print</button>
            </div>
        </form>
    </section>

    <section class="preview-area" aria-labelledby="preview-heading">
        <div class="preview-head no-share">
            <div>
                <span class="step-label">Step 3</span>
                <h2 id="preview-heading">Passenger copy</h2>
            </div>
            <?php if ($result !== null): ?>
                <span class="source-tag">Detected: <?= Html::e($result->sourceFormat) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($result !== null): ?>
            <div class="parse-stats no-share">
                <div><span>Confidence</span><strong class="conf-<?= Html::e($result->confidence) ?>"><?= Html::e(ucfirst($result->confidence)) ?></strong></div>
                <div><span>Flights</span><strong><?= Html::e((string) count($result->segments)) ?></strong></div>
                <div><span>Passengers</span><strong><?= Html::e((string) count($result->passengers)) ?></strong></div>
                <div><span>Privacy</span><strong>Not stored</strong></div>
            </div>
        <?php endif; ?>

        <?php if ($rateLimited): ?>
            <div class="alert alert-danger no-share" role="alert">
                <strong>Rate limit reached.</strong> You have exceeded 40 conversions per hour. Please try again later.
            </div>
        <?php endif; ?>

        <?php if ($result !== null && count($result->warnings) > 0): ?>
            <div class="alert alert-warn no-share" role="alert">
                <?php foreach ($result->warnings as $w): ?>
                    <p><?= Html::e($w) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($result === null && !$rateLimited): ?>
            <div class="empty-state no-share">
                <div class="empty-icon" aria-hidden="true">✈</div>
                <h3>No itinerary yet</h3>
                <p>Paste your GDS itinerary in the left panel and press <strong>Convert</strong>.</p>
            </div>

        <?php elseif ($result !== null && !$result->isRenderable()): ?>
            <div class="alert alert-warn no-share">
                <strong>Manual review needed</strong>
                <p>Confidence is too low to produce a passenger-ready card.</p>
                <?php if (count($result->unparsedLines) > 0): ?>
                    <ul><?php foreach ($result->unparsedLines as $ul): ?><li><code><?= Html::e($ul) ?></code></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

        <?php elseif ($result !== null && $result->isRenderable()): ?>
            <?php
            $firstSeg = $result->segments[0] ?? null;
            $lastSeg  = $result->segments[count($result->segments) - 1] ?? null;
            ?>
            <article class="itinerary-card" id="itineraryCard">

                <?php if ($showAgencyHeader): ?>
                    <header class="card-header">
                        <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?> logo" class="card-logo">
                        <p class="card-header-label">Flight Itinerary</p>
                    </header>
                <?php endif; ?>

                <div class="pax-row">
                    <div class="pax-cell">
                        <p class="cell-label">Passenger<?= count($result->passengers) !== 1 ? 's' : '' ?></p>
                        <p class="cell-value">
                            <?= Html::e(count($result->passengers) > 0
                                ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers))
                                : 'Not detected') ?>
                        </p>
                    </div>
                    <?php if ($show('show_booking_reference') && $result->recordLocator !== null): ?>
                        <div class="pax-cell">
                            <p class="cell-label">Booking ref</p>
                            <p class="cell-value"><span class="ref-badge"><?= Html::e($result->recordLocator) ?></span></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($firstSeg instanceof Segment && $lastSeg instanceof Segment): ?>
                        <div class="pax-cell">
                            <p class="cell-label">Trip</p>
                            <p class="cell-value"><?= Html::e($firstSeg->departureAirport . ' — ' . $lastSeg->arrivalAirport) ?></p>
                            <p class="cell-sub"><?= Html::e(count($result->segments) . ' flight' . (count($result->segments) === 1 ? '' : 's')) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($layoutFormat === 'table'): ?>
                    <?php foreach ($legs as $leg):
                        /** @var array{label:?string,segments:Segment[]} $leg */
                        $legSegs = $leg['segments'];
                        $legLabel = $leg['label'];
                        $legFirst = $legSegs[0] ?? null;
                        $legLast  = $legSegs[count($legSegs) - 1] ?? null;
                    ?>
                    <div class="leg-block">
                        <?php if ($legLabel !== null && $legFirst instanceof Segment && $legLast instanceof Segment): ?>
                            <div class="leg-header">
                                <span class="leg-label"><?= Html::e($legLabel) ?></span>
                                <span class="leg-route"><?= Html::e($legFirst->departureAirport . ' → ' . $legLast->arrivalAirport) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="table-wrap">
                            <table class="seg-table">
                                <thead>
                                    <tr>
                                        <th>Flight</th>
                                        <th>From</th>
                                        <th>Departure</th>
                                        <th>To</th>
                                        <th>Arrival</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($legSegs as $si => $seg): ?>
                                    <?php
                                    /** @var Segment $seg */
                                    $logo   = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                                    $dist   = Metadata::distanceLabel($seg->departureAirport, $seg->arrivalAirport, $distanceUnit);
                                    $dur    = $flightDuration($seg);
                                    $offset = $arrivalOffset($seg);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="flight-brand">
                                                <?php if ($logo !== null): ?>
                                                    <img src="<?= Html::e($asset($logo)) ?>" alt="" class="al-logo">
                                                <?php else: ?>
                                                    <span class="al-code"><?= Html::e($seg->airlineCode) ?></span>
                                                <?php endif; ?>
                                                <span class="al-num"><?= Html::e($seg->airlineCode . ' ' . $seg->flightNumber) ?><?php if ($show('show_airline_name')): ?><em><?= Html::e($airlineDisplayName($seg)) ?></em><?php endif; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= Html::e($seg->departureAirport) ?></strong>
                                            <?php if ($seg->departureTerminal): ?>
                                                <span class="terminal-badge"><?= Html::e($seg->departureTerminal) ?></span>
                                            <?php endif; ?>
                                            <small><?= Html::e(Metadata::airportLabel($seg->departureAirport)) ?></small>
                                        </td>
                                        <td><?= Html::e($seg->departureDate) ?><br><strong><?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?></strong></td>
                                        <td>
                                            <strong><?= Html::e($seg->arrivalAirport) ?></strong>
                                            <small><?= Html::e(Metadata::airportLabel($seg->arrivalAirport)) ?></small>
                                        </td>
                                        <td>
                                            <?= Html::e($seg->arrivalDate ?: $seg->departureDate) ?><br>
                                            <strong><?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?></strong>
                                            <?php if ($offset > 0): ?>
                                                <span class="day-badge">+<?= $offset ?>d</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-details">
                                            <?php if ($dur): ?><span class="detail-chip"><?= Html::e($dur) ?></span><?php endif; ?>
                                            <?php if ($seg->status): ?><span class="detail-chip"><?= Html::e($seg->status) ?></span><?php endif; ?>
                                            <?php if ($show('show_booking_class') && $seg->bookingClass): ?><span class="detail-chip"><?= Html::e($seg->bookingClass) ?></span><?php endif; ?>
                                            <?php if ($show('show_cabin') && $seg->cabin): ?><span class="detail-chip"><?= Html::e($seg->cabin) ?></span><?php endif; ?>
                                            <?php if ($dist !== null): ?><span class="detail-chip"><?= Html::e($dist) ?></span><?php endif; ?>
                                            <?php if ($show('show_aircraft') && $seg->aircraft): ?><span class="detail-chip"><?= Html::e($seg->aircraft) ?></span><?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (($show('show_transit_time') || $show('show_layover')) && $seg->layoverDuration): ?>
                                        <?php
                                        [$loClass, $loLabel] = $layoverMeta($seg->layoverDuration);
                                        $visa = $transitVisa($seg->arrivalAirport);
                                        ?>
                                        <tr class="layover-row <?= Html::e($loClass) ?>">
                                            <td colspan="6">
                                                <div class="layover-row-inner">
                                                    <span class="lo-icon" aria-hidden="true">⏱</span>
                                                    <span><?= Html::e($loLabel) ?> at <?= Html::e($seg->arrivalAirport) ?>: <strong><?= Html::e($seg->layoverDuration) ?></strong></span>
                                                    <?php if ($visa): ?>
                                                        <span class="visa-flag"><?= Html::e($visa) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>

                <?php else: ?>
                    <div class="segments-wrap segments-<?= Html::e($layoutFormat) ?>">
                        <?php foreach ($legs as $leg):
                            /** @var array{label:?string,segments:Segment[]} $leg */
                            $legSegs  = $leg['segments'];
                            $legLabel = $leg['label'];
                            $legFirst = $legSegs[0] ?? null;
                            $legLast  = $legSegs[count($legSegs) - 1] ?? null;
                        ?>
                        <div class="leg-block">
                            <?php if ($legLabel !== null && $legFirst instanceof Segment && $legLast instanceof Segment): ?>
                                <div class="leg-header">
                                    <span class="leg-label"><?= Html::e($legLabel) ?></span>
                                    <span class="leg-route"><?= Html::e($legFirst->departureAirport . ' → ' . $legLast->arrivalAirport) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($legSegs as $seg): ?>
                                <?php
                                /** @var Segment $seg */
                                $logo    = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                                $dist    = Metadata::distanceLabel($seg->departureAirport, $seg->arrivalAirport, $distanceUnit);
                                $dur     = $flightDuration($seg);
                                $offset  = $arrivalOffset($seg);
                                $compact = in_array($layoutFormat, ['two_lines', 'two_lines_reordered'], true);
                                $reorder = in_array($layoutFormat, ['two_lines_reordered', 'three_lines_reordered'], true);
                                ?>
                                <div class="seg-card<?= $compact ? ' seg-compact' : '' ?>">
                                    <div class="seg-airline">
                                        <?php if ($logo !== null): ?>
                                            <img src="<?= Html::e($asset($logo)) ?>" alt="" class="al-logo">
                                        <?php else: ?>
                                            <span class="al-code"><?= Html::e($seg->airlineCode) ?></span>
                                        <?php endif; ?>
                                        <div class="al-info">
                                            <strong><?= Html::e($seg->airlineCode . ' ' . $seg->flightNumber) ?></strong>
                                            <?php if ($show('show_airline_name')): ?><span><?= Html::e($airlineDisplayName($seg)) ?></span><?php endif; ?>
                                        </div>
                                        <?php if ($dur !== null): ?>
                                            <span class="dur-badge"><?= Html::e($dur) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($compact): ?>
                                        <div class="compact-row">
                                            <?php if (!$reorder): ?>
                                                <p><strong><?= Html::e($seg->airlineCode . ' ' . $seg->flightNumber) ?></strong></p>
                                                <p>
                                                    <?= Html::e($seg->departureAirport) ?> <?= Html::e($seg->departureDate) ?> <?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?>
                                                    &rarr;
                                                    <?= Html::e($seg->arrivalAirport) ?> <?= Html::e($seg->arrivalDate ?: $seg->departureDate) ?> <?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?>
                                                    <?php if ($offset > 0): ?><span class="day-badge">+<?= $offset ?>d</span><?php endif; ?>
                                                </p>
                                            <?php else: ?>
                                                <p><strong><?= Html::e($seg->departureAirport) ?> &rarr; <?= Html::e($seg->arrivalAirport) ?></strong> &middot; <?= Html::e($seg->departureDate) ?> <?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?> &rarr; <?= Html::e($seg->arrivalDate ?: $seg->departureDate) ?> <?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?><?php if ($offset > 0): ?> <span class="day-badge">+<?= $offset ?>d</span><?php endif; ?></p>
                                                <p><?= Html::e($seg->airlineCode . ' ' . $seg->flightNumber) ?><?php if ($show('show_airline_name')): ?> &middot; <?= Html::e($airlineDisplayName($seg)) ?><?php endif; ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="route-row">
                                            <div class="port">
                                                <?php if (!$reorder): ?>
                                                    <p class="port-name"><?= Html::e(Metadata::airportLabel($seg->departureAirport)) ?></p>
                                                <?php else: ?>
                                                    <p class="port-name">Departure</p>
                                                <?php endif; ?>
                                                <p class="port-code"><?= Html::e($seg->departureAirport) ?></p>
                                                <p class="port-time"><?= Html::e($seg->departureDate) ?> &middot; <?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?></p>
                                                <?php if ($seg->departureTerminal): ?>
                                                    <span class="terminal-badge">T<?= Html::e(ltrim($seg->departureTerminal, 'T')) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="route-arrow" aria-hidden="true">
                                                <?php if ($dur): ?><span class="arrow-dur"><?= Html::e($dur) ?></span><?php endif; ?>
                                                <span class="arrow-line"></span>
                                                <span class="arrow-head"></span>
                                            </div>
                                            <div class="port port-arr">
                                                <?php if (!$reorder): ?>
                                                    <p class="port-name"><?= Html::e(Metadata::airportLabel($seg->arrivalAirport)) ?></p>
                                                <?php else: ?>
                                                    <p class="port-name">Arrival</p>
                                                <?php endif; ?>
                                                <p class="port-code">
                                                    <?= Html::e($seg->arrivalAirport) ?>
                                                    <?php if ($offset > 0): ?>
                                                        <span class="day-badge">+<?= $offset ?>d</span>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="port-time"><?= Html::e($seg->arrivalDate ?: $seg->departureDate) ?> &middot; <?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="seg-details">
                                        <?php if ($seg->status): ?><span><?= Html::e($seg->status) ?></span><?php endif; ?>
                                        <?php if ($show('show_booking_class') && $seg->bookingClass): ?><span><?= Html::e($seg->bookingClass) ?></span><?php endif; ?>
                                        <?php if ($show('show_cabin') && $seg->cabin): ?><span><?= Html::e($seg->cabin) ?></span><?php endif; ?>
                                        <?php if ($dist !== null): ?><span><?= Html::e($dist) ?></span><?php endif; ?>
                                        <?php if ($show('show_aircraft') && $seg->aircraft): ?><span><?= Html::e($seg->aircraft) ?></span><?php endif; ?>
                                        <?php if ($show('show_operated_by') && $seg->operatedBy): ?><span>Op: <?= Html::e($seg->operatedBy) ?></span><?php endif; ?>
                                        <?php if ($show('show_ticket_numbers') && $seg->ticketNumber): ?><span>TKT <?= Html::e($maskTicket($seg->ticketNumber, (bool) ($features['mask_ticket_numbers'] ?? true))) ?></span><?php endif; ?>
                                        <?php if ($show('show_seat_numbers') && $seg->seatNumber): ?><span>Seat <?= Html::e($maskSeat($seg->seatNumber, (bool) ($features['mask_seat_numbers'] ?? false))) ?></span><?php endif; ?>
                                    </div>

                                    <?php if (($show('show_transit_time') || $show('show_layover')) && $seg->layoverDuration): ?>
                                        <?php
                                        [$loClass, $loLabel] = $layoverMeta($seg->layoverDuration);
                                        $visa = $transitVisa($seg->arrivalAirport);
                                        ?>
                                        <div class="layover-strip <?= Html::e($loClass) ?>">
                                            <span class="lo-dot" aria-hidden="true"></span>
                                            <div class="lo-body">
                                                <span class="lo-title"><?= Html::e($loLabel) ?> at <?= Html::e($seg->arrivalAirport) ?></span>
                                                <span class="lo-dur"><?= Html::e($seg->layoverDuration) ?></span>
                                                <?php if ($visa): ?>
                                                    <span class="visa-flag"><?= Html::e($visa) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($showDisclaimer || $showAgencyFooter): ?>
                    <footer class="card-footer">
                        <?php if ($showDisclaimer): ?>
                            <p class="disclaimer"><?= Html::e((string) ($settings['default_disclaimer'] ?? 'Please verify schedule, terminal and check-in details with the airline before travel.')) ?></p>
                        <?php endif; ?>
                        <?php if ($showAgencyFooter): ?>
                            <?php
                            $footer    = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
                            $headOffice = is_array($footer['head_office'] ?? null) ? $footer['head_office'] : [];
                            ?>
                            <?php if (!empty($headOffice)): ?>
                                <div class="footer-office">
                                    <?php if (!empty($headOffice['title'])): ?><strong><?= Html::e((string) $headOffice['title']) ?></strong><?php endif; ?>
                                    <?php foreach ((array) ($headOffice['lines'] ?? []) as $ln): ?><span><?= Html::e((string) $ln) ?></span><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php $branches = is_array($footer['branches'] ?? null) ? $footer['branches'] : []; ?>
                            <?php if (count($branches) > 0): ?>
                                <div class="footer-branches">
                                    <?php if (!empty($footer['branches_title'])): ?><strong class="branches-title"><?= Html::e((string) $footer['branches_title']) ?></strong><?php endif; ?>
                                    <div class="branch-grid">
                                        <?php foreach ($branches as $branch): ?>
                                            <?php if (!is_array($branch)) continue; ?>
                                            <div class="footer-office">
                                                <?php if (!empty($branch['title'])): ?><strong><?= Html::e((string) $branch['title']) ?></strong><?php endif; ?>
                                                <?php foreach ((array) ($branch['lines'] ?? []) as $ln): ?><span><?= Html::e((string) $ln) ?></span><?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($settings['footer_note'])): ?><p><?= Html::e((string) $settings['footer_note']) ?></p><?php endif; ?>
                        <?php endif; ?>
                    </footer>
                <?php endif; ?>
            </article>

            <textarea class="sr-only" id="textVersion" readonly><?= Html::e($buildTextVersion()) ?></textarea>
            <textarea class="sr-only" id="waVersion" readonly><?= Html::e($buildWaVersion()) ?></textarea>
        <?php endif; ?>
    </section>
</main>

<script src="<?= Html::e($asset('assets/js/app.js')) ?>"></script>
</body>
</html>
