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
    if (is_file($projectRoot . '/' . $rel)) $url .= '?v=' . filemtime($projectRoot . '/' . $rel);
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
$use24HourTime = array_key_exists('use_12_hour_clock', $features)
    ? !(bool) $features['use_12_hour_clock']
    : (bool) ($features['use_24_hour_time'] ?? true);

$show             = static fn (string $key): bool => (bool) ($features[$key] ?? false);
$showAgencyHeader = $show('show_agency_header');
$showAgencyFooter = $show('show_agency_footer');
$showDisclaimer   = $show('show_disclaimer');

$airlineLogo = static function (string $code) use ($projectRoot, $asset): array {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
    if ($code === '') return ['src' => '', 'local' => false];
    foreach (['svg', 'png', 'webp'] as $ext) {
        $p = 'assets/images/airlines/' . $code . '.' . $ext;
        if (is_file($projectRoot . '/' . $p)) return ['src' => $asset($p), 'local' => true];
    }
    // CDN fallback — Google Flights logo CDN (reliable, CORS-enabled)
    return ['src' => 'https://www.gstatic.com/flights/airline_logos/70px/' . $code . '.png', 'local' => false];
};

$logoImg = static function (array $logo, string $cls, string $alt): string {
    if ($logo['src'] === '') return '';
    $cors = $logo['local'] ? '' : ' crossorigin="anonymous"';
    $err  = ' onerror="this.style.visibility=\'hidden\'"';
    return '<img src="' . htmlspecialchars($logo['src'], ENT_QUOTES) . '"'
        . $cors . $err . ' class="' . $cls . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" loading="lazy">';
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
    $h = (int) $m[1]; $p = $h >= 12 ? 'PM' : 'AM'; $h = $h % 12 ?: 12;
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

// "Sat 30 May" display
$datePretty = static function (string $date) use ($gdsDateTime): string {
    $dt = $gdsDateTime($date, '00:00');
    return $dt !== null ? $dt->format('D j M') : $date;
};

// Flight duration "3h 50m"
$flightDuration = static function (Segment $seg) use ($gdsDateTime): ?string {
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime);
    $arr = $gdsDateTime($seg->arrivalDate ?: $seg->departureDate, $seg->arrivalTime);
    if ($dep === null || $arr === null) return null;
    if ($arr < $dep) $arr = $arr->modify('+1 day');
    $mins = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
    if ($mins <= 0 || $mins > 48 * 60) return null;
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
};

// Leg total duration (first dep → last arr including layovers)
$legDuration = static function (array $segs) use ($gdsDateTime): ?string {
    if (empty($segs)) return null;
    $first = $segs[0]; $last = $segs[count($segs) - 1];
    $dep = $gdsDateTime($first->departureDate, $first->departureTime);
    $arr = $gdsDateTime($last->arrivalDate ?: $last->departureDate, $last->arrivalTime);
    if ($dep === null || $arr === null) return null;
    if ($arr < $dep) $arr = $arr->modify('+1 day');
    $mins = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
    if ($mins <= 0 || $mins > 96 * 60) return null;
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
};

// Arrival day offset +1/+2
$arrivalOffset = static function (Segment $seg) use ($gdsDateTime): int {
    if (!$seg->arrivalDate) return 0;
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime);
    $arr = $gdsDateTime($seg->arrivalDate, $seg->arrivalTime);
    if ($dep === null || $arr === null || $arr <= $dep) return 0;
    return min(2, (int) $dep->diff($arr)->days);
};

// Full airport display: "Tribhuvan International Airport, Kathmandu (KTM)"
$portDisplay = static function (string $code): string {
    $meta = Metadata::airport($code);
    if ($meta === null) return strtoupper($code);
    $parts = array_filter([$meta['name'] ?? null, $meta['city'] ?? null]);
    return (!empty($parts) ? implode(', ', $parts) . ' ' : '') . '(' . strtoupper($code) . ')';
};

// City name only
$portCity = static function (string $code): string {
    $meta = Metadata::airport($code);
    return $meta['city'] ?? strtoupper($code);
};

// Transit visa hubs
$visaHubs = [
    'JFK'=>'US','LAX'=>'US','ORD'=>'US','MIA'=>'US','SFO'=>'US','DFW'=>'US',
    'EWR'=>'US','ATL'=>'US','IAH'=>'US','DEN'=>'US','BOS'=>'US','SEA'=>'US','IAD'=>'US','DTW'=>'US',
    'LHR'=>'UK','LGW'=>'UK','MAN'=>'UK','STN'=>'UK','LTN'=>'UK','BHX'=>'UK',
    'CDG'=>'Schengen','AMS'=>'Schengen','FRA'=>'Schengen','MUC'=>'Schengen','ZRH'=>'Schengen',
    'MAD'=>'Schengen','BCN'=>'Schengen','FCO'=>'Schengen','CPH'=>'Schengen','ARN'=>'Schengen',
    'BRU'=>'Schengen','VIE'=>'Schengen','HEL'=>'Schengen','OSL'=>'Schengen',
];
$transitVisa = static fn (string $ap): ?string
    => isset($visaHubs[$ap]) ? 'Check ' . $visaHubs[$ap] . ' transit visa' : null;

