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
    if (!$seg->arrivalDate) return 0;
    $dep = $gdsDateTime($seg->departureDate, $seg->departureTime, $seg->departureAirport);
    $arr = $gdsDateTime($seg->arrivalDate, $seg->arrivalTime, $seg->arrivalAirport);
    if ($dep === null || $arr === null || $arr->getTimestamp() <= $dep->getTimestamp()) return 0;
    return min(2, (int) $dep->diff($arr)->days);
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
      new window.QRCode(qrEl, { text: <?= json_encode($verifyUrl) ?>, width: 84, height: 84, colorDark: '#0F367B', colorLight: '#ffffff' });
    } catch (e) {}
  }
  <?php endif; ?>
  var printBtn = document.getElementById('etPrintBtn');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
})();
</script>
</body>
</html>
