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
$resultFormat = (string) ($features['result_format'] ?? 'detailed');
if (!in_array($resultFormat, ['detailed', 'compact', 'table', 'whatsapp', 'two_lines', 'two_lines_reordered', 'three_lines', 'three_lines_reordered'], true)) {
    $resultFormat = 'detailed';
}
$use24HourTime = array_key_exists('use_12_hour_clock', $features)
    ? !(bool) $features['use_12_hour_clock']
    : (bool) ($features['use_24_hour_time'] ?? true);

$featureDefaults = [
    'show_airline_logo'      => true,
    'show_airline_name'      => true,
    'show_flight_duration'   => true,
    'show_transit_time'      => true,
    'show_operated_by'       => true,
    'show_cabin'             => false,   // off by default — enable per preference
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
    'show_co2'               => true,
];

$show             = static fn (string $key): bool
    => (bool) ($features[$key] ?? $featureDefaults[$key] ?? false);
$showAgencyHeader = $show('show_agency_header');
$showAgencyFooter = $show('show_agency_footer');
$showDisclaimer   = $show('show_disclaimer');

$airlineLogo = static function (string $code) use ($projectRoot, $asset): array {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
    if ($code === '') return ['src' => '', 'local' => false];
    foreach (['png', 'svg', 'webp'] as $ext) {
        $p = 'assets/images/airlines/' . $code . '.' . $ext;
        if (is_file($projectRoot . '/' . $p)) return ['src' => $asset($p), 'local' => true];
    }
    // No CDN fallback — only show verified local logos to prevent random/wrong images
    return ['src' => '', 'local' => false];
};

