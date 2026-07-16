<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Parser\PnrParserFactory;
use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\DB;
use RoamingNepal\PnrConverter\Support\PrivacyLogger;

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireLogin('login.php');
$user = Auth::user();
$agencyId = (int) ($user['agency_id'] ?? 0);

$settings = load_settings();
$features = $settings['features'] ?? [];
$rawInput = '';
$result = null;

$passengerNameInput = '';
$creditError = '';
$docReference = '';
$verifyToken = '';
$verifyUrl = '';
$docIssuedAt = '';
$agencyCreditBalance = $agencyId > 0 ? Auth::creditBalance($agencyId) : 0;

/** Converts a GDS date like "10JUL" or "10JUL2026" into Y-m-d, or null on failure. */
function visaDocGdsDateToSql(string $gdsDate): ?string
{
    $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    if (preg_match('/^(\d{2})([A-Z]{3})(\d{4})?$/', strtoupper(trim($gdsDate)), $m) !== 1) {
        return null;
    }
    $month = $months[$m[2]] ?? null;
    if ($month === null) {
        return null;
    }
    $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');

    return sprintf('%04d-%02d-%02d', $year, $month, (int) $m[1]);
}

// Visa Itinerary is a fixed, formal document — not a re-skin of the free
// converter. There is no format picker and no Options popover; the only
// thing the agent controls is the passenger name and whether the agency's
// own branding appears. Everything else renders at its most complete.
$features['result_format']         = 'detailed';
$features['show_airline_logo']     = true;
$features['show_airline_name']     = true;
$features['show_flight_duration']  = true;
$features['show_transit_time']     = true;
$features['show_terminal']         = true;
$features['show_cabin']            = true;
$features['show_booking_class']    = false;
$features['show_booking_reference'] = true;
$features['show_aircraft']         = false;
$features['show_operated_by']      = true;
$features['show_disclaimer']       = true;
$features['show_must_read']        = false;
$features['show_co2']              = false;
$features['show_ticket_numbers']   = false;
$features['show_seat_numbers']     = false;
$features['distance_unit']         = 'off';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = isset($_POST['pnr_text']) ? (string) $_POST['pnr_text'] : '';
    $passengerNameInput = trim((string) ($_POST['passenger_name'] ?? ''));

    $agencyBranding = ($_POST['agency_branding'] ?? '1') === '1';
    $features['show_agency_header'] = $agencyBranding;
    $features['show_agency_footer'] = $agencyBranding;

    // Re-rendering an already-issued document (e.g. toggling display options)
    // must not deduct a second credit — only charge when the PNR text changes.
    $inputHash = trim($rawInput) !== '' ? hash('sha256', trim($rawInput)) : null;
    $isNewDocument = $inputHash !== null && $inputHash !== ($_SESSION['pnrc_visa_last_hash'] ?? null);

    if (trim($rawInput) !== '') {
        if ($isNewDocument && $agencyCreditBalance < 1) {
            $creditError = 'Your agency has no credits remaining. Ask an admin to top up from the Account page.';
        } else {
            $result = PnrParserFactory::parse($rawInput);

            if ($result->isRenderable() && $isNewDocument) {
                PrivacyLogger::log($result, (bool) ($settings['privacy_logging_enabled'] ?? false));

                $deducted = Auth::deductCredit($agencyId, (int) $user['id'], 'Visa itinerary document');
                if (!$deducted) {
                    $creditError = 'Your agency has no credits remaining. Ask an admin to top up from the Account page.';
                    $result = null;
                } else {
                    $agencyCreditBalance = Auth::creditBalance($agencyId);
                    $_SESSION['pnrc_visa_last_hash'] = $inputHash;

                    $verifyToken = bin2hex(random_bytes(16));
                    $firstSeg = $result->segments[0] ?? null;
                    $lastSeg  = $result->segments[count($result->segments) - 1] ?? null;
                    $routeSummary = $firstSeg !== null && $lastSeg !== null
                        ? $firstSeg->departureAirport . ' → ' . $lastSeg->arrivalAirport
                        : null;
                    $travelDate = $firstSeg !== null ? visaDocGdsDateToSql($firstSeg->departureDate) : null;

                    $pdo = DB::conn();
                    try {
                        $pdo->prepare(
                            'INSERT INTO documents (agency_id, user_id, type, passenger_name, route_summary, travel_date, pnr_text_hash, credits_used, verify_token)
                             VALUES (:aid, :uid, "visa_itinerary", :pax, :route, :tdate, :hash, 1, :token)'
                        )->execute([
                            'aid' => $agencyId,
                            'uid' => (int) $user['id'],
                            'pax' => $passengerNameInput !== '' ? $passengerNameInput : null,
                            'route' => $routeSummary,
                            'tdate' => $travelDate,
                            'hash' => $inputHash,
                            'token' => $verifyToken,
                        ]);
                        $docId = (int) $pdo->lastInsertId();
                        $docReference = 'RN-' . str_pad((string) $docId, 6, '0', STR_PAD_LEFT);
                        $pdo->prepare('UPDATE documents SET reference_no = :ref WHERE id = :id')
                            ->execute(['ref' => $docReference, 'id' => $docId]);
                    } catch (\Throwable $e) {
                        // Migration not applied yet (missing columns) — fall back to the
                        // minimal insert so the credit charge still has an audit row, and
                        // still show the document; only the verify footer is skipped.
                        $pdo->prepare(
                            'INSERT INTO documents (agency_id, user_id, type, pnr_text_hash, credits_used, verify_token)
                             VALUES (:aid, :uid, "visa_itinerary", :hash, 1, :token)'
                        )->execute([
                            'aid' => $agencyId,
                            'uid' => (int) $user['id'],
                            'hash' => $inputHash,
                            'token' => $verifyToken,
                        ]);
                        $docReference = '';
                        $verifyToken = '';
                    }

                    $docIssuedAt = date('d M Y');
                    if ($verifyToken !== '') {
                        $verifyUrl = rtrim((string) ($settings['base_url'] ?? ''), '/') . '/verify.php?token=' . $verifyToken;
                    }
                }
            } elseif ($result->isRenderable() && !$isNewDocument) {
                // Same PNR re-submitted (option toggle) — re-show without re-charging.
                // We don't have the original verify token/reference in this request,
                // so the verify footer is intentionally omitted on re-renders.
                $docIssuedAt = date('d M Y');
            }
        }
    }
}

$visaMode = true;
require __DIR__ . '/app/View/page.php';