// Layover label and CSS class
$layoverMeta = static function (?string $dur): array {
    if ($dur === null) return ['lo-normal', 'Connection'];
    if (preg_match('/^(\d+)h\s*(\d+)m$/', $dur, $m) !== 1) return ['lo-normal', 'Connection'];
    $mins = ((int) $m[1]) * 60 + (int) $m[2];
    if ($mins < 90)       return ['lo-tight',    'Short connection'];
    if ($mins >= 10 * 60) return ['lo-overnight', 'Overnight layover'];
    if ($mins >= 6 * 60)  return ['lo-long',      'Long connection'];
    return ['lo-normal', 'Connection'];
};

// Group segments into journey legs
$buildLegs = static function (array $segments): array {
    if (empty($segments)) return [];
    $legs = []; $cur = [$segments[0]];
    for ($i = 1; $i < count($segments); $i++) {
        if ($segments[$i - 1]->arrivalAirport === $segments[$i]->departureAirport) {
            $cur[] = $segments[$i];
        } else { $legs[] = $cur; $cur = [$segments[$i]]; }
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

// Full route display: KTM – PVG – PEK – KTM (all leg endpoints, no duplicates)
$routeDisplay = static function (array $legs): string {
    $ports = [];
    foreach ($legs as $leg) {
        $segs = $leg['segments'];
        $dep  = $segs[0]->departureAirport ?? null;
        $arr  = $segs[count($segs) - 1]->arrivalAirport ?? null;
        if ($dep !== null && (empty($ports) || end($ports) !== $dep)) $ports[] = $dep;
        if ($arr !== null && (empty($ports) || end($ports) !== $arr)) $ports[] = $arr;
    }
    return implode(' – ', $ports);
};

// Itinerary title with city names
$itinTitle = static function (array $legs) use ($portCity): string {
    if (empty($legs)) return 'Flight Itinerary';
    $parts = [];
    foreach ($legs as $leg) {
        $first = $leg['segments'][0] ?? null;
        $last  = $leg['segments'][count($leg['segments']) - 1] ?? null;
        if ($first && $last) {
            $parts[] = $portCity($first->departureAirport) . ' → ' . $portCity($last->arrivalAirport);
        }
    }
    return 'Flight Itinerary: ' . implode(' // ', $parts);
};

// WhatsApp text builder — rich visual structure
$buildWaText = static function () use (
    $result, $show, $showDisclaimer, $showAgencyFooter, $settings,
    $agencyName, $formatTime, $use24HourTime, $datePretty, $portDisplay, $portCity, $buildLegs
): string {
    if ($result === null || !$result->isRenderable()) return '';
    $legs = $buildLegs($result->segments);

    $lines = [];
    if ($show('show_agency_header')) {
        $lines[] = '╔══════════════════════╗';
        $lines[] = '  ✈  *' . strtoupper($agencyName) . '*';
        $lines[] = '  *FLIGHT ITINERARY*';
        $lines[] = '╚══════════════════════╝';
    } else {
        $lines[] = '✈ *FLIGHT ITINERARY*';
        $lines[] = '━━━━━━━━━━━━━━━━━━━━━━';
    }

    $pax = count($result->passengers) > 0
        ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers))
        : null;
    if ($pax) $lines[] = '👤 *Passenger:* ' . $pax;
    if ($show('show_booking_reference') && $result->recordLocator !== null)
        $lines[] = '🔖 *Booking Ref:* ' . $result->recordLocator;
    $lines[] = '';

    foreach ($legs as $leg) {
        $segs     = $leg['segments'];
        $legLabel = $leg['label'];
        $legFirst = $segs[0] ?? null;
        $legLast  = $segs[count($segs) - 1] ?? null;

        if ($legLabel && $legFirst && $legLast) {
            $lines[] = '━━━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '🗺 *' . $legLabel . ': ' . $portCity($legFirst->departureAirport) . ' → ' . $portCity($legLast->arrivalAirport) . '*';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━━━';
        }

        foreach ($segs as $seg) {
            $dep = $formatTime($seg->departureTime, $use24HourTime);
            $arr = $formatTime($seg->arrivalTime, $use24HourTime);

            $lines[] = '';
            $lines[] = '✈ *' . $seg->airlineCode . $seg->flightNumber . '*'
                . ($seg->airlineName ? '  _' . $seg->airlineName . '_' : '');
            if ($seg->operatedBy) $lines[] = '   🤝 _Operated by: ' . $seg->operatedBy . '_';
            $lines[] = '📅 *' . $datePretty($seg->departureDate) . '*';
            $lines[] = '🛫 *Departs:* ' . $dep . ' · ' . $portDisplay($seg->departureAirport);
            if ($seg->departureTerminal) $lines[] = '   🏢 Terminal ' . $seg->departureTerminal;
            $lines[] = '🛬 *Arrives:* ' . $arr . ' · ' . $portDisplay($seg->arrivalAirport);
            if ($show('show_cabin') && $seg->cabin) $lines[] = '💺 *Class:* ' . $seg->cabin;
            if ($show('show_flight_duration') ?? true) {
                // no duration in raw text; skip
            }
            if ($seg->layoverDuration) {
                $lines[] = '';
                $lines[] = '⏱ *Layover at ' . $portCity($seg->arrivalAirport) . ':* ' . $seg->layoverDuration;
            }
        }
    }

    $lines[] = '';
    $lines[] = '━━━━━━━━━━━━━━━━━━━━━━';

    if ($showDisclaimer)
        $lines[] = '⚠ _' . ($settings['default_disclaimer'] ?? 'Please verify schedule with airline.') . '_';

    if ($showAgencyFooter) {
        $footer = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
        $ho     = is_array($footer['head_office'] ?? null) ? $footer['head_office'] : [];
        if (!empty($ho['lines'])) {
            $lines[] = '';
            if (!empty($ho['title'])) $lines[] = '*' . $ho['title'] . '*';
            foreach ((array) $ho['lines'] as $ln) $lines[] = (string) $ln;
        }
    }
    return implode("\n", $lines);
};

