<?php
declare(strict_types=1);
// phpcs:disable Generic.Files.LineLength

use RoamingNepal\PnrConverter\Parser\Segment;
use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\Html;
use RoamingNepal\PnrConverter\Support\Metadata;

/** @var array $settings */
/** @var string $rawInput */
/** @var ?\RoamingNepal\PnrConverter\Parser\ParseResult $result */
/** @var ?bool $isDomestic */
/** @var string $fareBase */
/** @var string $fareFsc */
/** @var string $fareTax */
/** @var string $baggage */
/** @var bool $refundable */
/** @var bool $showFare */
/** @var string $creditError */
/** @var string $docReference */
/** @var string $verifyToken */
/** @var string $verifyUrl */
/** @var string $docIssuedAt */
/** @var int $agencyCreditBalance */

$basePath    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$projectRoot = dirname(__DIR__, 2);
$asset = static function (string $path) use ($basePath, $projectRoot): string {
    $rel = ltrim($path, '/');
    $url = ($basePath === '' ? '' : $basePath) . '/' . $rel;
    if (is_file($projectRoot . '/' . $rel)) $url .= '?v=' . filemtime($projectRoot . '/' . $rel);
    return $url;
};

$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');
$appVersion = (string) ($settings['app_version'] ?? '4.0.0');
$authUser   = Auth::user();
$authInitials = '?';
if ($authUser !== null) {
    $parts = preg_split('/\s+/', trim((string) $authUser['name']));
    $authInitials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
    if ($authInitials === '') $authInitials = strtoupper(substr((string) $authUser['email'], 0, 2));
}

$airlineLogo = static function (string $code) use ($projectRoot, $asset): array {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
    if ($code === '') return ['src' => '', 'local' => false];
    foreach (['png', 'svg', 'webp'] as $ext) {
        $p = 'assets/images/airlines/' . $code . '.' . $ext;
        if (is_file($projectRoot . '/' . $p)) return ['src' => $asset($p), 'local' => true];
    }
    return ['src' => '', 'local' => false];
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
$datePretty = static function (string $date) use ($gdsDateTime): string {
    $dt = $gdsDateTime($date, '00:00');
    return $dt !== null ? $dt->format('D, j M Y') : $date;
};
$formatTime = static function (string $time): string {
    if (preg_match('/^(\d{2}):(\d{2})$/', $time, $m) !== 1) return $time;
    return $m[1] . ':' . $m[2];
};
$boardingTime = static function (string $depTime): string {
    if (preg_match('/^(\d{2}):(\d{2})$/', $depTime, $m) !== 1) return $depTime;
    $minutes = ((int) $m[1]) * 60 + (int) $m[2] - 30;
    if ($minutes < 0) $minutes += 24 * 60;
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
};
$flightDuration = static function (Segment $seg) use ($gdsDateTime): ?string {
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime, $seg->departureAirport);
    $arr = $gdsDateTime($seg->arrivalDate ?: $seg->departureDate, $seg->arrivalTime, $seg->arrivalAirport);
    if ($dep === null || $arr === null) return null;
    if ($arr->getTimestamp() < $dep->getTimestamp()) $arr = $arr->modify('+1 day');
    $mins = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
    if ($mins <= 0 || $mins > 48 * 60) return null;
    return sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
};
$arrivalOffset = static function (Segment $seg) use ($gdsDateTime): int {
    if (!$seg->arrivalDate || $seg->arrivalDate === $seg->departureDate) return 0;
    $dep = $gdsDateTime($seg->departureDate, '00:00');
    $arr = $gdsDateTime($seg->arrivalDate, '00:00');
    if ($dep === null || $arr === null) return 0;
    $days = (int) round(($arr->getTimestamp() - $dep->getTimestamp()) / 86400);
    return max(0, min(2, $days));
};
$portCity = static function (string $code): string {
    $meta = Metadata::airport($code);
    return $meta['city'] ?? strtoupper($code);
};
$portName = static function (string $code): string {
    $meta = Metadata::airport($code);
    return $meta['name'] ?? strtoupper($code);
};

$renderable = $result !== null && $result->isRenderable();
$fBase = $fareBase !== '' ? (float) $fareBase : null;
$fFsc  = $fareFsc !== '' ? (float) $fareFsc : null;
$fTax  = $fareTax !== '' ? (float) $fareTax : null;
$fTotal = ($fBase ?? 0) + ($fFsc ?? 0) + ($fTax ?? 0);
$hasFareInput = $fBase !== null || $fFsc !== null || $fTax !== null;
$fmtNpr = static fn (float $n): string => 'NPR ' . number_format($n, 0);

// ── Domestic-ticket-only extras ─────────────────────────────────────
$paxCount = $renderable ? max(1, count($result->passengers)) : 1;
$legCount = $renderable ? max(1, count($result->segments)) : 1;
$perPaxFare = ($fBase ?? 0) + ($fFsc ?? 0) + ($fTax ?? 0);
$domesticGrandTotal = $perPaxFare * $legCount * $paxCount;
$sharedTicketNo = null;
if ($renderable) {
    foreach ($result->segments as $seg) {
        if ($seg->ticketNumber) { $sharedTicketNo = $seg->ticketNumber; break; }
    }
}
$isRoundTrip = $renderable && $isDomestic && count($result->segments) === 2
    && $result->segments[1]->departureAirport === $result->segments[0]->arrivalAirport
    && $result->segments[1]->arrivalAirport === $result->segments[0]->departureAirport;