// Badge class mapping by image class prefix
$logoImg = static function (array $logo, string $cls, string $alt): string {
    if ($logo['src'] === '') return '';
    // On error: hide image and reveal the fallback text badge beside it
    $err = ' onerror="this.style.display=\'none\';var n=this.nextSibling;if(n)n.style.display=\'inline-flex\';"';
    // Choose badge class to match context (fb-logo, ref-logo-img, cpt-logo)
    if (str_starts_with($cls, 'fb-')) {
        $badge = '<span class="fb-code-badge" style="display:none">' . htmlspecialchars($alt, ENT_QUOTES) . '</span>';
    } elseif (str_starts_with($cls, 'ref-')) {
        $badge = '<span class="ref-code-badge" style="display:none">' . htmlspecialchars($alt, ENT_QUOTES) . '</span>';
    } else {
        $badge = '<span class="cpt-code" style="display:none">' . htmlspecialchars($alt, ENT_QUOTES) . '</span>';
    }
    return '<img src="' . htmlspecialchars($logo['src'], ENT_QUOTES) . '"'
        . $err . ' class="' . $cls . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" loading="lazy">'
        . $badge;
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
                    <button type="button" class="sidebar-collapse-btn" id="collapseInputBtn" title="Minimize — show only the itinerary">
                        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="3" width="14" height="12" rx="2"/><line x1="7" y1="3" x2="7" y2="15"/><path d="M12.5 7.5L10.5 9l2 1.5" stroke-width="1.5"/></svg>
                    </button>
                </div>

                <!-- PNR History — JS-rendered, hidden until entries exist -->
                <div id="historyPanel" style="display:none"></div>

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
                            <span class="gds-detect-chip" id="gdsDetectChip" style="display:none"><span class="gds-dot"></span><span id="gdsDetectLabel">Detecting…</span></span>
                        </div>
                        <div class="convert-btns">
                            <button type="submit" class="btn btn-convert">✈ Convert</button>
                            <button type="reset" id="resetBtn" class="btn btn-ghost">Clear</button>
                        </div>
                        <div class="kbd-hint">
                            <span class="kbd">Ctrl</span><span style="color:#334155">+</span><span class="kbd">↵</span>
                            <span style="margin-left:4px">to convert instantly</span>
                        </div>
                    </div>
                </div>

                <!-- ── Format · Agency · Options ─ -->
                <?php
                $optCard = static function (string $key, string $label) use ($show): string {
                    ob_start(); ?>
                    <label class="opt">
                        <span class="opt-top"><span class="opt-lbl"><?= Html::e($label) ?></span></span>
                        <span class="opt-sw-wrap">
                            <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                            <input type="checkbox" class="opt-sw-input" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                            <span class="opt-sw" aria-hidden="true"></span>
                        </span>
                    </label>
                    <?php return (string) ob_get_clean();
                };
                ?>
                <div class="ctrl-bar settings-panel no-share">
                    <div class="cb-row1">
                        <div class="cb-sect">
                            <span class="cb-sect-lbl">Format</span>
                            <div class="fmt-pills" role="radiogroup">
                                <?php foreach ([
                                    'table'       => 'Table',
                                    'three_lines' => '3 Lines',
                                    'two_lines'   => '2 Lines',
                                    'compact'     => 'Compact',
                                    'detailed'    => 'Graphic',
                                ] as $val => $lbl): ?>
                                    <label class="fpill<?= $resultFormat === $val ? ' is-active' : '' ?>">
                                        <input type="radio" name="result_format" value="<?= Html::e($val) ?>"<?= Html::checked($resultFormat === $val) ?>>
                                        <span><?= Html::e($lbl) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <span class="cb-div"></span>
                        <div class="cb-sect">
                            <span class="cb-sect-lbl">Agency</span>
                            <div class="cb-presets">
                                <button type="button" class="tpill is-active" data-preset="roaming" title="Enable Roaming Nepal header &amp; footer">Roaming Nepal</button>
                                <button type="button" class="tpill" data-preset="neutral" title="Hide agency branding — clean neutral look">Neutral</button>
                            </div>
                        </div>
                        <button type="button" class="cb-opts-toggle" id="cbOptsToggle" aria-expanded="<?= $renderable ? 'false' : 'true' ?>" aria-controls="cbOptsDrw">
                            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="7" cy="4" r="1.2" fill="currentColor" stroke="none"/><circle cx="7" cy="7" r="1.2" fill="currentColor" stroke="none"/><circle cx="7" cy="10" r="1.2" fill="currentColor" stroke="none"/></svg>
                            <span class="cb-opts-toggle-lbl">Options</span>
                        </button>
                    </div>
                    <div class="cb-opts" id="cbOptsDrw"<?= $renderable ? ' hidden' : '' ?>>
                        <div class="opt-grid">
                            <div class="opt opt-multi">
                                <span class="opt-top"><span class="opt-lbl">Distance</span></span>
                                <span class="opt-mini" role="radiogroup">
                                    <?php foreach (['off' => 'Off', 'km' => 'KM', 'miles' => 'Mi'] as $dv => $dl): ?>
                                        <label class="opt-mini-pill<?= ($features['distance_unit'] ?? 'off') === $dv ? ' is-active' : '' ?>">
                                            <input type="radio" name="distance_unit" value="<?= Html::e($dv) ?>"<?= Html::checked(($features['distance_unit'] ?? 'off') === $dv) ?>>
                                            <span><?= Html::e($dl) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </span>
                            </div>
                            <?php
                            foreach ([
                                'show_airline_logo'      => 'Logos',
                                'show_airline_name'      => 'Airline Name',
                                'show_flight_duration'   => 'Duration',
                                'show_transit_time'      => 'Layover',
                                'show_co2'               => 'CO₂ Estimate',
                                'use_12_hour_clock'      => '12hr Clock',
                                'show_cabin'             => 'Cabin',
                                'show_booking_class'     => 'Class',
                                'show_operated_by'       => 'Operated By',
                                'show_terminal'          => 'Terminal',
                                'show_aircraft'          => 'Aircraft',
                                'show_booking_reference' => 'Booking Ref',
                                'show_ticket_numbers'    => 'Ticket No',
                                'show_seat_numbers'      => 'Seat No',
                                'show_agency_header'     => 'Agency Header',
                                'show_agency_footer'     => 'Footer',
                                'show_disclaimer'        => 'Disclaimer',
                                'show_must_read'         => 'Must Read',
                            ] as $k => $lbl) {
                                echo $optCard($k, $lbl);
                            }
                            ?>
                        </div>
                    </div>
                </div><!-- /ctrl-bar -->

                <?php if ($renderable): ?>
                <!-- Primary export actions -->
                <div class="sidebar-actions">
                    <button type="button" class="btn btn-export sidebar-btn" id="copyImageBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="2" y="2" width="16" height="16" rx="2"/><circle cx="7" cy="7" r="1.5" fill="currentColor" stroke="none"/><path d="M2 13l5-5 4 4 2-2 5 5"/></svg>
                        Copy Image
                    </button>
                    <button type="button" class="btn btn-export sidebar-btn" id="copyTextBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="12" height="14" rx="1.5"/><path d="M7 7h5M7 10h5M7 13h3"/><path d="M6 1.5h9a1.5 1.5 0 011.5 1.5v13"/></svg>
                        Copy Text
                    </button>
                    <button type="button" class="btn btn-export sidebar-btn" id="downloadPngBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 3v9M6 8l4 4 4-4M4 14v2a1 1 0 001 1h10a1 1 0 001-1v-2"/></svg>
                        Save PNG
                    </button>
                    <button type="button" class="btn btn-export sidebar-btn" id="printBtn">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 7V3h10v4M5 13H3a1 1 0 01-1-1V8a1 1 0 011-1h14a1 1 0 011 1v4a1 1 0 01-1 1h-2M5 11h10v6H5v-6z"/></svg>
                        Print / PDF
                    </button>
                </div>
                <!-- Plain-text version for Copy Text button -->
                <div id="pnrPlainText" hidden aria-hidden="true"><?= htmlspecialchars($buildTextVersion(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>

            </div><!-- /app-sidebar -->

            <!-- Floating dock — visible only when minimized (clean screenshot view) -->
            <div class="min-dock no-print no-share" id="minDock" aria-hidden="true">
                <button type="button" class="min-dock-btn min-dock-restore" id="expandInputBtn" title="Show input &amp; options">
                    <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="3" width="14" height="12" rx="2"/><line x1="7" y1="3" x2="7" y2="15"/><path d="M10.5 7.5L12.5 9l-2 1.5" stroke-width="1.5"/></svg>
                    <span>Show panels</span>
                </button>
                <span class="min-dock-sep"></span>
                <button type="button" class="min-dock-btn" id="copyImageBtnDock" title="Copy image to clipboard" aria-label="Copy image">
                    <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="2" width="14" height="14" rx="2"/><circle cx="6" cy="6" r="1.3" fill="currentColor" stroke="none"/><path d="M2 12l4-4 3.5 3.5 2-2 4.5 4.5"/></svg>
                </button>
                <button type="button" class="min-dock-btn" id="downloadPngBtnDock" title="Save as PNG" aria-label="Save PNG">
                    <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 2.5v8M5.5 7L9 10.5 12.5 7M3.5 13v1.5a1 1 0 001 1h9a1 1 0 001-1V13"/></svg>
                </button>
                <button type="button" class="min-dock-btn" id="printBtnDock" title="Print or save PDF" aria-label="Print">
                    <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 6V2.5h8V6M5 12.5H3.5a1 1 0 01-1-1V8a1 1 0 011-1h11a1 1 0 011 1v3.5a1 1 0 01-1 1H13M5 10h8v5.5H5V10z"/></svg>
                </button>
            </div>

            <!-- ══════════════════════════════════════
                 MAIN — Options strip + Result
            ══════════════════════════════════════ -->
            <div class="app-main">

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
        <article class="itin-card" id="itineraryCard" data-route="<?= Html::e($itinTitle($legs)) ?>" data-flights="<?= Html::e((string) count($result->segments)) ?>">

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
                    <img src="<?= Html::e($asset('assets/images/roaming-nepal-logo.png')) ?>" alt="<?= Html::e($agencyName) ?>" class="agency-logo">
                    <div class="agency-offices">
                        <?php foreach ($agencyOffices as $office): ?>
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
                <h2 class="card-title"><span class="ct-label">Flight Itinerary:</span> <?= Html::e($itinTitle($legs)) ?></h2>
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
                <!-- ── TABLE FORMAT (reference-style) ── -->
                <?php foreach ($legs as $leg):
                    /** @var array{label:?string,segments:Segment[]} $leg */
                    $legSegs   = $leg['segments'];
                    $legFirst  = $legSegs[0] ?? null;
                    $legLast   = $legSegs[count($legSegs) - 1] ?? null;
                    $legDur    = $legDuration($legSegs);
                    $legOrigin = $legFirst ? $portCity($legFirst->departureAirport) : '';
                    $legDest   = $legLast  ? $portCity($legLast->arrivalAirport)   : '';
                    $legLabel  = $leg['label'];

                    // Build cols list based on toggles
                    $showOpBy   = $show('show_operated_by');
                    $showDist   = $distanceUnit !== 'off';
                    $showTerm   = $show('show_terminal');
                    $showAcft   = $show('show_aircraft');
                    $showCabin  = $show('show_cabin');
                    $showClass  = $show('show_booking_class');
                    $colCount   = 9 + ($showOpBy ? 1 : 0) + ($show('show_transit_time') ? 1 : 0);
                ?>
                <div class="ref-table-wrap">
                    <!-- Leg label strip -->
                    <div class="ref-leg-strip">
                        <span class="ref-leg-route">
                            <?php if ($legLabel): echo Html::e($legLabel) . ' · '; endif; ?>
                            <?= Html::e($legOrigin) ?> <span class="ref-leg-arrow">→</span> <?= Html::e($legDest) ?>
                        </span>
                        <?php if ($legDur): ?><span class="ref-leg-dur"><?= Html::e($legDur) ?></span><?php endif; ?>
                    </div>

                    <div class="ref-table-scroll">
                    <table class="ref-table">
                        <thead>
                            <tr>
                                <th class="ref-th ref-th-logo"></th>
                                <th class="ref-th ref-th-date">Date</th>
                                <th class="ref-th ref-th-airline">Airline</th>
                                <th class="ref-th ref-th-num">Flight<br>No</th>
                                <?php if ($showOpBy): ?><th class="ref-th ref-th-opby">Operated<br>By</th><?php endif; ?>
                                <th class="ref-th ref-th-time">Depart</th>
                                <th class="ref-th ref-th-port">From</th>
                                <th class="ref-th ref-th-time">Arrive</th>
                                <th class="ref-th ref-th-port">At</th>
                                <th class="ref-th ref-th-num">Duration</th>
                                <?php if ($show('show_transit_time')): ?><th class="ref-th ref-th-num">Transit</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($legSegs as $seg): ?>
                            <?php
                            /** @var Segment $seg */
                            $logo    = $show('show_airline_logo') ? $airlineLogo($seg->airlineCode) : null;
                            $dur     = $flightDuration($seg);
                            $offset  = $arrivalOffset($seg);
                            $dist    = Metadata::distanceLabel($seg->departureAirport, $seg->arrivalAirport, $distanceUnit);
                            $depMeta = Metadata::airport($seg->departureAirport);
                            $arrMeta = Metadata::airport($seg->arrivalAirport);
                            $depCity = $depMeta['city'] ?? strtoupper($seg->departureAirport);
                            $arrCity = $arrMeta['city'] ?? strtoupper($seg->arrivalAirport);
                            $depPortLabel = ($depMeta['name'] ?? strtoupper($seg->departureAirport));
                            $arrPortLabel = ($arrMeta['name'] ?? strtoupper($seg->arrivalAirport));
                            // Arrival date label (next day etc.)
                            $arrDateNote = '';
                            if ($offset > 0 && $seg->arrivalDate) {
                                $arrDateNote = '(on the ' . $datePretty($seg->arrivalDate) . ')';
                            }
                            ?>
                            <tr class="ref-tr">
                                <!-- Logo -->
                                <td class="ref-td ref-td-logo">
                                    <?php if ($logo): ?>
                                        <?= $logoImg($logo, 'ref-logo-img', $seg->airlineCode) ?>
                                    <?php else: ?>
                                        <span class="ref-code-badge"><?= Html::e($seg->airlineCode) ?></span>
                                    <?php endif; ?>
                                </td>
                                <!-- Date -->
                                <td class="ref-td ref-td-date">
                                    <?= Html::e($datePretty($seg->departureDate)) ?>
                                </td>
                                <!-- Airline -->
                                <td class="ref-td ref-td-airline" title="<?= Html::e($seg->airlineName ?? $seg->airlineCode) ?>">
                                    <span class="ref-airline-name"><?php if ($show('show_airline_name') && $seg->airlineName): ?><?= Html::e($seg->airlineName) ?><?php else: ?><?= Html::e($seg->airlineCode) ?><?php endif; ?></span>
                                    <?php if ($showCabin && $seg->cabin): ?>
                                        <span class="ref-sub"><?= Html::e($seg->cabin) ?></span>
                                    <?php elseif ($showClass && $seg->bookingClass): ?>
                                        <span class="ref-sub">Class <?= Html::e($seg->bookingClass) ?></span>
                                    <?php endif; ?>
                                    <?php if ($showAcft && $seg->aircraft): ?>
                                        <span class="ref-sub"><?= Html::e($seg->aircraft) ?></span>
                                    <?php endif; ?>
                                </td>
                                <!-- Flight No -->
                                <td class="ref-td ref-td-num">
                                    <strong><?= Html::e($seg->flightNumber) ?></strong>
                                </td>
                                <!-- Operated By (optional) -->
                                <?php if ($showOpBy): ?>
                                <td class="ref-td ref-td-opby" title="<?= Html::e($seg->operatedBy ?: ($seg->airlineName ?: $seg->airlineCode)) ?>">
                                    <span class="ref-opby-name"><?= Html::e($seg->operatedBy ?: ($seg->airlineName ?: $seg->airlineCode)) ?></span>
                                </td>
                                <?php endif; ?>
                                <!-- Depart time -->
                                <td class="ref-td ref-td-time">
                                    <?= Html::e($formatTime($seg->departureTime, $use24HourTime)) ?>
                                    <?php if ($showTerm && $seg->departureTerminal): ?>
                                        <span class="ref-sub">T<?= Html::e($seg->departureTerminal) ?></span>
                                    <?php endif; ?>
                                </td>
                                <!-- From -->
                                <td class="ref-td ref-td-port">
                                    <strong class="ref-port-code"><?= Html::e(strtoupper($seg->departureAirport)) ?></strong>
                                    <span class="ref-port-name"><?= Html::e($depCity) ?></span>
                                    <?php if ($dist): ?><span class="ref-sub"><?= Html::e($dist) ?></span><?php endif; ?>
                                </td>
                                <!-- Arrive time -->
                                <td class="ref-td ref-td-time">
                                    <?= Html::e($formatTime($seg->arrivalTime, $use24HourTime)) ?>
                                    <?php if ($arrDateNote): ?>
                                        <span class="ref-nextday"><?= Html::e($arrDateNote) ?></span>
                                    <?php endif; ?>
                                </td>
                                <!-- At -->
                                <td class="ref-td ref-td-port">
                                    <strong class="ref-port-code"><?= Html::e(strtoupper($seg->arrivalAirport)) ?></strong>
                                    <span class="ref-port-name"><?= Html::e($arrCity) ?></span>
                                </td>
                                <!-- Duration -->
                                <td class="ref-td ref-td-num">
                                    <?= $dur ? Html::e($dur) : '&mdash;' ?>
                                </td>
                                <!-- Transit / Layover -->
                                <?php if ($show('show_transit_time')): ?>
                                <td class="ref-td ref-td-num">
                                    <?php if ($seg->layoverDuration):
                                        [$loClass, ] = $layoverMeta($seg->layoverDuration);
                                        $visa = $transitVisa($seg->arrivalAirport);
                                    ?>
                                        <span class="ref-transit <?= Html::e($loClass) ?>"><?= Html::e($seg->layoverDuration) ?></span>
                                        <?php if ($visa): ?><span class="ref-sub"><?= Html::e($visa) ?></span><?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
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
                                <strong><?= Html::e($seg->layoverDuration) ?></strong> <?= Html::e(strtolower($loLabel)) ?> at <?= Html::e($portCity($seg->arrivalAirport)) ?> (<?= Html::e($seg->arrivalAirport) ?>)<?php if ($visa): ?> &middot; <span class="visa-flag"><?= Html::e($visa) ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- ── CO2 estimate ──────────────────── -->
            <?php if ($show('show_co2')):
                $co2 = Metadata::co2Tonnes($result->segments);
            ?>
                <?php if ($co2 !== null): ?>
                    <div class="itin-co2"><span class="leaf">&#127793;</span> Estimated <?= Html::e(number_format($co2, 1)) ?> tonnes CO&#8322; &middot; economy</div>
                <?php endif; ?>
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

<div class="copy-toast" id="copyToast" aria-live="polite"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= Html::e($asset('assets/js/app.js')) ?>"></script>
</body>
</html>
