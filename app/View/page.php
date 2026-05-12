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
$resultFormat = in_array((string) ($features['result_format'] ?? 'table'),
    ['detailed', 'compact', 'table', 'whatsapp', 'two_lines', 'two_lines_reordered', 'three_lines', 'three_lines_reordered'], true)
    ? (string) $features['result_format'] : 'table';
$use24HourTime = array_key_exists('use_12_hour_clock', $features)
    ? !(bool) $features['use_12_hour_clock']
    : (bool) ($features['use_24_hour_time'] ?? true);

$featureDefaults = [
    'show_airline_logo'      => true,
    'show_airline_name'      => true,
    'show_flight_duration'   => true,
    'show_transit_time'      => true,
    'show_operated_by'       => true,
    'show_cabin'             => true,
    'show_terminal'          => false,
    'show_aircraft'          => false,
    'show_booking_class'     => false,
    'show_booking_reference' => false,
    'show_ticket_numbers'    => false,
    'show_seat_numbers'      => false,
    'show_agency_header'     => false,
    'show_agency_footer'     => false,
    'show_disclaimer'        => false,
    'show_must_read'         => false,
];

$show             = static fn (string $key): bool
    => (bool) ($features[$key] ?? $featureDefaults[$key] ?? false);
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

$gdsDateTime = static function (string $date, string $time, ?string $airportCode = null): ?\DateTimeImmutable {
    if (preg_match('/^(\d{2})([A-Z]{3})(\d{4})?$/i', strtoupper($date), $d) !== 1) return null;
    if (preg_match('/^(\d{2}):?(\d{2})$/', $time, $t) !== 1) return null;
    $mo = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
    $mn = $mo[strtoupper($d[2])] ?? null;
    if ($mn === null) return null;
    $y = isset($d[3]) && $d[3] !== '' ? (int) $d[3] : (int) date('Y');
    $dtStr = sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mn, (int) $d[1], (int) $t[1], (int) $t[2]);
    if ($airportCode !== null) {
        $tzId = Metadata::airportTimezone($airportCode);
        if ($tzId !== null) {
            try { return new \DateTimeImmutable($dtStr, new \DateTimeZone($tzId)); } catch (\Exception) {}
        }
    }
    return new \DateTimeImmutable($dtStr);
};

// "Sat 30 May" display
$datePretty = static function (string $date) use ($gdsDateTime): string {
    $dt = $gdsDateTime($date, '00:00');
    return $dt !== null ? $dt->format('D j M') : $date;
};

// Flight duration "3h 50m" — timezone-aware
$flightDuration = static function (Segment $seg) use ($gdsDateTime): ?string {
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime, $seg->departureAirport);
    $arr = $gdsDateTime($seg->arrivalDate ?: $seg->departureDate, $seg->arrivalTime, $seg->arrivalAirport);
    if ($dep === null || $arr === null) return null;
    if ($arr->getTimestamp() < $dep->getTimestamp()) $arr = $arr->modify('+1 day');
    $mins = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
    if ($mins <= 0 || $mins > 48 * 60) return null;
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
};

// Leg total duration (first dep → last arr including layovers) — timezone-aware
$legDuration = static function (array $segs) use ($gdsDateTime): ?string {
    if (empty($segs)) return null;
    $first = $segs[0]; $last = $segs[count($segs) - 1];
    $dep = $gdsDateTime($first->departureDate, $first->departureTime, $first->departureAirport);
    $arr = $gdsDateTime($last->arrivalDate ?: $last->departureDate, $last->arrivalTime, $last->arrivalAirport);
    if ($dep === null || $arr === null) return null;
    if ($arr->getTimestamp() < $dep->getTimestamp()) $arr = $arr->modify('+1 day');
    $mins = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
    if ($mins <= 0 || $mins > 96 * 60) return null;
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
};

// Arrival day offset +1/+2
$arrivalOffset = static function (Segment $seg) use ($gdsDateTime): int {
    if (!$seg->arrivalDate) return 0;
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime, $seg->departureAirport);
    $arr = $gdsDateTime($seg->arrivalDate, $seg->arrivalTime, $seg->arrivalAirport);
    if ($dep === null || $arr === null || $arr->getTimestamp() <= $dep->getTimestamp()) return 0;
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