// The 3-office grid reuses the same head-office/branches data already
// configured for the main itinerary footer — Kathmandu, Pokhara, Australia —
// rather than a second, easily-out-of-sync copy of the same addresses.
$footerCfg = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
$officeGrid = [];
if (isset($footerCfg['head_office']['lines'])) {
    $officeGrid[] = ['label' => 'Kathmandu', 'lines' => $footerCfg['head_office']['lines']];
}
foreach ((array) ($footerCfg['branches'] ?? []) as $branch) {
    $officeGrid[] = ['label' => (string) ($branch['title'] ?? ''), 'lines' => (array) ($branch['lines'] ?? [])];
}

// Rotating promo strip — 6 offers, shown 3 at a time, swapped client-side.
$promoOffers = [
    ['icon' => '👥', 'tag' => 'Save More', 'title' => 'Group Booking', 'body' => 'Up to 15% off for groups of 10+'],
    ['icon' => '🌏', 'tag' => 'Go Further', 'title' => 'International Flights', 'body' => 'Best fares to 50+ destinations'],
    ['icon' => '🏨', 'tag' => 'Bundle', 'title' => 'Hotel + Flight Package', 'body' => 'Bundle & save 20%'],
    ['icon' => '🛡️', 'tag' => 'Stay Covered', 'title' => 'Travel Insurance', 'body' => 'From NPR 500 only'],
    ['icon' => '🚌', 'tag' => 'Door to Door', 'title' => 'Airport Transfer', 'body' => 'KTM pickup from NPR 1,200'],
    ['icon' => '🎓', 'tag' => 'For Students', 'title' => 'Student Fares', 'body' => 'Up to 10% off'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Ticket — <?= Html::e($agencyName) ?> PNR Converter</title>
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/styles.css')) ?>">
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/eticket.css')) ?>">
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/print.css')) ?>" media="print">
</head>
<body>
<div class="rn-shell">

<!-- ══ NAV RAIL ══════════════════════════════════════════════════ -->
<nav class="rn-rail no-print no-share" aria-label="Site navigation">
    <div class="rail-logo"><a href="<?= Html::e($asset('index.php')) ?>"><span class="rail-logo-text">RN</span></a></div>
    <div class="rail-nav">
        <a class="rail-item" href="<?= Html::e($asset('index.php')) ?>" title="PNR Converter">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span class="rail-item-label">Convert</span>
        </a>
        <a class="rail-item" href="<?= Html::e($asset('visa-doc.php')) ?>" title="Visa Itinerary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <span class="rail-item-label">Visa Itinerary</span>
        </a>
        <a class="rail-item is-active" href="<?= Html::e($asset('eticket.php')) ?>" title="E-Ticket">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 10a2 2 0 100-4V5a1 1 0 011-1h16a1 1 0 011 1v1a2 2 0 100 4v0a2 2 0 100 4v1a1 1 0 01-1 1H4a1 1 0 01-1-1v-1a2 2 0 100-4z"/><line x1="10" y1="4" x2="10" y2="20" stroke-dasharray="2 2"/></svg>
            <span class="rail-item-label">E-Ticket</span>
        </a>
        <a class="rail-item" href="<?= Html::e($asset('account.php')) ?>" title="Account">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span class="rail-item-label">Account</span>
        </a>
        <?php if ($authUser !== null && $authUser['role'] === 'superadmin'): ?>
        <a class="rail-item" href="<?= Html::e($asset('admin.php')) ?>" title="Admin">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span class="rail-item-label">Admin</span>
        </a>
        <?php endif; ?>
    </div>
    <div class="rail-footer">
        <span class="rail-version">v<?= Html::e($appVersion) ?></span>
        <?php if ($authUser !== null): ?>
        <a class="rail-avatar" href="<?= Html::e($asset('account.php')) ?>" title="<?= Html::e((string) $authUser['name']) ?>"><?= Html::e($authInitials) ?></a>
        <a class="rail-logout" href="<?= Html::e($asset('logout.php')) ?>" title="Log out">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
        <?php endif; ?>
    </div>
</nav>

<!-- ══ WORKSPACE ══════════════════════════════════════════════════ -->
<div class="rn-workspace">
<main class="rn-page">
<form method="post" id="eticketForm" autocomplete="off" class="et-layout">

    <!-- ══════════════════════════════════════
         FORM PANEL
    ══════════════════════════════════════ -->
    <div class="et-form-panel no-print">
        <div class="et-form-hd">
            <span class="et-form-hd-title">E-Ticket</span>
            <span class="et-form-hd-sub">Client-ready ticket document</span>
        </div>

        <div class="et-form-body">
            <label class="et-label" for="pnr_text">Paste GDS / booking text</label>
            <textarea id="pnr_text" name="pnr_text" class="et-textarea" rows="10" spellcheck="false"
                placeholder="Paste the GDS PNR here — Amadeus, Galileo, Sabre, Worldspan, Smartpoint..."><?= Html::e($rawInput) ?></textarea>

            <?php if ($renderable): ?>
            <div class="et-route-chip">
                <span class="et-route-badge <?= $isDomestic ? 'is-domestic' : 'is-intl' ?>"><?= $isDomestic ? 'Domestic' : 'International' ?></span>
                <span><?= Html::e((string) count($result->segments)) ?> flight<?= count($result->segments) === 1 ? '' : 's' ?></span>
            </div>
            <?php endif; ?>

            <div class="et-fieldset">
                <span class="et-label">Fare (optional)</span>
                <div class="et-fare-grid">
                    <div>
                        <label class="et-sublabel" for="fare_base">Base fare</label>
                        <input class="et-input" type="number" min="0" step="1" id="fare_base" name="fare_base" value="<?= Html::e($fareBase) ?>" placeholder="0">
                    </div>
                    <div>
                        <label class="et-sublabel" for="fare_fsc">Fuel surcharge</label>
                        <input class="et-input" type="number" min="0" step="1" id="fare_fsc" name="fare_fsc" value="<?= Html::e($fareFsc) ?>" placeholder="0">
                    </div>
                    <div>
                        <label class="et-sublabel" for="fare_tax">Tax &amp; fees</label>
                        <input class="et-input" type="number" min="0" step="1" id="fare_tax" name="fare_tax" value="<?= Html::e($fareTax) ?>" placeholder="0">
                    </div>
                </div>
            </div>

            <?php if ($isDomestic === false || $isDomestic === null): ?>
            <label class="et-toggle-row">
                <input type="hidden" name="show_fare" value="0">
                <input type="checkbox" name="show_fare" value="1"<?= Html::checked($showFare) ?>>
                <span>Show fare on ticket <span class="et-toggle-note">(international tickets hide fare by default)</span></span>
            </label>
            <?php endif; ?>

            <?php if ($isDomestic === true): ?>
            <div class="et-fieldset">
                <label class="et-sublabel" for="baggage">Free baggage allowance</label>
                <input class="et-input" type="text" id="baggage" name="baggage" value="<?= Html::e($baggage) ?>" placeholder="15 KG + 5 KG">
            </div>
            <div class="et-fieldset">
                <span class="et-label">Fare type</span>
                <div class="et-segmented">
                    <label class="et-segmented-opt<?= $refundable ? ' is-active' : '' ?>">
                        <input type="radio" name="refundable" value="1"<?= Html::checked($refundable) ?>> Refundable
                    </label>
                    <label class="et-segmented-opt<?= !$refundable ? ' is-active' : '' ?>">
                        <input type="radio" name="refundable" value="0"<?= Html::checked(!$refundable) ?>> Non-Refundable
                    </label>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="et-form-ft">
            <button type="submit" class="et-generate-btn">🎫 Generate Ticket</button>
            <div class="et-cost-line">
                <?php if ($isDomestic === true): ?>
                    Free for domestic tickets
                <?php else: ?>
                    Costs <strong>1 credit</strong> for international · <?= Html::e((string) $agencyCreditBalance) ?> remaining
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         DOCUMENT PREVIEW
    ══════════════════════════════════════ -->
    <div class="et-preview-pane">
        <div class="et-preview-toolbar no-print">
            <span>Document Preview</span>
            <?php if ($renderable): ?>
            <div class="et-preview-actions">
                <button type="button" class="et-tbtn" id="etPrintBtn">Print / PDF</button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($creditError !== ''): ?>
            <div class="et-alert"><?= Html::e($creditError) ?> <a href="<?= Html::e($asset('account.php')) ?>">View balance &amp; top up</a></div>
        <?php endif; ?>

        <?php if (!$renderable): ?>
            <div class="et-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M3 10a2 2 0 100-4V5a1 1 0 011-1h16a1 1 0 011 1v1a2 2 0 100 4v0a2 2 0 100 4v1a1 1 0 01-1 1H4a1 1 0 01-1-1v-1a2 2 0 100-4z"/></svg>
                <p>Paste a GDS itinerary and click <strong>Generate Ticket</strong></p>
                <span class="et-empty-sub">Auto-detects Domestic vs. International and picks the right template</span>
            </div>
        <?php elseif ($isDomestic): ?>

        <?php
        $airlineBadgeColors = [
            'U4' => ['bg' => '#FFF8E1', 'text' => '#7B5800'],
            'YT' => ['bg' => '#E8F5E9', 'text' => '#1B5E20'],
            'TA' => ['bg' => '#FBE9E7', 'text' => '#C75000'],
            'SHA' => ['bg' => '#FFEBEE', 'text' => '#C62828'],
            'Q6' => ['bg' => '#E8EAF6', 'text' => '#283593'],
        ];
        $firstLeg = $result->segments[0];
        $badge = $airlineBadgeColors[strtoupper($firstLeg->airlineCode)] ?? ['bg' => 'var(--rn-navy-light)', 'text' => 'var(--rn-navy)'];
        $firstLegLogo = $airlineLogo($firstLeg->airlineCode);
        ?>
        <article class="det-doc" id="eticketDoc">
            <!-- HEADER -->
            <div class="det-header">
                <div class="det-header-left">
                    <?php if (is_file($projectRoot . '/assets/images/roaming-nepal-logo.png')): ?>
                    <div class="det-logo-pill"><img src="<?= Html::e($asset('assets/images/roaming-nepal-logo.png')) ?>" alt="<?= Html::e($agencyName) ?>"></div>
                    <div class="det-vdiv"></div>
                    <?php endif; ?>
                    <div>
                        <div class="det-eyebrow">Domestic Flight</div>
                        <div class="det-title">E-Ticket</div>
                    </div>
                </div>
                <div class="det-header-right">
                    <div class="det-eyebrow">PNR &middot; Booking Ref</div>
                    <div class="det-pnr"><?= Html::e($result->recordLocator ?? '—') ?></div>
                </div>
            </div>

            <!-- PROMO BANNER -->
            <div class="det-promo">
                <div class="det-promo-head">
                    <span class="det-promo-label">Roaming Nepal &mdash; Exclusive Offers</span>
                    <div class="det-promo-dots" id="detPromoDots">
                        <span class="det-dot is-active"></span><span class="det-dot"></span>
                    </div>
                </div>
                <div class="det-promo-grid" id="detPromoGrid" data-offers="<?= Html::e(json_encode($promoOffers)) ?>">
                    <?php foreach (array_slice($promoOffers, 0, 3) as $offer): ?>
                    <div class="det-offer">
                        <div class="det-offer-icon"><?= $offer['icon'] ?></div>
                        <span class="det-offer-tag"><?= Html::e($offer['tag']) ?></span>
                        <div class="det-offer-title"><?= Html::e($offer['title']) ?></div>
                        <div class="det-offer-body"><?= Html::e($offer['body']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- AIRLINE BAR (first leg's carrier) -->
            <div class="det-airline-bar">
                <div class="det-airline-left">
                    <div>
                        <span class="det-airline-microlabel">Operated By</span>
                        <span class="det-airline-op"><?= Html::e($firstLeg->airlineName ?? $firstLeg->airlineCode) ?></span>
                    </div>
                    <div>
                        <span class="det-airline-microlabel">Flight No.</span>
                        <span class="det-airline-flightno"><?= Html::e($firstLeg->airlineCode . ' ' . $firstLeg->flightNumber) ?></span>
                    </div>
                    <?php if ($firstLeg->aircraft): ?>
                    <div>
                        <span class="det-airline-microlabel">Aircraft</span>
                        <span class="det-airline-aircraft"><?= Html::e($firstLeg->aircraft) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="det-airline-right">
                    <span class="det-airline-code-badge" style="background:<?= Html::e($badge['bg']) ?>;color:<?= Html::e($badge['text']) ?>;border-color:<?= Html::e($badge['text']) ?>33;"><?= Html::e(strtoupper($firstLeg->airlineCode)) ?></span>
                    <?php if ($firstLegLogo['src'] !== ''): ?>
                        <img src="<?= Html::e($firstLegLogo['src']) ?>" alt="<?= Html::e($firstLeg->airlineCode) ?>" class="det-airline-logo">
                    <?php endif; ?>
                    <span class="det-refund-badge <?= $refundable ? 'is-refundable' : 'is-norefund' ?>"><?= $refundable ? 'Refundable' : 'Non-Refundable' ?></span>
                </div>
            </div>

            <div class="det-body">
                <!-- PASSENGERS -->
                <div class="det-section-label">Passenger(s)</div>
                <table class="det-pax-table">
                    <thead><tr><th>Name</th><th>Type</th><th>Nationality</th><th>E-Ticket No.</th><th>Free Baggage</th></tr></thead>
                    <tbody>
                        <?php if (count($result->passengers) > 0): ?>
                            <?php foreach ($result->passengers as $pax): ?>
                            <tr>
                                <td class="det-pax-name"><?= Html::e($pax->name) ?></td>
                                <td><?= Html::e($pax->type ?? 'Adult') ?></td>
                                <td>Nepali</td>
                                <td class="det-pax-tkno"><?= Html::e($sharedTicketNo ?? '—') ?></td>
                                <td class="det-pax-bag"><?= Html::e($baggage) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="det-pax-empty">No passenger name detected in the pasted text</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="det-perforation"><span></span><span></span></div>

                <!-- FLIGHT LEG(S) -->
                <?php foreach ($result->segments as $idx => $seg): ?>
                    <?php
                    /** @var Segment $seg */
                    $dur  = $flightDuration($seg);
                    $offset = $arrivalOffset($seg);
                    ?>
                    <?php if ($isRoundTrip && $idx === 1): ?>
                    <div class="det-return-divider">
                        <span></span><span class="det-return-label">Return Flight</span><span></span>
                    </div>
                    <?php elseif ($idx > 0 && $seg->layoverDuration !== null): ?>
                    <div class="det-return-divider">
                        <span></span>
                        <span class="det-return-label">Connecting via <?= Html::e($portCity($seg->departureAirport)) ?> (<?= Html::e(strtoupper($seg->departureAirport)) ?>) &middot; Layover <?= Html::e($seg->layoverDuration) ?></span>
                        <span></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($isRoundTrip): ?>
                        <span class="det-leg-badge"><?= $idx === 0 ? '&#8599; Outbound Flight' : '&#8601; Return Flight' ?></span>
                    <?php endif; ?>

                    <div class="det-route-row">
                        <div class="det-route-port">
                            <span class="det-route-label">From</span>
                            <div class="det-route-code"><?= Html::e(strtoupper($seg->departureAirport)) ?></div>
                            <div class="det-route-name"><?= Html::e($portName($seg->departureAirport)) ?></div>
                        </div>
                        <div class="det-route-mid">
                            <?php if ($dur): ?><div class="det-route-dur">Direct &middot; <?= Html::e($dur) ?></div><?php endif; ?>
                            <div class="det-route-line"><span class="det-route-plane">&#9992;</span></div>
                            <div class="det-route-date"><?= Html::e($datePretty($seg->departureDate)) ?></div>
                        </div>
                        <div class="det-route-port det-route-port-r">
                            <span class="det-route-label">To</span>
                            <div class="det-route-code"><?= Html::e(strtoupper($seg->arrivalAirport)) ?></div>
                            <div class="det-route-name"><?= Html::e($portName($seg->arrivalAirport)) ?></div>
                        </div>
                    </div>

                    <div class="det-detail-strip">
                        <div>
                            <span class="det-detail-label">Departure</span>
                            <div class="det-detail-val"><?= Html::e($formatTime($seg->departureTime)) ?></div>
                            <span class="det-detail-sub">Local time</span>
                        </div>
                        <div>
                            <span class="det-detail-label">Boarding</span>
                            <div class="det-detail-val"><?= Html::e($boardingTime($seg->departureTime)) ?></div>
                            <span class="det-detail-sub">30 min before</span>
                        </div>
                        <div>
                            <span class="det-detail-label">Arrival</span>
                            <div class="det-detail-val"><?= Html::e($formatTime($seg->arrivalTime)) ?><?php if ($offset > 0): ?><span class="det-plus-day">+<?= $offset ?></span><?php endif; ?></div>
                            <span class="det-detail-sub">Local time</span>
                        </div>
                        <div>
                            <span class="det-detail-label">Class</span>
                            <div class="det-detail-val det-detail-val-sm"><?= Html::e($seg->cabin ?? ($seg->bookingClass ?? '—')) ?></div>
                            <span class="det-detail-sub">Cabin class</span>
                        </div>
                        <div>
                            <span class="det-detail-label">Free Baggage</span>
                            <div class="det-detail-val det-detail-val-sm det-detail-bag"><?= Html::e($baggage) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($hasFareInput): ?>
                <div class="det-divider"></div>
                <div class="det-fare-section">
                    <div>
                        <div class="det-section-label">Fare Breakdown</div>
                        <?php if ($fBase !== null): ?><div class="det-fare-row"><span>Base Fare</span><span><?= Html::e($fmtNpr($fBase)) ?></span></div><?php endif; ?>
                        <?php if ($fFsc !== null): ?><div class="det-fare-row"><span>Fuel Surcharge (FSC)</span><span><?= Html::e($fmtNpr($fFsc)) ?></span></div><?php endif; ?>
                        <?php if ($fTax !== null): ?><div class="det-fare-row"><span>Tax &amp; Fees</span><span><?= Html::e($fmtNpr($fTax)) ?></span></div><?php endif; ?>
                        <div class="det-fare-row det-fare-subtotal"><span>Per Passenger</span><span><?= Html::e($fmtNpr($perPaxFare)) ?></span></div>
                        <div class="det-fare-legs-note"><?= Html::e((string) $legCount) ?> leg<?= $legCount === 1 ? '' : 's' ?> &times; <?= Html::e((string) $paxCount) ?> passenger<?= $paxCount === 1 ? '' : 's' ?></div>
                    </div>
                    <div class="det-total-card">
                        <span class="det-section-label">Grand Total</span>
                        <div class="det-total-amount"><?= Html::e($fmtNpr($domesticGrandTotal)) ?></div>
                        <div class="det-total-sub">All passengers &amp; legs included</div>
                        <div class="det-total-guarantee">&#9733; Best Price Guaranteed by Roaming Nepal</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="det-divider"></div>

                <!-- ISSUED BY + QR -->
                <div class="det-issued-row">
                    <div class="det-issued-info">
                        <div class="det-section-label">Issued By</div>
                        <div class="det-issued-name"><?= Html::e($agencyName) ?></div>
                        <div class="det-issued-meta">IATA Accredited Agency &middot; Issued: <?= Html::e($docIssuedAt) ?></div>
                        <?php if (count($officeGrid) > 0): ?>
                        <div class="det-office-grid">
                            <?php foreach ($officeGrid as $office): ?>
                            <div class="det-office-card">
                                <span class="det-office-label"><?= Html::e($office['label']) ?></span>
                                <?php foreach ($office['lines'] as $i => $line): ?>
                                <div class="det-office-line<?= $i > 0 ? ' is-muted' : '' ?>"><?= Html::e($line) ?></div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($verifyUrl !== ''): ?>
                    <div class="det-verify-box">
                        <span class="det-verify-label">Verify Booking</span>
                        <div class="det-qr" id="etQr"></div>
                        <?php if ($docReference !== ''): ?><div class="det-verify-ref"><?= Html::e($docReference) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- T&C -->
            <div class="det-tnc">
                <div class="det-section-label">Important Notices</div>
                <div class="det-tnc-grid">
                    <span class="det-tnc-item"><span class="det-bullet">&middot;</span>Passenger name must exactly match their valid government-issued ID.</span>
                    <span class="det-tnc-item"><span class="det-bullet">&middot;</span>Report any ticket errors to us within 1 hour of receiving it.</span>
                    <span class="det-tnc-item"><span class="det-bullet">&middot;</span>Re-confirm your flight at least 1 day before scheduled departure.</span>
                    <span class="det-tnc-item"><span class="det-bullet">&middot;</span>Arrive at the airport at least 1.5 hours before domestic departure.</span>
                    <span class="det-tnc-item"><span class="det-bullet">&middot;</span>Changes &amp; cancellations must be made &ge;4 hrs before departure, per airline terms.</span>
                    <span class="det-tnc-item"><span class="det-bullet">&middot;</span>Bank card surcharges are non-refundable. Call or message us anytime for help.</span>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="det-tagline-row">
                <div><span class="det-tagline">Your Trusted Travel Partner Since 2012</span><span class="det-tagline-site">roamingnepal.com</span></div>
                <div class="det-social" aria-hidden="true">
                    <span title="Facebook"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0022 12z"/></svg></span>
                    <span title="Instagram"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c2.7 0 3.1 0 4.1.1 1.1 0 1.8.2 2.4.5.6.2 1.1.6 1.6 1.1.5.5.8.9 1.1 1.6.2.6.4 1.3.5 2.4.1 1 .1 1.4.1 4.1s0 3.1-.1 4.1c0 1.1-.2 1.8-.5 2.4-.2.6-.6 1.1-1.1 1.6-.5.5-.9.8-1.6 1.1-.6.2-1.3.4-2.4.5-1 .1-1.4.1-4.1.1s-3.1 0-4.1-.1c-1.1 0-1.8-.2-2.4-.5-.6-.2-1.1-.6-1.6-1.1-.5-.5-.8-.9-1.1-1.6-.2-.6-.4-1.3-.5-2.4C2 15.1 2 14.7 2 12s0-3.1.1-4.1c0-1.1.2-1.8.5-2.4.2-.6.6-1.1 1.1-1.6.5-.5.9-.8 1.6-1.1.6-.2 1.3-.4 2.4-.5C8.9 2 9.3 2 12 2zm0 5a5 5 0 100 10 5 5 0 000-10zm0 8.2a3.2 3.2 0 110-6.4 3.2 3.2 0 010 6.4zm5.2-8.4a1.2 1.2 0 100-2.4 1.2 1.2 0 000 2.4z"/></svg></span>
                    <span title="WhatsApp"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 00-8.5 15.2L2 22l4.9-1.5A10 10 0 1012 2zm5.8 14.3c-.2.7-1.4 1.3-2 1.4-.5.1-1.1.2-3.6-.8-3-1.2-4.9-4.3-5.1-4.5-.1-.2-1.2-1.6-1.2-3s.7-2.1 1-2.4c.3-.3.6-.4.8-.4h.5c.2 0 .4 0 .6.5.2.5.7 1.8.8 1.9.1.2.1.3 0 .5-.1.2-.1.3-.3.5-.1.2-.3.4-.4.5-.1.1-.3.3-.1.6.2.3.9 1.4 1.9 2.3 1.3 1.2 2.4 1.5 2.7 1.7.3.2.5.1.7-.1.2-.2.8-.9 1-1.2.2-.3.4-.2.7-.1.3.1 1.7.8 2 1 .3.1.5.2.6.3.1.2.1.9-.1 1.6z"/></svg></span>
                </div>
            </div>
            <div class="det-copyright">
                <span>&copy; <?= date('Y') ?> <?= Html::e($agencyName) ?> &middot; IATA Accredited Agency</span>
                <span class="is-italic">This is NOT a VAT / Tax Invoice</span>
            </div>
        </article>

        <?php else: ?>

        <article class="et-doc" id="eticketDoc">
            <!-- HEADER -->
            <div class="et-doc-header">
                <div class="et-doc-brand">
                    <?php if (is_file($projectRoot . '/assets/images/roaming-nepal-logo.png')): ?>
                    <div class="et-doc-logo-tile"><img src="<?= Html::e($asset('assets/images/roaming-nepal-logo.png')) ?>" alt="<?= Html::e($agencyName) ?>"></div>
                    <?php endif; ?>
                    <div>
                        <div class="et-doc-eyebrow"><?= $isDomestic ? 'Domestic Flight' : 'International Flight' ?></div>
                        <div class="et-doc-title">E-Ticket</div>
                    </div>
                </div>
                <div class="et-doc-pnr-box">
                    <div class="et-doc-eyebrow">PNR &middot; Booking Ref</div>
                    <div class="et-doc-pnr"><?= Html::e($result->recordLocator ?? '—') ?></div>
                </div>
            </div>

            <!-- PASSENGERS -->
            <div class="et-doc-body">
                <div class="et-section-label">Passenger(s)</div>
                <table class="et-pax-table">
                    <thead><tr><th>Name</th><th>Type</th></tr></thead>
                    <tbody>
                        <?php if (count($result->passengers) > 0): ?>
                            <?php foreach ($result->passengers as $pax): ?>
                            <tr><td><?= Html::e($pax->name) ?></td><td><?= Html::e($pax->type ?? 'Adult') ?></td></tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="2" class="et-pax-empty">No passenger name detected in the pasted text</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="et-perforation"><span></span><span></span></div>

                <!-- ROUTE LEGS -->
                <?php $segCount = count($result->segments); ?>
                <?php foreach ($result->segments as $idx => $seg): ?>
                    <?php
                    /** @var Segment $seg */
                    $logo = $airlineLogo($seg->airlineCode);
                    $dur  = $flightDuration($seg);
                    $offset = $arrivalOffset($seg);
                    ?>
                    <?php if ($idx > 0 && $seg->layoverDuration !== null): ?>
                    <div class="et-connect-divider">
                        <span></span>
                        <span class="et-connect-label">Connecting via <?= Html::e($portCity($seg->departureAirport)) ?> (<?= Html::e(strtoupper($seg->departureAirport)) ?>) &middot; Layover <?= Html::e($seg->layoverDuration) ?></span>
                        <span></span>
                    </div>
                    <?php endif; ?>

                    <div class="et-leg">
                        <div class="et-leg-airline-bar">
                            <div class="et-leg-airline-info">
                                <div>
                                    <span class="et-micro">Operated By</span>
                                    <span class="et-leg-airline-name"><?= Html::e($seg->airlineName ?? $seg->airlineCode) ?></span>
                                </div>
                                <span class="et-vdiv"></span>
                                <div>
                                    <span class="et-micro">Flight No.</span>
                                    <span class="et-leg-flight-no"><?= Html::e($seg->airlineCode . ' ' . $seg->flightNumber) ?></span>
                                </div>
                                <?php if ($seg->aircraft): ?>
                                <span class="et-vdiv"></span>
                                <div>
                                    <span class="et-micro">Aircraft</span>
                                    <span class="et-leg-aircraft"><?= Html::e($seg->aircraft) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="et-leg-airline-right">
                                <?php if ($logo['src'] !== ''): ?>
                                    <img src="<?= Html::e($logo['src']) ?>" alt="<?= Html::e($seg->airlineCode) ?>" class="et-leg-logo">
                                <?php else: ?>
                                    <span class="et-leg-code-badge"><?= Html::e($seg->airlineCode) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="et-route-row">
                            <div class="et-route-port">
                                <span class="et-micro">From</span>
                                <div class="et-port-code"><?= Html::e(strtoupper($seg->departureAirport)) ?></div>
                                <div class="et-port-name"><?= Html::e($portCity($seg->departureAirport)) ?></div>
                            </div>
                            <div class="et-route-path">
                                <?php if ($dur): ?><div class="et-path-dur"><?= Html::e($dur) ?></div><?php endif; ?>
                                <div class="et-path-line"><span class="et-path-plane">&#9992;</span></div>
                                <div class="et-path-date"><?= Html::e($datePretty($seg->departureDate)) ?></div>
                            </div>
                            <div class="et-route-port et-route-port-r">
                                <span class="et-micro">To</span>
                                <div class="et-port-code"><?= Html::e(strtoupper($seg->arrivalAirport)) ?></div>
                                <div class="et-port-name"><?= Html::e($portCity($seg->arrivalAirport)) ?></div>
                            </div>
                        </div>

                        <div class="et-detail-strip <?= $seg->departureTerminal ? 'et-detail-6col' : '' ?>">
                            <div>
                                <span class="et-micro">Departure</span>
                                <div class="et-detail-val"><?= Html::e($formatTime($seg->departureTime)) ?></div>
                                <span class="et-detail-sub">Local time</span>
                            </div>
                            <div>
                                <span class="et-micro">Boarding</span>
                                <div class="et-detail-val"><?= Html::e($boardingTime($seg->departureTime)) ?></div>
                                <span class="et-detail-sub">30 min before</span>
                            </div>
                            <div>
                                <span class="et-micro">Arrival</span>
                                <div class="et-detail-val"><?= Html::e($formatTime($seg->arrivalTime)) ?><?php if ($offset > 0): ?><span class="et-plus-day">+<?= $offset ?></span><?php endif; ?></div>
                                <span class="et-detail-sub">Local time</span>
                            </div>
                            <div>
                                <span class="et-micro">Class</span>
                                <div class="et-detail-val et-detail-val-sm"><?= Html::e($seg->cabin ?? ($seg->bookingClass ?? '—')) ?></div>
                                <span class="et-detail-sub">Cabin class</span>
                            </div>
                            <?php if ($seg->departureTerminal): ?>
                            <div>
                                <span class="et-micro">Terminal</span>
                                <div class="et-detail-val et-detail-val-sm">T<?= Html::e($seg->departureTerminal) ?></div>
                                <span class="et-detail-sub">Departure</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($showFare && $hasFareInput): ?>
                <div class="et-divider"></div>
                <div class="et-fare-section">
                    <div>
                        <div class="et-section-label">Fare Details</div>
                        <div class="et-fare-rows">
                            <?php if ($fBase !== null): ?><div class="et-fare-row"><span>Base Fare</span><span><?= Html::e($fmtNpr($fBase)) ?></span></div><?php endif; ?>
                            <?php if ($fFsc !== null): ?><div class="et-fare-row"><span>Fuel Surcharge (FSC)</span><span><?= Html::e($fmtNpr($fFsc)) ?></span></div><?php endif; ?>
                            <?php if ($fTax !== null): ?><div class="et-fare-row"><span>Tax &amp; Fees</span><span><?= Html::e($fmtNpr($fTax)) ?></span></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="et-fare-total-box">
                        <span class="et-micro">Total</span>
                        <div class="et-fare-total"><?= Html::e($fmtNpr($fTotal)) ?></div>
                        <div class="et-fare-total-note">All entered charges included</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="et-divider"></div>

                <!-- ISSUED BY + VERIFY -->
                <div class="et-issued-row">
                    <div class="et-issued-info">
                        <div class="et-section-label">Issued By</div>
                        <div class="et-issued-name"><?= Html::e($agencyName) ?></div>
                        <div class="et-issued-meta">Issued: <?= Html::e($docIssuedAt) ?></div>
                    </div>
                    <?php if ($verifyUrl !== ''): ?>
                    <div class="et-verify-box">
                        <span class="et-micro">Verify Ticket</span>
                        <div class="et-qr" id="etQr"></div>
                        <?php if ($docReference !== ''): ?><div class="et-verify-ref"><?= Html::e($docReference) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- T&C STRIP -->
            <div class="et-tnc">
                <div class="et-section-label">Important Notices</div>
                <div class="et-tnc-grid">
                    <?php if ($isDomestic): ?>
                        <span>Passenger name must exactly match their valid government-issued ID.</span>
                        <span>Report any ticket errors to us within 1 hour of receiving it.</span>
                        <span>Re-confirm your flight at least 1 day before scheduled departure.</span>
                        <span>Arrive at the airport at least 1.5 hours before domestic departure.</span>
                        <span>Changes &amp; cancellations must be made per airline terms.</span>
                        <span>Bank card surcharges are non-refundable.</span>
                    <?php else: ?>
                        <span>Passport must be valid for at least 6 months beyond the return date.</span>
                        <span>Report any ticket errors to us within 1 hour of receiving it.</span>
                        <span>Re-confirm your flight at least 1 day before scheduled departure.</span>
                        <span>Arrive at the airport at least 3 hours before international departure.</span>
                        <span>Check transit visa requirements for connecting airports; missed connections due to visa issues are the passenger's responsibility.</span>
                        <span>Changes &amp; cancellations follow airline fare rules. Bank card surcharges are non-refundable.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="et-doc-footer">
                <span>Presented by <strong><?= Html::e($agencyName) ?></strong></span>
                <span class="et-doc-footer-note">This is NOT a VAT / Tax Invoice</span>
            </div>
        </article>

        <?php endif; ?>
    </div>
</form>
</main>
</div><!-- /rn-workspace -->
</div><!-- /rn-shell -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
  var qrEl = document.getElementById('etQr');
  <?php if ($verifyUrl !== ''): ?>
  if (qrEl && window.QRCode) {
    try {
      var qrSize = <?= $isDomestic ? 92 : 84 ?>;
      new window.QRCode(qrEl, { text: <?= json_encode($verifyUrl) ?>, width: qrSize, height: qrSize, colorDark: '#0F367B', colorLight: '#ffffff' });
    } catch (e) {}
  }
  <?php endif; ?>
  var printBtn = document.getElementById('etPrintBtn');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });

  // Fare-type segmented control: highlight the picked option immediately,
  // without waiting for the form to resubmit.
  document.querySelectorAll('input[name="refundable"]').forEach(function (input) {
    input.addEventListener('change', function () {
      document.querySelectorAll('.et-segmented-opt').forEach(function (opt) { opt.classList.remove('is-active'); });
      input.closest('.et-segmented-opt').classList.add('is-active');
    });
  });

  // Domestic promo banner: cycle the 6 offers 3-at-a-time, fading between sets.
  var promoGrid = document.getElementById('detPromoGrid');
  if (promoGrid) {
    var offers = [];
    try { offers = JSON.parse(promoGrid.dataset.offers || '[]'); } catch (e) {}
    var dots = document.querySelectorAll('#detPromoDots .det-dot');
    var slot = 0;
    function renderSlot(i) {
      var set = offers.slice(i, i + 3);
      promoGrid.innerHTML = set.map(function (o) {
        return '<div class="det-offer"><div class="det-offer-icon">' + o.icon + '</div>' +
          '<span class="det-offer-tag"></span><div class="det-offer-title"></div><div class="det-offer-body"></div></div>';
      }).join('');
      var cards = promoGrid.querySelectorAll('.det-offer');
      set.forEach(function (o, idx) {
        cards[idx].querySelector('.det-offer-tag').textContent = o.tag;
        cards[idx].querySelector('.det-offer-title').textContent = o.title;
        cards[idx].querySelector('.det-offer-body').textContent = o.body;
      });
      dots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === (i === 0 ? 0 : 1)); });
    }
    if (offers.length > 3) {
      setInterval(function () {
        promoGrid.classList.add('is-fading');
        setTimeout(function () {
          slot = slot === 0 ? 3 : 0;
          renderSlot(slot);
          promoGrid.classList.remove('is-fading');
        }, 550);
      }, 5000);
    }
  }
})();
</script>
</body>
</html>