// Plain text builder
$buildTextVersion = static function () use ($result, $show, $showDisclaimer, $settings,
    $agencyName, $formatTime, $use24HourTime, $portDisplay, $datePretty): string {
    if ($result === null || !$result->isRenderable()) return '';
    $lines = [$agencyName . ' — Flight Itinerary', str_repeat('-', 50)];
    $pax = count($result->passengers) > 0
        ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers))
        : 'Not detected';
    $lines[] = 'Passenger: ' . $pax;
    if ($show('show_booking_reference') && $result->recordLocator !== null)
        $lines[] = 'Booking Ref: ' . $result->recordLocator;
    $lines[] = '';
    foreach ($result->segments as $i => $seg) {
        $dep = $formatTime($seg->departureTime, $use24HourTime);
        $arr = $formatTime($seg->arrivalTime, $use24HourTime);
        $lines[] = $datePretty($seg->departureDate) . ' — ' . $seg->airlineCode . $seg->flightNumber;
        $lines[] = '  Departs: ' . $dep . '  ' . $portDisplay($seg->departureAirport);
        $lines[] = '  Arrives: ' . $arr . '  ' . $portDisplay($seg->arrivalAirport);
        if ($seg->layoverDuration) $lines[] = '  Connection at ' . $seg->arrivalAirport . ': ' . $seg->layoverDuration;
        $lines[] = '';
    }
    if ($showDisclaimer) $lines[] = $settings['default_disclaimer'] ?? '';
    return trim(implode("\n", $lines));
};

// Extract fare and baggage from raw input
$rawFare    = null;
$rawBaggage = null;
if ($rawInput !== '') {
    if (preg_match('/\b((?:Rs|NPR|INR|USD|EUR|GBP|AUD|AED|SAR|QAR|BDT|THB|SGD)\.?\s*[\d,]+(?:\.\d{1,2})?(?:\s*\/-)?)/i', $rawInput, $fm)) {
        $rawFare = trim($fm[1]);
    }
    if (preg_match('/\b(?:baggage|bag|bgga?ge?)\s*[:\-]?\s*([\d]+\s*(?:kg|pc|pcs?|piece)s?(?:\s*x\s*[\d]+\s*(?:kg|pcs?)?)?)/i', $rawInput, $bm)) {
        $rawBaggage = trim($bm[1]);
    }
}

$renderable = $result !== null && $result->isRenderable();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Free PNR converter by Roaming Nepal — turn raw GDS flight itineraries into beautiful, branded, customer-ready formats. Supports Amadeus, Galileo, Sabre, Worldspan & more. No sign-up needed.">
    <meta name="keywords" content="PNR converter, GDS itinerary converter, Amadeus, Galileo, Sabre, flight itinerary, travel agency tool">
    <title>PNR Converter — <?= Html::e($agencyName) ?> | Free GDS Flight Itinerary Tool</title>
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/styles.css')) ?>">
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/print.css')) ?>" media="print">
</head>
<body<?= $renderable ? ' class="has-result"' : '' ?>>

<div class="share-hint no-print" aria-hidden="true">Press Esc to exit share view</div>

<header class="topbar no-share">
    <a class="brand-link" href="/">
        <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?>" class="brand-logo">
    </a>
    <div class="topbar-right">
        <span class="privacy-tag">No PNR storage · v<?= Html::e($appVersion) ?></span>
        <?php if ($renderable): ?>
            <button class="btn btn-outline-sm" type="button" id="shareModeBtn">Share view</button>
        <?php endif; ?>
    </div>
</header>

