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

// Every Nepal domestic airport this app knows about (see app/Support/Metadata.php
// and data/airports.php). If every leg of the itinerary stays inside this set,
// the ticket is Domestic (free); otherwise International (1 credit).
const ETICKET_NEPAL_DOMESTIC_AIRPORTS = [
    'KTM', 'PKR', 'BWA', 'BIR', 'JMO', 'KEP', 'BDP', 'DHI', 'SIF', 'LUA', 'RJB', 'TPU', 'IMK',
];

$settings = load_settings();
$rawInput = '';
$result = null;

$fareBase = '';
$fareFsc = '';
$fareTax = '';
$baggage = '15 KG + 5 KG';
$refundable = true;
$showFare = true;
$isDomestic = null;
$creditError = '';
$docReference = '';
$verifyToken = '';
$verifyUrl = '';
$docIssuedAt = '';
$agencyCreditBalance = $agencyId > 0 ? Auth::creditBalance($agencyId) : 0;

// The "Generate E-Ticket" shortcut on the Convert screen hands the pasted PNR
// text over so the agent doesn't have to paste it twice — but it must only
// pre-fill the textarea, not immediately generate (and charge a credit for)
// a document before they've reviewed fare/baggage/refundable settings.
$isPrefillOnly = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['prefill'] ?? '') === '1';
if ($isPrefillOnly) {
    $rawInput = isset($_POST['pnr_text']) ? (string) $_POST['pnr_text'] : '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = isset($_POST['pnr_text']) ? (string) $_POST['pnr_text'] : '';
    $fareBase = trim((string) ($_POST['fare_base'] ?? ''));
    $fareFsc  = trim((string) ($_POST['fare_fsc'] ?? ''));
    $fareTax  = trim((string) ($_POST['fare_tax'] ?? ''));
    $baggage  = trim((string) ($_POST['baggage'] ?? '')) !== '' ? trim((string) $_POST['baggage']) : '15 KG + 5 KG';
    $refundable = ($_POST['refundable'] ?? '1') === '1';

    $inputHash = trim($rawInput) !== '' ? hash('sha256', trim($rawInput)) : null;
    $isNewDocument = $inputHash !== null && $inputHash !== ($_SESSION['pnrc_eticket_last_hash'] ?? null);

    if (trim($rawInput) !== '') {
        $result = PnrParserFactory::parse($rawInput);

        if ($result->isRenderable()) {
            $isDomestic = true;
            foreach ($result->segments as $seg) {
                if (
                    !in_array(strtoupper($seg->departureAirport), ETICKET_NEPAL_DOMESTIC_AIRPORTS, true)
                    || !in_array(strtoupper($seg->arrivalAirport), ETICKET_NEPAL_DOMESTIC_AIRPORTS, true)
                ) {
                    $isDomestic = false;
                    break;
                }
            }
            $showFare = $isDomestic ? true : (($_POST['show_fare'] ?? '0') === '1');

            if ($isNewDocument) {
                if (!$isDomestic && $agencyCreditBalance < 1) {
                    $creditError = 'Your agency has no credits remaining. Ask an admin to top up from the Account page. (Domestic-only tickets are free.)';
                    $result = null;
                } else {
                    PrivacyLogger::log($result, (bool) ($settings['privacy_logging_enabled'] ?? false));

                    $deducted = $isDomestic || Auth::deductCredit($agencyId, (int) $user['id'], 'International e-ticket');
                    if (!$deducted) {
                        $creditError = 'Your agency has no credits remaining. Ask an admin to top up from the Account page.';
                        $result = null;
                    } else {
                        $agencyCreditBalance = Auth::creditBalance($agencyId);
                        $_SESSION['pnrc_eticket_last_hash'] = $inputHash;

                        $verifyToken = bin2hex(random_bytes(16));
                        $firstSeg = $result->segments[0] ?? null;
                        // Walk every leg's arrival rather than just first-departure →
                        // last-arrival: on a round trip those are the same airport
                        // (e.g. KTM → KTM), which reads as a zero-length route on the
                        // verify page. Collapsing consecutive duplicates gives the
                        // real path, e.g. KTM → DXB → KTM.
                        $routeSummary = null;
                        if ($firstSeg !== null) {
                            $waypoints = [$firstSeg->departureAirport];
                            foreach ($result->segments as $seg) {
                                if ($seg->arrivalAirport !== end($waypoints)) {
                                    $waypoints[] = $seg->arrivalAirport;
                                }
                            }
                            $routeSummary = implode(' → ', $waypoints);
                        }
                        $passengerNames = array_map(static fn ($p) => $p->name, $result->passengers);
                        $passengerLabel = count($passengerNames) > 0
                            ? ($passengerNames[0] . (count($passengerNames) > 1 ? ' +' . (count($passengerNames) - 1) . ' more' : ''))
                            : null;

                        $pdo = DB::conn();
                        try {
                            $pdo->prepare(
                                'INSERT INTO documents (agency_id, user_id, type, passenger_name, route_summary, pnr_text_hash, credits_used, verify_token)
                                 VALUES (:aid, :uid, :type, :pax, :route, :hash, :cost, :token)'
                            )->execute([
                                'aid' => $agencyId,
                                'uid' => (int) $user['id'],
                                'type' => 'eticket',
                                'pax' => $passengerLabel,
                                'route' => $routeSummary,
                                'hash' => $inputHash,
                                'cost' => $isDomestic ? 0 : 1,
                                'token' => $verifyToken,
                            ]);
                            $docId = (int) $pdo->lastInsertId();
                            $docReference = 'RN-' . str_pad((string) $docId, 6, '0', STR_PAD_LEFT);
                            $pdo->prepare('UPDATE documents SET reference_no = :ref WHERE id = :id')
                                ->execute(['ref' => $docReference, 'id' => $docId]);
                        } catch (\Throwable $e) {
                            $docReference = '';
                            $verifyToken = '';
                        }

                        $docIssuedAt = date('d M Y, H:i');
                        if ($verifyToken !== '') {
                            $verifyUrl = rtrim((string) ($settings['base_url'] ?? ''), '/') . '/verify.php?token=' . $verifyToken;
                        }
                    }
                }
            } else {
                // Same PNR re-submitted (e.g. toggling the fare display) — re-show
                // without re-charging or re-issuing a reference number. The verify
                // QR/reference must still come from the document that was already
                // issued, not be silently dropped — they only ever lived in the
                // request that created them, so without this lookup every re-render
                // (including just revisiting the same PNR later) showed a ticket
                // with no verify code at all, even though the document is real and
                // still verifiable at its original link.
                $pdo = DB::conn();
                $existing = $pdo->prepare(
                    'SELECT reference_no, verify_token, created_at FROM documents
                     WHERE agency_id = :aid AND pnr_text_hash = :hash AND type = :type
                     ORDER BY created_at DESC LIMIT 1'
                );
                $existing->execute(['aid' => $agencyId, 'hash' => $inputHash, 'type' => 'eticket']);
                $prior = $existing->fetch();
                if ($prior !== false) {
                    $docReference = (string) ($prior['reference_no'] ?? '');
                    $verifyToken = (string) ($prior['verify_token'] ?? '');
                    if ($verifyToken !== '') {
                        $verifyUrl = rtrim((string) ($settings['base_url'] ?? ''), '/') . '/verify.php?token=' . $verifyToken;
                    }
                    $docIssuedAt = date('d M Y, H:i', strtotime((string) $prior['created_at']));
                } else {
                    $docIssuedAt = date('d M Y, H:i');
                }
            }
        }
    }
}

require __DIR__ . '/app/View/eticketPage.php';