// Itinerary title with city names (no "Flight Itinerary:" prefix)
$itinTitle = static function (array $legs) use ($portCity): string {
    if (empty($legs)) return 'Flight Itinerary';
    $allSegs = [];
    foreach ($legs as $leg) { foreach ($leg['segments'] as $s) { $allSegs[] = $s; } }
    if (empty($allSegs)) return 'Flight Itinerary';
    $first = $allSegs[0];
    $last  = $allSegs[count($allSegs) - 1];
    $origin = $portCity($first->departureAirport);
    $dest   = $portCity($last->arrivalAirport);
    if ($origin === $dest) {
        $waypoints = [$origin];
        foreach ($allSegs as $seg) {
            $city = $portCity($seg->arrivalAirport);
            if ($city !== end($waypoints)) $waypoints[] = $city;
        }
        return implode(' → ', $waypoints);
    }
    return $origin . ' → ' . $dest;
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
        <div class="app-layout<?= $renderable ? ' app-layout--split' : '' ?>" id="appLayout">

            <!-- ══════════════════════════════════════
                 SIDEBAR — Input + Export
            ══════════════════════════════════════ -->
            <div class="app-sidebar" id="appSidebar">

                <div class="sidebar-hd">
                    <span class="sidebar-hd-title">
                        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 2h14v14H2z"/><path d="M2 7h14M7 7v9"/></svg>
                        PNR Input
                    </span>
                    <button type="button" class="sidebar-collapse-btn" id="collapseInputBtn" title="Hide input panel">
                        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 3L5 9l6 6"/></svg>
                    </button>
                </div>

                <!-- Input card -->
                <div class="input-card">
                    <textarea
                        id="pnr_text"
                        name="pnr_text"
                        rows="<?= $renderable ? '10' : '14' ?>"
                        spellcheck="false"
                        placeholder="Paste GDS itinerary here — Amadeus, Galileo, Sabre, Worldspan, Smartpoint...

Example:
1  QR 647  21MAY KTMDOH HK1 1910 2200
2  QR 007  21MAY DOHLHR HK1 2355 0545+1"><?= Html::e($rawInput) ?></textarea>

                    <div class="input-foot">
                        <div class="input-chips">
                            <span title="Your PNR is never saved or logged">🔒 Private</span>
                            <span title="Sensitive lines are automatically ignored">🚫 Docs ignored</span>
                        </div>
                        <div class="convert-btns">
                            <button type="submit" class="btn btn-convert">✈ Convert</button>
                            <button type="reset" id="resetBtn" class="btn btn-ghost">Clear</button>
                        </div>
                    </div>
                </div>

                <?php if ($renderable): ?>
                <!-- Primary export actions -->
                <div class="sidebar-actions">
                    <button type="button" class="btn btn-export sidebar-btn" id="copyImageBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="2" y="2" width="16" height="16" rx="2"/><circle cx="7" cy="7" r="1.5" fill="currentColor" stroke="none"/><path d="M2 13l5-5 4 4 2-2 5 5"/></svg>
                        Copy Image
                    </button>
                    <button type="button" class="btn btn-export sidebar-btn" id="downloadPngBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 3v9M6 8l4 4 4-4M4 14v2a1 1 0 001 1h10a1 1 0 001-1v-2"/></svg>
                        Save PNG
                    </button>
                    <button type="button" class="btn btn-export sidebar-btn" id="printBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 7V3h10v4M5 13H3a1 1 0 01-1-1V8a1 1 0 011-1h14a1 1 0 011 1v4a1 1 0 01-1 1h-2M5 11h10v6H5v-6z"/></svg>
                        Print
                    </button>
                </div>
                <?php endif; ?>

            </div><!-- /app-sidebar -->

            <!-- Expand button (shown only when sidebar is collapsed) -->
            <button type="button" class="sidebar-expand-btn" id="expandInputBtn" title="Show input panel" aria-label="Show input">
                <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M7 3l6 6-6 6"/></svg>
            </button>

            <!-- ══════════════════════════════════════
                 MAIN — Options strip + Result
            ══════════════════════════════════════ -->
            <div class="app-main">

                <!-- ── Options panel (tabbed) ── -->
                <div class="opts-panel-wrap settings-panel no-share">
                    <div class="opts-tab-bar" role="tablist">
                        <button type="button" class="opts-tab" role="tab" data-tab="layout" aria-selected="true">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="2" width="14" height="12" rx="1.5"/><path d="M1 6h14M6 6v8"/></svg>
                            Layout
                        </button>
                        <button type="button" class="opts-tab" role="tab" data-tab="details" aria-selected="false">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M2 8h8M2 12h10"/></svg>
                            Details
                        </button>
                        <button type="button" class="opts-tab" role="tab" data-tab="agency" aria-selected="false">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="1" width="12" height="14" rx="1"/><path d="M5 5h6M5 8h6M5 11h3"/></svg>
                            Agency
                        </button>
                    </div>

                    <!-- Tab: Layout -->
                    <div class="opts-tab-panel" data-panel="layout">
                        <div class="opts-section">
                            <div class="opts-section-label">Format</div>
                            <div class="theme-pills" role="radiogroup">
                                <?php foreach ([
                                    'detailed'    => 'Graphic',
                                    'table'       => 'Table',
                                    'three_lines' => '3 Lines',
                                    'two_lines'   => '2 Lines',
                                    'compact'     => 'Compact',
                                ] as $val => $lbl): ?>
                                    <label class="theme-pill">
                                        <input type="radio" name="result_format" value="<?= Html::e($val) ?>"<?= Html::checked($resultFormat === $val) ?>>
                                        <span><?= Html::e($lbl) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="opts-section opts-section--inline">
                            <div class="opts-section-label">Presets</div>
                            <div class="preset-pills">
                                <button type="button" class="pill-btn" data-preset="branded">Branded</button>
                                <button type="button" class="pill-btn" data-preset="neutral">Neutral</button>
                            </div>
                            <div class="opts-section-label opts-section-label--mid">Distance</div>
                            <div class="dist-pills" role="radiogroup">
                                <?php foreach (['off' => 'Off', 'km' => 'km', 'miles' => 'mi'] as $val => $lbl): ?>
                                    <label class="pill-radio-sm">
                                        <input type="radio" name="distance_unit" value="<?= Html::e($val) ?>"<?= Html::checked(($features['distance_unit'] ?? 'off') === $val) ?>>
                                        <span><?= Html::e($lbl) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Details -->
                    <div class="opts-tab-panel" data-panel="details" hidden>
                        <div class="opts-section opts-section--toggles">
                            <?php foreach ([
                                'show_airline_logo'      => 'Logo',
                                'show_airline_name'      => 'Airline',
                                'show_flight_duration'   => 'Duration',
                                'show_transit_time'      => 'Layover',
                                'show_terminal'          => 'Terminal',
                                'show_cabin'             => 'Cabin',
                                'show_operated_by'       => 'Op. By',
                                'show_aircraft'          => 'Aircraft',
                                'use_12_hour_clock'      => '12h',
                                'show_booking_reference' => 'Ref',
                                'show_booking_class'     => 'Class',
                                'show_ticket_numbers'    => 'Ticket',
                                'show_seat_numbers'      => 'Seat',
                            ] as $key => $label): ?>
                                <label class="mini-toggle" title="<?= Html::e($label) ?>">
                                    <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                                    <input type="checkbox" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                                    <span class="mt-track" aria-hidden="true"></span>
                                    <span class="mt-label"><?= Html::e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tab: Agency -->
                    <div class="opts-tab-panel" data-panel="agency" hidden>
                        <div class="opts-section opts-section--toggles">
                            <?php foreach ([
                                'show_agency_header' => 'Header',
                                'show_agency_footer' => 'Footer',
                                'show_disclaimer'    => 'Disclaimer',
                                'show_must_read'     => 'Must Read',
                            ] as $key => $label): ?>
                                <label class="mini-toggle" title="<?= Html::e($label) ?>">
                                    <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                                    <input type="checkbox" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                                    <span class="mt-track" aria-hidden="true"></span>
                                    <span class="mt-label"><?= Html::e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="opts-agency-note">Configure agency details in <code>config/settings.php</code></p>
                    </div>

                </div><!-- /opts-panel-wrap -->

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
                        <!-- Collapsed-mode export actions (only visible when sidebar is hidden) -->
                        <div class="result-collapsed-actions" id="collapsedExportBtns">
                            <button type="button" class="btn btn-sm btn-export-sm" id="copyImageBtn2">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="1" width="14" height="14" rx="1.5"/><circle cx="5.5" cy="5.5" r="1" fill="currentColor" stroke="none"/><path d="M1 10.5l4-4 3.5 3.5 2-2 4.5 4.5"/></svg>
                                Copy Image
                            </button>
                            <button type="button" class="btn btn-sm btn-export-sm" id="downloadPngBtn2">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2v8M5 7l3 3 3-3M3 12v1.5a.5.5 0 00.5.5h9a.5.5 0 00.5-.5V12"/></svg>
                                Save PNG
                            </button>
                            <button type="button" class="btn btn-sm btn-export-sm" id="printBtn2">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5V2h8v3M4 11H2.5A.5.5 0 012 10.5v-4A.5.5 0 012.5 6h11a.5.5 0 01.5.5v4a.5.5 0 01-.5.5H12M4 9h8v5H4V9z"/></svg>
                                Print
                            </button>
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

            <?php if ($showAgencyHeader):
                $agencyFooterCfg = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
                $agencyBranches  = is_array($agencyFooterCfg['branches'] ?? null) ? $agencyFooterCfg['branches'] : [];
                $agencyOffices   = [];
                // Always include head office city
                $hoLines = (array) ($agencyFooterCfg['head_office']['lines'] ?? []);
                $hoTitle = (string) ($agencyFooterCfg['head_office']['title'] ?? '');
                // Extract city name from title or use default
                if (preg_match('/KATHMANDU|POKHARA|SYDNEY|MELBOURNE|LONDON|DUBAI/i', $hoTitle, $hm)) {
                    $agencyOffices[] = ucfirst(strtolower($hm[0]));
                } else {
                    $agencyOffices[] = 'Kathmandu';
                }
                foreach ($agencyBranches as $br) {
                    if (is_array($br) && !empty($br['title'])) {
                        $agencyOffices[] = ucfirst(strtolower((string) $br['title']));
                    }
                }
            ?>
                <div class="card-agency">
                    <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?>" class="agency-logo">
                    <div class="agency-offices">
                        <?php foreach ($agencyOffices as $oi => $office): ?>
                            <?php if ($oi > 0): ?><span class="agency-dot">·</span><?php endif; ?>
                            <span class="agency-office">
                                <svg class="loc-pin" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a4.5 4.5 0 0 1 4.5 4.5c0 3-4.5 9-4.5 9S3.5 8.5 3.5 5.5A4.5 4.5 0 0 1 8 1zm0 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z"/></svg>
                                <?= Html::e($office) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
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
                <?php if (count($result->passengers) > 0): ?>
                <div class="pax-item">
                    <span class="pax-key">Passenger<?= count($result->passengers) !== 1 ? 's' : '' ?></span>
                    <span class="pax-val">
                        <?php if (count($result->passengers) === 1): ?>
                            <?= Html::e($result->passengers[0]->name) ?>
                        <?php else: ?>
                            <ol class="pax-list<?= count($result->passengers) > 4 ? ' pax-list--cols' : '' ?>">
                                <?php foreach ($result->passengers as $pax): ?>
                                    <li><?= Html::e($pax->name) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($show('show_booking_reference') && $result->recordLocator !== null): ?>
                    <div class="pax-item">
                        <span class="pax-key">Booking ref</span>
                        <span class="pax-val"><span class="ref-code"><?= Html::e($result->recordLocator) ?></span></span>
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
                    $legOrigin = $legFirst ? $portCity($legFirst->departureAirport) : '';
                    $legDest   = $legLast  ? $portCity($legLast->arrivalAirport)   : '';
                ?>
                <div class="table-scroll">
                    <table class="seg-table">
                        <thead>
                            <tr class="leg-head-row">
                                <th colspan="7" class="leg-head-cell">
                                    <span class="leg-head-title"><?php
                                        if ($legLabel !== null) {
                                            echo Html::e($legLabel) . ': ';
                                        }
                                        echo Html::e($legOrigin) . ' &rarr; ' . Html::e($legDest);
                                    ?></span>
                                    <?php if ($legDur): ?><span class="leg-head-dur"><?= Html::e($legDur) ?></span><?php endif; ?>
                                </th>
                            </tr>
                            <tr>
                                <th>Date</th>
                                <th>Flight</th>
                                <th>Carrier</th>
                                <th>Departs</th>
                                <th>Arrives</th>
                                <th>Duration</th>
                                <th>Layover</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($legSegs as $seg): ?>
                            <?php
                            /** @var Segment $seg */
                            $logo   = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                            $dur    = $flightDuration($seg);
                            $offset = $arrivalOffset($seg);
                            $dist   = Metadata::distanceLabel($seg->departureAirport, $seg->arrivalAirport, $distanceUnit);
                            $depMeta = Metadata::airport($seg->departureAirport);
                            $arrMeta = Metadata::airport($seg->arrivalAirport);
                            $depLabel = ($depMeta ? ($depMeta['name'] . ', ' . $depMeta['city']) : strtoupper($seg->departureAirport)) . ' (' . strtoupper($seg->departureAirport) . ')';
                            $arrLabel = ($arrMeta ? ($arrMeta['name'] . ', ' . $arrMeta['city']) : strtoupper($seg->arrivalAirport)) . ' (' . strtoupper($seg->arrivalAirport) . ')';
                            ?>
                            <tr>
                                <td class="tbl-date">
                                    <strong><?= Html::e($datePretty($seg->departureDate)) ?></strong>
                                </td>
                                <td>
                                    <span class="tbl-flight-num"><?= Html::e($seg->airlineCode . $seg->flightNumber) ?></span>
                                    <?php if ($show('show_booking_class') && $seg->bookingClass): ?>
                                        <br><span style="font-size:11px;color:#888"><?= Html::e($seg->bookingClass) ?></span>
                                    <?php endif; ?>
                                    <?php if ($show('show_cabin') && $seg->cabin): ?>
                                        <br><span style="font-size:11px;color:#888"><?= Html::e($seg->cabin) ?></span>
                                    <?php endif; ?>
                                    <?php if ($show('show_aircraft') && $seg->aircraft): ?>
                                        <br><span style="font-size:11px;color:#aaa"><?= Html::e($seg->aircraft) ?></span>
                                    <?php endif; ?>
                                    <?php if ($dist): ?>
                                        <br><span style="font-size:11px;color:#aaa"><?= Html::e($dist) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="tbl-carrier">
                                        <?php if ($logo): ?><?= $logoImg($logo, 'tbl-carrier-logo', $seg->airlineCode) ?><?php else: ?><span class="tbl-code"><?= Html::e($seg->airlineCode) ?></span><?php endif; ?>
                                        <?php if ($show('show_airline_name') && $seg->airlineName): ?>
                                            <span class="tbl-carrier-name"><?= Html::e($seg->airlineName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($show('show_operated_by') && $seg->operatedBy): ?>
                                        <span class="tbl-opby">Operated by <?= Html::e($seg->operatedBy) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tbl-port">
                                    <span class="tbl-port-name"><?= Html::e($depLabel) ?></span>
                                    <span class="tbl-port-time"><?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?></span>
                                    <?php if ($show('show_terminal') && $seg->departureTerminal): ?>
                                        <span class="tbl-term">T<?= Html::e($seg->departureTerminal) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tbl-port">
                                    <span class="tbl-port-name"><?= Html::e($arrLabel) ?></span>
                                    <span class="tbl-port-time"><?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?><?php if ($offset > 0): ?><span class="day-badge">+<?= $offset ?>d</span><?php endif; ?></span>
                                </td>
                                <td class="tbl-dur"><?= $dur ? Html::e($dur) : '&mdash;' ?></td>
                                <td>
                                    <?php if ($show('show_transit_time') && $seg->layoverDuration): ?>
                                        <?php [$loClass, $loLabel] = $layoverMeta($seg->layoverDuration); $visa = $transitVisa($seg->arrivalAirport); ?>
                                        <span class="tbl-layover <?= Html::e($loClass) ?>"><?= Html::e($seg->layoverDuration) ?></span>
                                        <?php if ($visa): ?><br><span class="visa-flag" style="font-size:10px"><?= Html::e($visa) ?></span><?php endif; ?>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
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
                                            <?php if ($show('show_operated_by') && $seg->operatedBy): ?><span>Operated by <?= Html::e($seg->operatedBy) ?></span><?php endif; ?>
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

            <!-- ── Must Read section ─────────────── -->
            <?php if ($show('show_must_read')):
                $mustReadDefault = implode("\n", [
                    '• Reconfirm your flight 24–48 hours before departure directly with the airline.',
                    '• Check-in at least 3 hours before international and 2 hours before domestic flights.',
                    '• Carry original travel documents — passport, visa, and printed/digital itinerary.',
                    '• Verify transit visa requirements for all stopover countries before travel.',
                    '• Baggage allowances vary by airline and fare class — excess baggage charges apply at check-in.',
                    '• Roaming Nepal is not liable for flight delays, cancellations, or schedule changes by airlines.',
                    '• For any assistance, contact us at the numbers listed below.',
                ]);
                $mustReadText = (string) ($settings['must_read_text'] ?? $mustReadDefault);
                $mustReadLines = array_filter(array_map('trim', explode("\n", $mustReadText)));
            ?>
                <div class="card-must-read">
                    <div class="must-read-title">&#9888; MUST READ</div>
                    <ul class="must-read-list">
                        <?php foreach ($mustReadLines as $mrl): ?>
                            <li><?= Html::e(ltrim($mrl, '•- ')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- ── Card footer ────────────────────── -->
            <?php if ($showDisclaimer || $showAgencyFooter):
                // Default footer data (overridden by config/settings.php on server)
                $footerCfg  = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
                $headOffice = is_array($footerCfg['head_office'] ?? null) ? $footerCfg['head_office'] : [
                    'title' => 'ROAMING NEPAL TRAVEL & TOURS PVT. LTD.',
                    'lines' => [
                        'Gairidhara-02, Nil Saraswoti Marg, Kathmandu, Nepal',
                        'P: +(977) 015905391 / 015905392',
                        'M: +(977) 9851075316 · 9841093086',
                        'W: www.roamingnepal.com',
                    ],
                ];
                $branches = is_array($footerCfg['branches'] ?? null) ? $footerCfg['branches'] : [
                    ['title' => 'POKHARA', 'lines' => ['Lakeside (Khahare), Kaski', '+977-61-591401 / 591402']],
                    ['title' => 'AUSTRALIA', 'lines' => ['15 Crossing Rd, Mernda VIC 3754', '+(61) 0452055393']],
                ];
            ?>
                <div class="card-footer">
                    <?php if ($showDisclaimer): ?>
                        <p class="disclaimer"><?= Html::e((string) ($settings['default_disclaimer'] ?? 'Please verify final schedule, terminal and check-in details with the airline before travel.')) ?></p>
                    <?php endif; ?>
                    <?php if ($showAgencyFooter): ?>
                        <div class="footer-compact">
                            <?php if (!empty($headOffice)): ?>
                                <div class="fc-main">
                                    <?php if (!empty($headOffice['title'])): ?>
                                        <div class="fc-name"><?= Html::e((string) $headOffice['title']) ?></div>
                                    <?php endif; ?>
                                    <div class="fc-lines">
                                        <?php foreach ((array) ($headOffice['lines'] ?? []) as $ln): ?>
                                            <span><?= Html::e(ltrim((string) $ln, '#')) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($branches)): ?>
                                <div class="fc-branches">
                                    <?php foreach ($branches as $branch): ?>
                                        <?php if (!is_array($branch)) continue; ?>
                                        <div class="fc-branch">
                                            <?php if (!empty($branch['title'])): ?><strong><?= Html::e((string) $branch['title']) ?></strong><?php endif; ?>
                                            <?php foreach ((array) ($branch['lines'] ?? []) as $ln): ?>
                                                <span><?= Html::e(ltrim((string) $ln, '#')) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($settings['footer_note'])): ?>
                            <p class="footer-note"><?= Html::e((string) $settings['footer_note']) ?></p>
                        <?php endif; ?>
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