<main class="page-wrap">
    <form method="post" id="converterForm" autocomplete="off">
        <div class="app-layout<?= $renderable ? ' app-layout--split' : '' ?>">

            <!-- ══════════════════════════════════════
                 SIDEBAR — Input + Options
            ══════════════════════════════════════ -->
            <div class="app-sidebar">

                <!-- Input card -->
                <div class="input-card">
                    <textarea
                        id="pnr_text"
                        name="pnr_text"
                        rows="<?= $renderable ? '8' : '11' ?>"
                        spellcheck="false"
                        placeholder="Paste GDS itinerary here — Amadeus, Galileo, Sabre, Worldspan, Smartpoint...

Example:
1  QR 647  21MAY KTMDOH HK1 1910 2200
2  QR 007  21MAY DOHLHR HK1 2355 0545+1"><?= Html::e($rawInput) ?></textarea>

                    <div class="input-foot">
                        <div class="input-chips">
                            <span title="Your PNR is never saved or logged">🔒 Memory only</span>
                            <span title="Sensitive lines are automatically ignored">🚫 Passport/payment ignored</span>
                        </div>
                        <div class="convert-btns">
                            <button type="submit" class="btn btn-convert">✈&nbsp; Convert</button>
                            <button type="reset" id="resetBtn" class="btn btn-ghost">Clear</button>
                        </div>
                    </div>
                </div>

            </div><!-- /app-sidebar -->

            <!-- ══════════════════════════════════════
                 MAIN — Options strip + Result
            ══════════════════════════════════════ -->
            <div class="app-main">

                <!-- ── Options strip (always visible, auto-submits on change) ── -->
                <div class="options-strip settings-panel no-share">

                    <!-- Row 1: Layout format + presets -->
                    <div class="ostrip-row">
                        <span class="ostrip-label">Layout</span>
                        <div class="theme-pills" role="radiogroup">
                            <?php foreach ([
                                'detailed'    => 'Graphic',
                                'table'       => 'Table',
                                'three_lines' => '3 Lines',
                                'two_lines'   => '2 Lines',
                                'compact'     => 'Compact',
                                'whatsapp'    => 'WhatsApp',
                            ] as $val => $lbl): ?>
                                <label class="theme-pill">
                                    <input type="radio" name="result_format" value="<?= Html::e($val) ?>"<?= Html::checked($resultFormat === $val) ?>>
                                    <span><?= Html::e($lbl) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="ostrip-sep"></div>
                        <div class="preset-pills">
                            <button type="button" class="pill-btn" data-preset="branded">Branded</button>
                            <button type="button" class="pill-btn" data-preset="neutral">Neutral</button>
                            <button type="button" class="pill-btn" data-preset="whatsapp">WA</button>
                        </div>
                        <div class="ostrip-sep"></div>
                        <div class="dist-pills" role="radiogroup">
                            <?php foreach (['off' => 'Dist: Off', 'km' => 'km', 'miles' => 'mi'] as $val => $lbl): ?>
                                <label class="pill-radio-sm">
                                    <input type="radio" name="distance_unit" value="<?= Html::e($val) ?>"<?= Html::checked(($features['distance_unit'] ?? 'off') === $val) ?>>
                                    <span><?= Html::e($lbl) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Row 2: Flight detail toggles -->
                    <div class="ostrip-row ostrip-row--toggles">
                        <span class="ostrip-label">Show</span>
                        <?php foreach ([
                            'show_airline_logo'      => 'Logo',
                            'show_airline_name'      => 'Airline',
                            'show_flight_duration'   => 'Duration',
                            'show_transit_time'      => 'Layover',
                            'show_terminal'          => 'Terminal',
                            'show_cabin'             => 'Cabin',
                            'show_operated_by'       => 'Operated by',
                            'show_aircraft'          => 'Aircraft',
                            'use_12_hour_clock'      => '12h Clock',
                            'show_booking_reference' => 'Booking Ref',
                            'show_booking_class'     => 'Class Code',
                            'show_ticket_numbers'    => 'Ticket No.',
                            'show_seat_numbers'      => 'Seat No.',
                        ] as $key => $label): ?>
                            <label class="mini-toggle" title="<?= Html::e($label) ?>">
                                <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                                <input type="checkbox" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                                <span class="mt-track" aria-hidden="true"></span>
                                <span class="mt-label"><?= Html::e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Row 3: Agency branding (optional) -->
                    <div class="ostrip-row ostrip-row--agency">
                        <span class="ostrip-label">Agency <span class="ostrip-badge">Optional</span></span>
                        <?php foreach ([
                            'show_agency_header' => 'Header',
                            'show_agency_footer' => 'Footer',
                            'show_disclaimer'    => 'Disclaimer',
                        ] as $key => $label): ?>
                            <label class="mini-toggle" title="<?= Html::e($label) ?>">
                                <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                                <input type="checkbox" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                                <span class="mt-track" aria-hidden="true"></span>
                                <span class="mt-label"><?= Html::e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                </div><!-- /options-strip -->

                <!-- Result action bar -->
                <?php if ($result !== null): ?>
                <div class="result-bar no-share">
                    <div class="parse-badges">
                        <span class="pbadge pbadge-fmt"><?= Html::e($result->sourceFormat) ?></span>
                        <span class="pbadge pbadge-<?= Html::e($result->confidence) ?>"><?= Html::e(strtoupper($result->confidence)) ?></span>
                        <span class="pbadge"><?= Html::e((string) count($result->segments)) ?> flight<?= count($result->segments) !== 1 ? 's' : '' ?></span>
                        <?php if (count($result->passengers) > 0): ?>
                            <span class="pbadge"><?= Html::e((string) count($result->passengers)) ?> pax</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($renderable): ?>
                        <div class="result-actions">
                            <button type="button" class="btn btn-wa btn-sm" id="waBtn">📱 WhatsApp</button>
                            <button type="button" class="btn btn-sm" id="copyTextBtn">📋 Copy Text</button>
                            <button type="button" class="btn btn-sm" id="copyImageBtn">🖼 Copy Image</button>
                            <button type="button" class="btn btn-sm" id="downloadPngBtn">💾 Save PNG</button>
                            <button type="button" class="btn btn-sm" id="printBtn">🖨 Print</button>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Alerts -->
                <?php if ($rateLimited): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong>Rate limit reached.</strong> You have exceeded 40 conversions per hour. Please wait and try again.
                    </div>
                <?php endif; ?>

                <?php if ($result !== null && count($result->warnings) > 0): ?>
                    <div class="alert alert-warn" role="alert">
                        <?php foreach ($result->warnings as $w): ?><p><?= Html::e($w) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($result === null && !$rateLimited): ?>
                    <div class="empty-hint">
                        <svg class="empty-plane" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 00-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>
                        <p>Paste a GDS itinerary in the box and click <strong>Convert</strong></p>
                        <span class="empty-sub">Amadeus · Galileo · Sabre · Worldspan · Smartpoint</span>
                    </div>

                <?php elseif ($result !== null && !$result->isRenderable()): ?>
                    <div class="alert alert-warn">
                        <strong>Manual review needed.</strong> Confidence too low to generate a card.
                        <?php if (count($result->unparsedLines) > 0): ?>
                            <ul><?php foreach ($result->unparsedLines as $ul): ?><li><code><?= Html::e($ul) ?></code></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </div>

                <?php elseif ($renderable): ?>

        <!-- ══════════════════════════════════════
             ITINERARY CARD
        ══════════════════════════════════════ -->
        <article class="itin-card" id="itineraryCard">

            <?php if ($showAgencyHeader): ?>
                <div class="card-agency">
                    <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?>" class="agency-logo">
                    <span class="agency-label">Flight Itinerary</span>
                </div>
            <?php endif; ?>

            <?php
            $firstSeg = $result->segments[0] ?? null;
            $lastSeg  = $result->segments[count($result->segments) - 1] ?? null;
            ?>
            <div class="card-title-row">
                <h2 class="card-title"><?= Html::e($itinTitle($legs)) ?></h2>
            </div>

            <div class="card-pax">
                <div class="pax-item">
                    <span class="pax-key">Passenger<?= count($result->passengers) !== 1 ? 's' : '' ?></span>
                    <span class="pax-val">
                        <?= Html::e(count($result->passengers) > 0
                            ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers))
                            : 'Not detected') ?>
                    </span>
                </div>
                <?php if ($show('show_booking_reference') && $result->recordLocator !== null): ?>
                    <div class="pax-item">
                        <span class="pax-key">Booking ref</span>
                        <span class="pax-val"><span class="ref-code"><?= Html::e($result->recordLocator) ?></span></span>
                    </div>
                <?php endif; ?>
                <?php if ($firstSeg instanceof Segment && $lastSeg instanceof Segment): ?>
                    <div class="pax-item">
                        <span class="pax-key">Route</span>
                        <span class="pax-val"><?= Html::e($routeDisplay($legs)) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($rawFare !== null || $rawBaggage !== null): ?>
                <div class="card-fare-row">
                    <?php if ($rawFare !== null): ?>
                        <span class="fare-chip"><span class="fare-key">Fare</span> <?= Html::e($rawFare) ?></span>
                    <?php endif; ?>
                    <?php if ($rawBaggage !== null): ?>
                        <span class="fare-chip"><span class="fare-key">Baggage</span> <?= Html::e($rawBaggage) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($resultFormat === 'table'): ?>
                <!-- ── TABLE FORMAT ──────────────────── -->
                <?php foreach ($legs as $leg):
                    /** @var array{label:?string,segments:Segment[]} $leg */
                    $legSegs  = $leg['segments'];
                    $legLabel = $leg['label'];
                    $legFirst = $legSegs[0] ?? null;
                    $legLast  = $legSegs[count($legSegs) - 1] ?? null;
                    $legDur   = $legDuration($legSegs);
                ?>
                <?php if ($legLabel !== null && $legFirst && $legLast): ?>
                    <div class="leg-head">
                        <span><?= Html::e($legLabel) ?>: <?= Html::e($portCity($legFirst->departureAirport)) ?> &rarr; <?= Html::e($portCity($legLast->arrivalAirport)) ?></span>
                        <?php if ($legDur): ?><span class="leg-dur"><?= Html::e($legDur) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="table-scroll">
                    <table class="seg-table">
                        <thead><tr>
                            <th>Flight</th><th>Date</th>
                            <th>Departs</th><th>From</th>
                            <th>Arrives</th><th>To</th>
                            <th>Info</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($legSegs as $seg): ?>
                            <?php
                            /** @var Segment $seg */
                            $logo   = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                            $dur    = $flightDuration($seg);
                            $offset = $arrivalOffset($seg);
                            $dist   = Metadata::distanceLabel($seg->departureAirport, $seg->arrivalAirport, $distanceUnit);
                            ?>
                            <tr>
                                <td>
                                    <div class="tbl-flight">
                                        <?php if ($logo): ?><?= $logoImg($logo, 'tbl-logo', $seg->airlineCode) ?><?php else: ?><span class="tbl-code"><?= Html::e($seg->airlineCode) ?></span><?php endif; ?>
                                        <div>
                                            <strong><?= Html::e($seg->airlineCode . $seg->flightNumber) ?></strong>
                                            <?php if ($show('show_airline_name') && $seg->airlineName): ?><em><?= Html::e($seg->airlineName) ?></em><?php endif; ?>
                                            <?php if ($show('show_operated_by') && $seg->operatedBy): ?><small class="tbl-opby">Op: <?= Html::e($seg->operatedBy) ?></small><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= Html::e($datePretty($seg->departureDate)) ?></td>
                                <td><strong><?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?></strong><?php if ($seg->departureTerminal): ?><br><span class="tbl-term"><?= Html::e($seg->departureTerminal) ?></span><?php endif; ?></td>
                                <td><?= Html::e($portDisplay($seg->departureAirport)) ?></td>
                                <td>
                                    <strong><?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?></strong>
                                    <?php if ($offset > 0): ?><span class="day-badge">+<?= $offset ?>d</span><?php endif; ?>
                                </td>
                                <td><?= Html::e($portDisplay($seg->arrivalAirport)) ?></td>
                                <td class="td-chips">
                                    <?php if ($dur): ?><span><?= Html::e($dur) ?></span><?php endif; ?>
                                    <?php if ($show('show_cabin') && $seg->cabin): ?><span><?= Html::e($seg->cabin) ?></span><?php endif; ?>
                                    <?php if ($show('show_booking_class') && $seg->bookingClass): ?><span><?= Html::e($seg->bookingClass) ?></span><?php endif; ?>
                                    <?php if ($dist): ?><span><?= Html::e($dist) ?></span><?php endif; ?>
                                    <?php if ($show('show_aircraft') && $seg->aircraft): ?><span><?= Html::e($seg->aircraft) ?></span><?php endif; ?>
                                </td>
                            </tr>
                            <?php if (($show('show_transit_time') || $show('show_layover')) && $seg->layoverDuration): ?>
                                <?php [$loClass, $loLabel] = $layoverMeta($seg->layoverDuration); $visa = $transitVisa($seg->arrivalAirport); ?>
                                <tr class="tbl-layover-row <?= Html::e($loClass) ?>">
                                    <td colspan="7">
                                        <span class="lo-sep-inner">
                                            &mdash;&mdash; <?= Html::e($loLabel) ?> at <?= Html::e($portCity($seg->arrivalAirport)) ?> (<?= Html::e($seg->arrivalAirport) ?>): <strong><?= Html::e($seg->layoverDuration) ?></strong>
                                            <?php if ($visa): ?> &middot; <span class="visa-flag"><?= Html::e($visa) ?></span><?php endif; ?>
                                            &mdash;&mdash;
                                        </span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>

            <?php elseif (in_array($resultFormat, ['compact', 'whatsapp'], true)): ?>
                <!-- ── COMPACT FORMAT ────────────────── -->
                <?php foreach ($legs as $leg):
                    $legSegs  = $leg['segments'];
                    $legLabel = $leg['label'];
                    $legFirst = $legSegs[0] ?? null;
                    $legLast  = $legSegs[count($legSegs) - 1] ?? null;
                    $legDur   = $legDuration($legSegs);
                ?>
                <?php if ($legLabel !== null && $legFirst && $legLast): ?>
                    <div class="leg-head">
                        <span><?= Html::e($legLabel) ?>: <?= Html::e($portCity($legFirst->departureAirport)) ?> &rarr; <?= Html::e($portCity($legLast->arrivalAirport)) ?></span>
                        <?php if ($legDur): ?><span class="leg-dur"><?= Html::e($legDur) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="compact-flights">
                    <?php foreach ($legSegs as $seg): ?>
                        <?php
                        /** @var Segment $seg */
                        $logo   = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                        $dur    = $flightDuration($seg);
                        $offset = $arrivalOffset($seg);
                        ?>
                        <div class="cpt-flight">
                            <div class="cpt-head">
                                <?php if ($logo): ?><?= $logoImg($logo, 'cpt-logo', $seg->airlineCode) ?><?php else: ?><span class="cpt-code"><?= Html::e($seg->airlineCode) ?></span><?php endif; ?>
                                <strong><?= Html::e($datePretty($seg->departureDate)) ?></strong>
                                <span class="cpt-fn"><?= Html::e($seg->airlineCode . $seg->flightNumber) ?><?php if ($show('show_airline_name') && $seg->airlineName): ?> · <?= Html::e($seg->airlineName) ?><?php endif; ?></span>
                                <?php if ($dur): ?><span class="cpt-dur"><?= Html::e($dur) ?></span><?php endif; ?>
                            </div>
                            <div class="cpt-route">
                                <div class="cpt-port">
                                    <span class="cpt-code-big"><?= Html::e($seg->departureAirport) ?></span>
                                    <span class="cpt-time"><?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?></span>
                                    <span class="cpt-city"><?= Html::e($portCity($seg->departureAirport)) ?></span>
                                </div>
                                <div class="cpt-arrow">&#x2192;</div>
                                <div class="cpt-port cpt-port-r">
                                    <span class="cpt-code-big"><?= Html::e($seg->arrivalAirport) ?><?php if ($offset > 0): ?><span class="day-badge">+<?= $offset ?>d</span><?php endif; ?></span>
                                    <span class="cpt-time"><?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?></span>
                                    <span class="cpt-city"><?= Html::e($portCity($seg->arrivalAirport)) ?></span>
                                </div>
                            </div>
                            <?php if ($show('show_cabin') && $seg->cabin): ?><div class="cpt-meta"><span><?= Html::e($seg->cabin) ?></span><?php if ($show('show_booking_class') && $seg->bookingClass): ?><span><?= Html::e($seg->bookingClass) ?></span><?php endif; ?></div><?php endif; ?>
                        </div>
                        <?php if (($show('show_transit_time') || $show('show_layover')) && $seg->layoverDuration): ?>
                            <?php [$loClass, $loLabel] = $layoverMeta($seg->layoverDuration); $visa = $transitVisa($seg->arrivalAirport); ?>
                            <div class="lo-sep <?= Html::e($loClass) ?>">
                                &mdash;&mdash; <?= Html::e($loLabel) ?> at <?= Html::e($portCity($seg->arrivalAirport)) ?> (<?= Html::e($seg->arrivalAirport) ?>): <strong><?= Html::e($seg->layoverDuration) ?></strong><?php if ($visa): ?> &middot; <span class="visa-flag"><?= Html::e($visa) ?></span><?php endif; ?> &mdash;&mdash;
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

            <?php else: ?>
                <!-- ── DETAILED FORMAT (default, pnrexpert-style) ── -->
                <?php foreach ($legs as $leg):
                    /** @var array{label:?string,segments:Segment[]} $leg */
                    $legSegs  = $leg['segments'];
                    $legLabel = $leg['label'];
                    $legFirst = $legSegs[0] ?? null;
                    $legLast  = $legSegs[count($legSegs) - 1] ?? null;
                    $legDur   = $legDuration($legSegs);
                ?>
                <div class="leg-section">
                    <?php if ($legLabel !== null && $legFirst && $legLast): ?>
                        <div class="leg-head">
                            <span><?= Html::e($legLabel) ?>: <?= Html::e($portCity($legFirst->departureAirport)) ?> &rarr; <?= Html::e($portCity($legLast->arrivalAirport)) ?></span>
                            <?php if ($legDur): ?><span class="leg-dur"><?= Html::e($legDur) ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($legSegs as $si => $seg): ?>
                        <?php
                        /** @var Segment $seg */
                        $logo   = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                        $dur    = $flightDuration($seg);
                        $offset = $arrivalOffset($seg);
                        $dist   = Metadata::distanceLabel($seg->departureAirport, $seg->arrivalAirport, $distanceUnit);
                        $visa   = $transitVisa($seg->arrivalAirport);
                        ?>

                        <div class="flight-block">
                            <div class="fb-headline">
                                <span><?= Html::e($datePretty($seg->departureDate)) ?></span>
                                <span class="fb-sep">·</span>
                                <strong><?= Html::e($seg->airlineCode . $seg->flightNumber) ?></strong>
                                <?php if ($show('show_airline_name') && $seg->airlineName): ?>
                                    <span class="fb-alname"><?= Html::e($seg->airlineName) ?></span>
                                <?php endif; ?>
                                <?php if ($dur): ?><span class="fb-dur"><?= Html::e($dur) ?></span><?php endif; ?>
                                <?php if ($seg->status): ?><span class="fb-status"><?= Html::e($seg->status) ?></span><?php endif; ?>
                            </div>

                            <div class="fb-body">
                                <div class="fb-logo-col">
                                    <?php if ($logo !== null): ?>
                                        <?= $logoImg($logo, 'fb-logo', $seg->airlineCode) ?>
                                    <?php else: ?>
                                        <span class="fb-code-badge"><?= Html::e($seg->airlineCode) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="fb-routes">
                                    <p class="fb-route-line">
                                        <span class="fb-dir">Departs:</span>
                                        <span class="fb-time"><?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?></span>
                                        <span class="fb-port"><?= Html::e($portDisplay($seg->departureAirport)) ?></span>
                                        <?php if ($seg->departureTerminal): ?>
                                            <span class="fb-term"><?= Html::e($seg->departureTerminal) ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="fb-route-line">
                                        <span class="fb-dir">Arrives:</span>
                                        <span class="fb-time"><?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?></span>
                                        <?php if ($offset > 0): ?><span class="day-badge">+<?= $offset ?>d</span><?php endif; ?>
                                        <span class="fb-port"><?= Html::e($portDisplay($seg->arrivalAirport)) ?></span>
                                    </p>

                                    <?php if ($show('show_cabin') && $seg->cabin || $show('show_booking_class') && $seg->bookingClass || $dist || $show('show_aircraft') && $seg->aircraft || $show('show_operated_by') && $seg->operatedBy || $show('show_ticket_numbers') && $seg->ticketNumber || $show('show_seat_numbers') && $seg->seatNumber): ?>
                                        <div class="fb-chips">
                                            <?php if ($show('show_cabin') && $seg->cabin): ?><span><?= Html::e($seg->cabin) ?></span><?php endif; ?>
                                            <?php if ($show('show_booking_class') && $seg->bookingClass): ?><span>Class <?= Html::e($seg->bookingClass) ?></span><?php endif; ?>
                                            <?php if ($dist): ?><span><?= Html::e($dist) ?></span><?php endif; ?>
                                            <?php if ($show('show_aircraft') && $seg->aircraft): ?><span><?= Html::e($seg->aircraft) ?></span><?php endif; ?>
                                            <?php if ($show('show_operated_by') && $seg->operatedBy): ?><span>Op: <?= Html::e($seg->operatedBy) ?></span><?php endif; ?>
                                            <?php if ($show('show_ticket_numbers') && $seg->ticketNumber): ?><span>TKT <?= Html::e($maskTicket($seg->ticketNumber, (bool) ($features['mask_ticket_numbers'] ?? true))) ?></span><?php endif; ?>
                                            <?php if ($show('show_seat_numbers') && $seg->seatNumber): ?><span>Seat <?= Html::e($maskSeat($seg->seatNumber, (bool) ($features['mask_seat_numbers'] ?? false))) ?></span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (($show('show_transit_time') || $show('show_layover')) && $seg->layoverDuration): ?>
                            <?php [$loClass, $loLabel] = $layoverMeta($seg->layoverDuration); ?>
                            <div class="lo-sep <?= Html::e($loClass) ?>">
                                &mdash;&mdash;&mdash; <?= Html::e($loLabel) ?> at <?= Html::e($portCity($seg->arrivalAirport)) ?> (<?= Html::e($seg->arrivalAirport) ?>): <strong><?= Html::e($seg->layoverDuration) ?></strong><?php if ($visa): ?> &middot; <span class="visa-flag"><?= Html::e($visa) ?></span><?php endif; ?> &mdash;&mdash;&mdash;
                            </div>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- ── Card footer ────────────────────── -->
            <?php if ($showDisclaimer || $showAgencyFooter): ?>
                <div class="card-footer">
                    <?php if ($showDisclaimer): ?>
                        <p class="disclaimer"><?= Html::e((string) ($settings['default_disclaimer'] ?? 'Please verify final schedule, terminal and check-in details with the airline before travel.')) ?></p>
                    <?php endif; ?>
                    <?php if ($showAgencyFooter):
                        $footer     = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
                        $headOffice = is_array($footer['head_office'] ?? null) ? $footer['head_office'] : [];
                        $branches   = is_array($footer['branches'] ?? null) ? $footer['branches'] : [];
                    ?>
                        <div class="footer-grid">
                            <?php if (!empty($headOffice)): ?>
                                <div class="footer-col">
                                    <?php if (!empty($headOffice['title'])): ?><strong><?= Html::e((string) $headOffice['title']) ?></strong><?php endif; ?>
                                    <?php foreach ((array) ($headOffice['lines'] ?? []) as $ln): ?><span><?= Html::e((string) $ln) ?></span><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($branches as $branch): ?>
                                <?php if (!is_array($branch)) continue; ?>
                                <div class="footer-col">
                                    <?php if (!empty($branch['title'])): ?><strong><?= Html::e((string) $branch['title']) ?></strong><?php endif; ?>
                                    <?php foreach ((array) ($branch['lines'] ?? []) as $ln): ?><span><?= Html::e((string) $ln) ?></span><?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($settings['footer_note'])): ?><p class="footer-note"><?= Html::e((string) $settings['footer_note']) ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </article><!-- /itin-card -->

        <textarea class="sr-only" id="textVersion" readonly><?= Html::e($buildTextVersion()) ?></textarea>
        <textarea class="sr-only" id="waVersion"   readonly><?= Html::e($buildWaText()) ?></textarea>

        <?php endif; /* renderable */ ?>

            </div><!-- /app-main -->
        </div><!-- /app-layout -->
    </form>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= Html::e($asset('assets/js/app.js')) ?>"></script>
</body>
</html>
