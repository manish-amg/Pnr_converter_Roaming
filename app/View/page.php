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

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$asset = static fn (string $path): string => ($basePath === '' ? '' : $basePath) . '/' . ltrim($path, '/');
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');
$logoPath = (string) ($settings['logo_path'] ?? 'assets/images/logo.svg');
$projectRoot = dirname(__DIR__, 2);
$distanceUnit = in_array((string) ($features['distance_unit'] ?? 'off'), ['off', 'km', 'miles'], true)
    ? (string) $features['distance_unit']
    : 'off';
$resultFormat = in_array((string) ($features['result_format'] ?? 'detailed'), ['detailed', 'compact', 'table', 'whatsapp', 'two_lines', 'two_lines_reordered', 'three_lines', 'three_lines_reordered'], true)
    ? (string) $features['result_format']
    : 'detailed';
$layoutFormat = match ($resultFormat) {
    'compact' => 'two_lines',
    'whatsapp' => 'two_lines_reordered',
    'detailed' => 'three_lines',
    default => $resultFormat,
};
$use24HourTime = array_key_exists('use_12_hour_clock', $features)
    ? !(bool) $features['use_12_hour_clock']
    : (bool) ($features['use_24_hour_time'] ?? true);

$show = static fn (string $key): bool => (bool) ($features[$key] ?? false);
$showAgencyHeader = $show('show_agency_header');
$showAgencyFooter = $show('show_agency_footer');
$showDisclaimer = $show('show_disclaimer');
$airlineLogo = static function (string $airlineCode) use ($projectRoot): ?string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $airlineCode) ?? '');
    if ($code === '') {
        return null;
    }

    foreach (['svg', 'png', 'webp'] as $extension) {
        $path = 'assets/images/airlines/' . $code . '.' . $extension;
        if (is_file($projectRoot . '/' . $path)) {
            return $path;
        }
    }

    return null;
};
$maskTicket = static function (?string $ticket, bool $mask): ?string {
    if ($ticket === null || $ticket === '') {
        return null;
    }
    if (!$mask || strlen($ticket) < 7) {
        return $ticket;
    }
    return substr($ticket, 0, 3) . str_repeat('*', max(0, strlen($ticket) - 7)) . substr($ticket, -4);
};
$maskSeat = static function (?string $seat, bool $mask): ?string {
    if ($seat === null || $seat === '') {
        return null;
    }
    return $mask ? preg_replace('/\d/', '*', $seat) : $seat;
};
$formatTime = static function (string $time, bool $use24): string {
    if ($use24 || preg_match('/^(\d{2}):(\d{2})$/', $time, $m) !== 1) {
        return $time;
    }
    $hour = (int) $m[1];
    $period = $hour >= 12 ? 'PM' : 'AM';
    $hour = $hour % 12;
    if ($hour === 0) {
        $hour = 12;
    }
    return sprintf('%d:%s %s', $hour, $m[2], $period);
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>PNR Converter | <?= Html::e($agencyName) ?></title>
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/styles.css')) ?>">
    <link rel="stylesheet" href="<?= Html::e($asset('assets/css/print.css')) ?>" media="print">
</head>
<body>
<div class="share-hint no-print" aria-hidden="true">Press Esc to exit share view</div>
<header class="topbar no-share">
    <div class="brand">
        <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?> logo">
        <div>
            <p class="eyebrow">Hosted by Roaming Nepal</p>
            <h1>Flight PNR Converter</h1>
        </div>
    </div>
    <button class="button button-light" type="button" id="shareModeBtn">Clean Share View</button>
</header>

<main class="app-shell">
    <section class="workspace no-share" aria-labelledby="input-title">
        <form method="post" class="panel" autocomplete="off">
            <div class="panel-header">
                <div>
                    <p class="eyebrow">Step 1</p>
                    <h2 id="input-title">Paste and convert</h2>
                </div>
                <?php if ($result !== null): ?>
                    <span class="status-pill status-<?= Html::e($result->confidence) ?>">
                        <?= Html::e($result->sourceFormat) ?> · <?= Html::e(strtoupper($result->confidence)) ?>
                    </span>
                <?php endif; ?>
            </div>

            <label class="field-label" for="pnr_text">Raw PNR / itinerary text</label>
            <textarea id="pnr_text" name="pnr_text" rows="16" spellcheck="false" placeholder="Paste Amadeus, Travelport/Galileo/Smartpoint/Worldspan, or Sabre itinerary text here."><?= Html::e($rawInput) ?></textarea>

            <section class="settings-panel" aria-labelledby="settings-title">
                <div class="settings-title">
                    <p class="eyebrow">Step 2</p>
                    <h3 id="settings-title">Share and display</h3>
                </div>
                <?php
                $optionGroups = [
                    'Branding' => [
                        'show_agency_header' => 'Agency header',
                        'show_agency_footer' => 'Footer/contact',
                        'show_disclaimer' => 'Disclaimer',
                    ],
                    'Flight Details' => [
                        'show_airline_name' => 'Airline name',
                        'show_airline_logo' => 'Airline logo',
                        'show_transit_time' => 'Transit time',
                        'use_12_hour_clock' => '12 hour clock',
                        'show_operated_by' => 'Operated by',
                        'show_aircraft' => 'Aircraft',
                    ],
                    'Passenger Safe Data' => [
                        'show_booking_class' => 'Booking class',
                        'show_cabin' => 'Cabin',
                        'show_booking_reference' => 'Booking reference',
                        'show_ticket_numbers' => 'Ticket numbers',
                        'show_seat_numbers' => 'Seat numbers',
                    ],
                ];
                foreach ($optionGroups as $groupTitle => $optionLabels):
                    ?>
                    <div class="option-group">
                        <h3><?= Html::e($groupTitle) ?></h3>
                        <div class="option-list">
                            <?php foreach ($optionLabels as $key => $label): ?>
                                <label class="check">
                                    <input type="hidden" name="<?= Html::e($key) ?>" value="0">
                                    <input type="checkbox" name="<?= Html::e($key) ?>" value="1"<?= Html::checked($show($key)) ?>>
                                    <span><?= Html::e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="option-group">
                    <span class="control-title">Show distance</span>
                    <div class="segmented" role="radiogroup" aria-label="Show distance">
                        <?php foreach (['off' => 'Off', 'km' => 'KM', 'miles' => 'Miles'] as $value => $label): ?>
                            <label>
                                <input type="radio" name="distance_unit" value="<?= Html::e($value) ?>"<?= Html::checked($distanceUnit === $value) ?>>
                                <span><?= Html::e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="option-group option-group-wide">
                    <span class="control-title">Results format</span>
                    <div class="format-options" role="radiogroup" aria-label="Results format">
                        <?php
                        $formats = [
                            'detailed' => 'Detailed',
                            'compact' => 'Compact',
                            'table' => 'Table',
                            'whatsapp' => 'WhatsApp',
                        ];
                        foreach ($formats as $value => $label):
                            ?>
                            <label class="radio-card">
                                <input type="radio" name="result_format" value="<?= Html::e($value) ?>"<?= Html::checked($resultFormat === $value) ?>>
                                <span><?= Html::e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <div class="actions">
                <button class="button button-primary" type="submit">Convert</button>
                <button class="button" type="reset" id="resetBtn">Clear / Reset</button>
                <button class="button" type="button" id="printBtn">Print / Save PDF</button>
                <button class="button" type="button" id="downloadPngBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>Download PNG</button>
                <button class="button" type="button" id="copyTextBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>Copy Text</button>
                <button class="button" type="button" id="copyImageBtn"<?= $result === null || !$result->isRenderable() ? ' disabled' : '' ?>>Copy Image</button>
            </div>
        </form>
    </section>

    <section class="preview-area" aria-labelledby="preview-title">
        <div class="preview-header no-share">
            <div>
                <p class="eyebrow">Step 3</p>
                <h2 id="preview-title">Review passenger copy</h2>
            </div>
            <?php if ($result !== null): ?>
                <span class="source-label">Detected: <?= Html::e($result->sourceFormat) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($result !== null && count($result->warnings) > 0): ?>
            <div class="warning no-share" role="alert">
                <?php foreach ($result->warnings as $warning): ?>
                    <p><?= Html::e($warning) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($result === null): ?>
            <div class="empty-state no-share">
                <h3>No itinerary converted yet</h3>
                <p>Paste the GDS output, keep the default display options, and press Convert.</p>
            </div>
        <?php elseif (!$result->isRenderable()): ?>
            <div class="warning no-share">
                <h3>Manual review needed</h3>
                <p>The confidence level is low, so the tool is not generating a passenger-ready card.</p>
                <?php if (count($result->unparsedLines) > 0): ?>
                    <ul>
                        <?php foreach ($result->unparsedLines as $line): ?>
                            <li><code><?= Html::e($line) ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <article class="itinerary-card<?= !$showAgencyHeader ? ' itinerary-card-plain' : '' ?>" id="itineraryCard">
                <?php
                $firstSegment = $result->segments[0] ?? null;
                $lastSegment = $result->segments[count($result->segments) - 1] ?? null;
                ?>
                <?php if ($showAgencyHeader): ?>
                    <header class="card-header">
                        <img src="<?= Html::e($asset($logoPath)) ?>" alt="<?= Html::e($agencyName) ?> logo">
                        <div>
                            <p class="eyebrow">Flight itinerary</p>
                            <h2><?= Html::e($agencyName) ?></h2>
                        </div>
                    </header>
                <?php endif; ?>

                <section class="card-section passenger-summary">
                    <div>
                        <p class="label">Passenger<?= count($result->passengers) === 1 ? '' : 's' ?></p>
                        <p class="value">
                            <?= Html::e(count($result->passengers) > 0 ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers)) : 'Passenger name not detected') ?>
                        </p>
                    </div>
                    <?php if ($show('show_booking_reference') && $result->recordLocator !== null): ?>
                        <div>
                            <p class="label">Booking reference</p>
                            <p class="value reference"><?= Html::e($result->recordLocator) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($firstSegment instanceof Segment && $lastSegment instanceof Segment): ?>
                        <div>
                            <p class="label">Trip summary</p>
                            <p class="value"><?= Html::e($firstSegment->departureAirport . ' to ' . $lastSegment->arrivalAirport) ?></p>
                            <p class="summary-meta"><?= Html::e(count($result->segments) . ' flight' . (count($result->segments) === 1 ? '' : 's')) ?></p>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($layoutFormat === 'table'): ?>
                    <section class="segments-table-wrap" aria-label="Itinerary table">
                        <table class="segments-table">
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
                            <?php foreach ($result->segments as $segment): ?>
                                <?php
                                /** @var Segment $segment */
                                $distanceLabel = Metadata::distanceLabel($segment->departureAirport, $segment->arrivalAirport, $distanceUnit);
                                ?>
                                <tr>
                                    <td>
                                        <strong class="flight-brand flight-brand-table">
                                            <?php $airlineLogoPath = $show('show_airline_logo') ? $airlineLogo($segment->airlineCode) : null; ?>
                                            <?php if ($airlineLogoPath !== null): ?>
                                                <img src="<?= Html::e($asset($airlineLogoPath)) ?>" alt="<?= Html::e($segment->airlineName ?: $segment->airlineCode) ?> logo">
                                            <?php else: ?>
                                                <span class="airline-code-badge"><?= Html::e($segment->airlineCode) ?></span>
                                            <?php endif; ?>
                                            <span><?= Html::e($segment->airlineCode . ' ' . $segment->flightNumber) ?><?php if ($show('show_airline_name') && $segment->airlineName): ?><small><?= Html::e($segment->airlineName) ?></small><?php endif; ?></span>
                                        </strong>
                                    </td>
                                    <td><?= Html::e($segment->departureAirport) ?></td>
                                    <td><?= Html::e($segment->departureDate) ?><br><?= Html::e($formatTime($segment->departureTime, $use24HourTime)) ?></td>
                                    <td><?= Html::e($segment->arrivalAirport) ?></td>
                                    <td><?= Html::e($segment->arrivalDate ?: $segment->departureDate) ?><br><?= Html::e($formatTime($segment->arrivalTime, $use24HourTime)) ?></td>
                                    <td>
                                        <?php if ($segment->status): ?>Status <?= Html::e($segment->status) ?><br><?php endif; ?>
                                        <?php if ($show('show_booking_class') && $segment->bookingClass): ?>Class <?= Html::e($segment->bookingClass) ?><br><?php endif; ?>
                                        <?php if ($show('show_cabin') && $segment->cabin): ?><?= Html::e($segment->cabin) ?><br><?php endif; ?>
                                        <?php if ($distanceLabel !== null): ?>Distance <?= Html::e($distanceLabel) ?><br><?php endif; ?>
                                        <?php if ($show('show_aircraft') && $segment->aircraft): ?>Aircraft <?= Html::e($segment->aircraft) ?><br><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php else: ?>
                    <section class="segments segments-format-<?= Html::e($layoutFormat) ?><?= $resultFormat === 'whatsapp' ? ' segments-whatsapp' : '' ?>">
                        <?php foreach ($result->segments as $index => $segment): ?>
                            <?php
                            /** @var Segment $segment */
                            $airlineLogoPath = $show('show_airline_logo') ? $airlineLogo($segment->airlineCode) : null;
                            $distanceLabel = Metadata::distanceLabel($segment->departureAirport, $segment->arrivalAirport, $distanceUnit);
                            $compactFormat = in_array($layoutFormat, ['two_lines', 'two_lines_reordered'], true);
                            $reorderedFormat = in_array($layoutFormat, ['two_lines_reordered', 'three_lines_reordered'], true);
                            ?>
                            <div class="segment<?= $compactFormat ? ' segment-compact' : '' ?>">
                                <div class="segment-title">
                                    <span>Segment <?= $index + 1 ?></span>
                                    <strong class="flight-brand">
                                        <?php if ($airlineLogoPath !== null): ?>
                                            <img src="<?= Html::e($asset($airlineLogoPath)) ?>" alt="<?= Html::e($segment->airlineName ?: $segment->airlineCode) ?> logo">
                                        <?php else: ?>
                                            <span class="airline-code-badge"><?= Html::e($segment->airlineCode) ?></span>
                                        <?php endif; ?>
                                        <span>
                                            <?= Html::e($segment->airlineCode . ' ' . $segment->flightNumber) ?>
                                            <?php if ($show('show_airline_name') && $segment->airlineName): ?>
                                                <small><?= Html::e($segment->airlineName) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </strong>
                                </div>

                                <?php if ($compactFormat): ?>
                                    <div class="compact-lines">
                                        <?php if ($reorderedFormat): ?>
                                            <p><strong><?= Html::e($segment->departureAirport) ?> to <?= Html::e($segment->arrivalAirport) ?></strong> · <?= Html::e($segment->departureDate) ?> <?= Html::e($formatTime($segment->departureTime, $use24HourTime)) ?> - <?= Html::e($segment->arrivalDate ?: $segment->departureDate) ?> <?= Html::e($formatTime($segment->arrivalTime, $use24HourTime)) ?></p>
                                            <p><?= Html::e($segment->airlineCode . ' ' . $segment->flightNumber) ?><?php if ($show('show_airline_name') && $segment->airlineName): ?> · <?= Html::e($segment->airlineName) ?><?php endif; ?></p>
                                        <?php else: ?>
                                            <p><strong><?= Html::e($segment->airlineCode . ' ' . $segment->flightNumber) ?></strong><?php if ($show('show_airline_name') && $segment->airlineName): ?> · <?= Html::e($segment->airlineName) ?><?php endif; ?></p>
                                            <p><?= Html::e($segment->departureAirport) ?> <?= Html::e($segment->departureDate) ?> <?= Html::e($formatTime($segment->departureTime, $use24HourTime)) ?> to <?= Html::e($segment->arrivalAirport) ?> <?= Html::e($segment->arrivalDate ?: $segment->departureDate) ?> <?= Html::e($formatTime($segment->arrivalTime, $use24HourTime)) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="route">
                                        <?php if (!$reorderedFormat): ?>
                                            <div class="airport">
                                                <p class="code"><?= Html::e($segment->departureAirport) ?></p>
                                                <p class="datetime"><?= Html::e($segment->departureDate) ?> · <?= Html::e($formatTime($segment->departureTime, $use24HourTime)) ?></p>
                                                <p class="airport-name"><?= Html::e(Metadata::airportLabel($segment->departureAirport)) ?></p>
                                            </div>
                                            <div class="route-line" aria-hidden="true"><span></span></div>
                                            <div class="airport airport-arrive">
                                                <p class="code"><?= Html::e($segment->arrivalAirport) ?></p>
                                                <p class="datetime"><?= Html::e(($segment->arrivalDate ?: $segment->departureDate)) ?> · <?= Html::e($formatTime($segment->arrivalTime, $use24HourTime)) ?></p>
                                                <p class="airport-name"><?= Html::e(Metadata::airportLabel($segment->arrivalAirport)) ?></p>
                                            </div>
                                        <?php else: ?>
                                            <div class="airport">
                                                <p class="airport-name">Departure</p>
                                                <p class="code"><?= Html::e($segment->departureAirport) ?></p>
                                                <p class="datetime"><?= Html::e($segment->departureDate) ?> · <?= Html::e($formatTime($segment->departureTime, $use24HourTime)) ?></p>
                                            </div>
                                            <div class="route-line" aria-hidden="true"><span></span></div>
                                            <div class="airport airport-arrive">
                                                <p class="airport-name">Arrival</p>
                                                <p class="code"><?= Html::e($segment->arrivalAirport) ?></p>
                                                <p class="datetime"><?= Html::e(($segment->arrivalDate ?: $segment->departureDate)) ?> · <?= Html::e($formatTime($segment->arrivalTime, $use24HourTime)) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="details">
                                    <?php if ($segment->status): ?><span>Status <?= Html::e($segment->status) ?></span><?php endif; ?>
                                    <?php if ($show('show_booking_class') && $segment->bookingClass): ?><span>Class <?= Html::e($segment->bookingClass) ?></span><?php endif; ?>
                                    <?php if ($show('show_cabin') && $segment->cabin): ?><span><?= Html::e($segment->cabin) ?></span><?php endif; ?>
                                    <?php if ($distanceLabel !== null): ?><span>Distance <?= Html::e($distanceLabel) ?></span><?php endif; ?>
                                    <?php if ($show('show_aircraft') && $segment->aircraft): ?><span>Aircraft <?= Html::e($segment->aircraft) ?></span><?php endif; ?>
                                    <?php if ($show('show_ticket_numbers') && $segment->ticketNumber): ?><span>Ticket <?= Html::e($maskTicket($segment->ticketNumber, (bool) ($features['mask_ticket_numbers'] ?? true))) ?></span><?php endif; ?>
                                    <?php if ($show('show_seat_numbers') && $segment->seatNumber): ?><span>Seat <?= Html::e($maskSeat($segment->seatNumber, (bool) ($features['mask_seat_numbers'] ?? false))) ?></span><?php endif; ?>
                                </div>
                                <?php if ($show('show_operated_by') && $segment->operatedBy): ?>
                                    <p class="note">Operated by <?= Html::e($segment->operatedBy) ?></p>
                                <?php endif; ?>
                                <?php if (($show('show_transit_time') || $show('show_layover')) && $segment->layoverDuration): ?>
                                    <p class="layover">Transit at <?= Html::e($segment->arrivalAirport) ?>: <?= Html::e($segment->layoverDuration) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <?php if ($showDisclaimer || $showAgencyFooter): ?>
                <footer class="card-footer">
                    <?php if ($showDisclaimer): ?>
                        <p class="disclaimer"><?= Html::e((string) ($settings['default_disclaimer'] ?? 'Please verify final schedule with the airline before travel.')) ?></p>
                    <?php endif; ?>
                    <?php if ($showAgencyFooter): ?>
                    <?php $footer = is_array($settings['footer'] ?? null) ? $settings['footer'] : []; ?>
                    <?php $headOffice = is_array($footer['head_office'] ?? null) ? $footer['head_office'] : []; ?>
                    <?php if (!empty($headOffice)): ?>
                        <div class="footer-office footer-office-main">
                            <?php if (!empty($headOffice['title'])): ?><strong><?= Html::e((string) $headOffice['title']) ?></strong><?php endif; ?>
                            <?php foreach (($headOffice['lines'] ?? []) as $line): ?>
                                <span><?= Html::e((string) $line) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php $branches = is_array($footer['branches'] ?? null) ? $footer['branches'] : []; ?>
                    <?php if (count($branches) > 0): ?>
                        <div class="footer-branches">
                            <?php if (!empty($footer['branches_title'])): ?><strong class="branches-title"><?= Html::e((string) $footer['branches_title']) ?></strong><?php endif; ?>
                            <div class="branch-grid">
                                <?php foreach ($branches as $branch): ?>
                                    <?php if (!is_array($branch)) { continue; } ?>
                                    <div class="footer-office">
                                        <?php if (!empty($branch['title'])): ?><strong><?= Html::e((string) $branch['title']) ?></strong><?php endif; ?>
                                        <?php foreach (($branch['lines'] ?? []) as $line): ?>
                                            <span><?= Html::e((string) $line) ?></span>
                                        <?php endforeach; ?>
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

            <textarea class="sr-only" id="textVersion" readonly><?php
                echo Html::e($agencyName . " flight itinerary\n");
                if ($result->recordLocator !== null && $show('show_booking_reference')) {
                    echo Html::e('Booking reference: ' . $result->recordLocator . "\n");
                }
                echo Html::e('Passengers: ' . (count($result->passengers) > 0 ? implode(', ', array_map(static fn ($p) => $p->name, $result->passengers)) : 'Not detected') . "\n\n");
                foreach ($result->segments as $index => $segment) {
                    echo Html::e('Segment ' . ($index + 1) . ': ' . $segment->airlineCode . ' ' . $segment->flightNumber . ' ' . $segment->departureAirport . ' ' . $segment->departureDate . ' ' . $formatTime($segment->departureTime, $use24HourTime) . ' to ' . $segment->arrivalAirport . ' ' . ($segment->arrivalDate ?: $segment->departureDate) . ' ' . $formatTime($segment->arrivalTime, $use24HourTime) . "\n");
                }
                if ($showDisclaimer) {
                    echo Html::e("\n" . ($settings['default_disclaimer'] ?? ''));
                }
                ?></textarea>
        <?php endif; ?>
    </section>
</main>

<script src="<?= Html::e($asset('assets/js/app.js')) ?>"></script>
</body>
</html>
