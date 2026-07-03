<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Support\DB;
use RoamingNepal\PnrConverter\Support\Html;

require_once __DIR__ . '/app/bootstrap.php';

$settings = load_settings();
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');

$token = (string) ($_GET['token'] ?? '');
$doc = null;
$agency = null;

if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE verify_token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $doc = $stmt->fetch();

    if ($doc !== false) {
        $agStmt = $pdo->prepare('SELECT name FROM agencies WHERE id = :id LIMIT 1');
        $agStmt->execute(['id' => $doc['agency_id']]);
        $agency = $agStmt->fetch();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Document Verification — <?= Html::e($agencyName) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-brand">
        <div class="auth-brand-logo"><span><?= Html::e($agencyName) ?></span></div>
        <div class="auth-brand-mid">
            <div class="auth-brand-title">Document verification.</div>
            <div class="auth-brand-sub">Confirming whether a visa itinerary document was genuinely issued by one of our partner agencies.</div>
        </div>
    </div>
    <div class="auth-main">
        <div class="auth-card">
            <?php if ($doc !== false && $doc !== null): ?>
                <div class="auth-card-title">Document verified</div>
                <div class="auth-alert auth-alert-ok">This document was genuinely issued and matches our records.</div>
                <div class="auth-field">
                    <label class="auth-label">Issued by</label>
                    <div class="verify-value"><?= Html::e((string) ($agency['name'] ?? 'Unknown agency')) ?></div>
                </div>
                <?php if (!empty($doc['reference_no'])): ?>
                <div class="auth-field">
                    <label class="auth-label">Reference</label>
                    <div class="verify-value verify-mono"><?= Html::e((string) $doc['reference_no']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($doc['passenger_name'])): ?>
                <div class="auth-field">
                    <label class="auth-label">Passenger</label>
                    <div class="verify-value"><?= Html::e((string) $doc['passenger_name']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($doc['route_summary'])): ?>
                <div class="auth-field">
                    <label class="auth-label">Route</label>
                    <div class="verify-value verify-mono"><?= Html::e((string) $doc['route_summary']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($doc['travel_date'])): ?>
                <div class="auth-field">
                    <label class="auth-label">Travel date</label>
                    <div class="verify-value"><?= Html::e(date('d M Y', strtotime((string) $doc['travel_date']))) ?></div>
                </div>
                <?php endif; ?>
                <div class="auth-field">
                    <label class="auth-label">Issued on</label>
                    <div class="verify-value"><?= Html::e(date('d M Y', strtotime((string) $doc['created_at']))) ?></div>
                </div>
            <?php else: ?>
                <div class="auth-card-title">Not found</div>
                <div class="auth-alert">This verification code does not match any document in our records. It may have been altered, or is not genuine.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
