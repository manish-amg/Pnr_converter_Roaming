<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\DB;
use RoamingNepal\PnrConverter\Support\Html;

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireLogin('login.php');
Auth::requireRole(['superadmin']);
$user = Auth::user();
$pdo = DB::conn();
$settings = load_settings();
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_credits') {
        $agencyId = (int) ($_POST['agency_id'] ?? 0);
        $amount = (int) ($_POST['amount'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Manual top-up'));

        if ($agencyId <= 0 || $amount === 0) {
            $error = 'Choose an agency and a non-zero amount.';
        } else {
            Auth::addCredits($agencyId, $amount, $reason !== '' ? $reason : 'Manual top-up', (int) $user['id']);
            $notice = 'Credits updated.';
        }
    }

    if ($action === 'toggle_internal') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $target->execute(['id' => $targetId]);
        $targetRow = $target->fetch();

        if ($targetRow !== false && in_array($targetRow['role'], ['agent', 'internal'], true)) {
            $newRole = $targetRow['role'] === 'internal' ? 'agent' : 'internal';
            $pdo->prepare('UPDATE users SET role = :role WHERE id = :id')
                ->execute(['role' => $newRole, 'id' => $targetId]);
            $notice = 'Role updated.';
        } else {
            $error = 'Only agent accounts can be flagged internal.';
        }
    }

    if ($action === 'toggle_active') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target = $pdo->prepare('SELECT id, is_active, role FROM users WHERE id = :id LIMIT 1');
        $target->execute(['id' => $targetId]);
        $targetRow = $target->fetch();

        if ($targetRow !== false && $targetRow['role'] !== 'superadmin') {
            $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id')
                ->execute(['active' => ((int) $targetRow['is_active']) === 1 ? 0 : 1, 'id' => $targetId]);
            $notice = 'Account status updated.';
        }
    }
}

$agencies = $pdo->query('SELECT * FROM agencies ORDER BY name ASC')->fetchAll();
$users = $pdo->query(
    'SELECT u.*, a.name AS agency_name FROM users u LEFT JOIN agencies a ON a.id = u.agency_id ORDER BY u.created_at DESC'
)->fetchAll();

$today = date('Y-m-d');
$startDate = date('Y-m-d', strtotime('-6 days'));

$agencyCount = (int) $pdo->query('SELECT COUNT(*) FROM agencies')->fetchColumn();

$activeTodayStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT u.agency_id) FROM usage_daily ud JOIN users u ON u.id = ud.user_id WHERE ud.usage_date = :d AND ud.conversions_count > 0'
);
$activeTodayStmt->execute(['d' => $today]);
$activeToday = (int) $activeTodayStmt->fetchColumn();

$conversionsTodayStmt = $pdo->prepare('SELECT COALESCE(SUM(conversions_count),0) FROM usage_daily WHERE usage_date = :d');
$conversionsTodayStmt->execute(['d' => $today]);
$conversionsToday = (int) $conversionsTodayStmt->fetchColumn();

$docsTotal = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();

$sevenDayStmt = $pdo->prepare('SELECT usage_date, SUM(conversions_count) AS total FROM usage_daily WHERE usage_date >= :start GROUP BY usage_date');
$sevenDayStmt->execute(['start' => $startDate]);
$byDate = [];
foreach ($sevenDayStmt->fetchAll() as $row) {
    $byDate[(string) $row['usage_date']] = (int) $row['total'];
}
$series = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $series[] = ['label' => date('D', strtotime($d)), 'count' => $byDate[$d] ?? 0, 'isToday' => $d === $today];
}
$seriesMax = max(1, ...array_column($series, 'count'));

$topAgenciesStmt = $pdo->prepare(
    'SELECT a.id, a.name, COALESCE(SUM(ud.conversions_count),0) AS total
     FROM agencies a
     LEFT JOIN users u ON u.agency_id = a.id
     LEFT JOIN usage_daily ud ON ud.user_id = u.id AND ud.usage_date >= :start
     GROUP BY a.id, a.name
     ORDER BY total DESC
     LIMIT 5'
);
$topAgenciesStmt->execute(['start' => $startDate]);
$topAgencies = $topAgenciesStmt->fetchAll();
$topAgenciesMax = max(1, ...array_map(static fn (array $r): int => (int) $r['total'], $topAgencies));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — <?= Html::e($agencyName) ?> PNR Converter</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <link rel="stylesheet" href="assets/css/account.css">
</head>
<body>
<div class="rn-shell">
    <nav class="rn-rail no-print no-share" aria-label="Site navigation">
        <div class="rail-logo"><a href="index.php"><span class="rail-logo-text">RN</span></a></div>
        <div class="rail-nav">
            <a class="rail-item" href="index.php" title="PNR Converter">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span class="rail-item-label">Convert</span>
            </a>
            <a class="rail-item" href="visa-doc.php" title="Visa Itinerary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <span class="rail-item-label">Visa Itinerary</span>
            </a>
            <a class="rail-item" href="eticket.php" title="E-Ticket">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 10a2 2 0 100-4V5a1 1 0 011-1h16a1 1 0 011 1v1a2 2 0 100 4v0a2 2 0 100 4v1a1 1 0 01-1 1H4a1 1 0 01-1-1v-1a2 2 0 100-4z"/><line x1="10" y1="4" x2="10" y2="20" stroke-dasharray="2 2"/></svg>
                <span class="rail-item-label">E-Ticket</span>
            </a>
            <a class="rail-item" href="account.php" title="Account">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span class="rail-item-label">Account</span>
            </a>
            <a class="rail-item is-active" href="admin.php" title="Admin">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span class="rail-item-label">Admin</span>
            </a>
        </div>
        <div class="rail-footer">
            <a class="rail-avatar" href="account.php" title="<?= Html::e((string) $user['name']) ?>"><?= Html::e(strtoupper(substr((string) $user['name'], 0, 2))) ?></a>
            <a class="rail-logout" href="logout.php" title="Log out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </nav>

    <div class="rn-workspace">
    <main class="rn-page acct-page">
        <div class="acct-eyebrow">SUPER ADMIN</div>
        <div class="acct-header">
            <h1 class="acct-title">Platform Overview</h1>
            <span class="acct-live-pill"><span class="acct-live-dot"></span>Live &middot; today</span>
        </div>

        <?php if ($notice !== null): ?><div class="alert" style="background:rgba(16,185,129,.09);color:#059669;border:1px solid rgba(16,185,129,.22);"><?= Html::e($notice) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="alert alert-danger"><?= Html::e($error) ?></div><?php endif; ?>

        <div class="acct-admin-stats">
            <div class="acct-admin-stat">
                <div class="acct-admin-stat-icon" style="background:rgba(41,159,239,.12);color:#1E8AD9;">&#127970;</div>
                <div class="acct-admin-stat-value"><?= Html::e((string) $agencyCount) ?></div>
                <div class="acct-admin-stat-label">Agencies</div>
            </div>
            <div class="acct-admin-stat">
                <div class="acct-admin-stat-icon" style="background:rgba(33,150,83,.12);color:#219653;">&#9889;</div>
                <div class="acct-admin-stat-value"><?= Html::e((string) $activeToday) ?></div>
                <div class="acct-admin-stat-label">Active today</div>
            </div>
            <div class="acct-admin-stat">
                <div class="acct-admin-stat-icon" style="background:rgba(15,54,123,.1);color:var(--rn-navy);">&#128257;</div>
                <div class="acct-admin-stat-value"><?= Html::e((string) $conversionsToday) ?></div>
                <div class="acct-admin-stat-label">Conversions today</div>
            </div>
            <div class="acct-admin-stat">
                <div class="acct-admin-stat-icon" style="background:rgba(245,188,65,.16);color:#B98A16;">&#128196;</div>
                <div class="acct-admin-stat-value"><?= Html::e((string) $docsTotal) ?></div>
                <div class="acct-admin-stat-label">Docs generated</div>
            </div>
        </div>

        <div class="acct-admin-charts">
            <section class="acct-card">
                <h2 class="acct-card-title">Conversions &middot; last 7 days</h2>
                <div class="acct-bar-chart">
                    <?php foreach ($series as $point): ?>
                    <div class="acct-bar-col">
                        <div class="acct-bar <?= $point['isToday'] ? 'is-today' : '' ?>" style="height:<?= (int) round($point['count'] / $seriesMax * 100) ?>%" title="<?= Html::e((string) $point['count']) ?> conversions"></div>
                        <span class="acct-bar-label"><?= Html::e($point['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="acct-card">
                <h2 class="acct-card-title">Top agencies by usage</h2>
                <?php if (count($topAgencies) === 0): ?>
                    <div class="acct-empty-note">No conversions recorded in the last 7 days yet.</div>
                <?php else: ?>
                <ul class="acct-rank-list">
                    <?php foreach ($topAgencies as $i => $ag): ?>
                    <li>
                        <span class="acct-rank-num"><?= $i + 1 ?></span>
                        <span class="acct-rank-name"><?= Html::e((string) $ag['name']) ?></span>
                        <div class="acct-rank-bar"><div class="acct-rank-bar-fill" style="width:<?= (int) round((int) $ag['total'] / $topAgenciesMax * 100) ?>%"></div></div>
                        <span class="acct-rank-count"><?= Html::e((string) $ag['total']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>

        <div class="acct-grid">
            <!-- Agencies + credit top-up -->
            <section class="acct-card acct-card-wide">
                <h2 class="acct-card-title">Agencies</h2>
                <table class="acct-table">
                    <thead><tr><th>Name</th><th>Credit balance</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($agencies as $ag): ?>
                        <tr>
                            <td><?= Html::e((string) $ag['name']) ?></td>
                            <td><strong><?= Html::e((string) $ag['credit_balance']) ?></strong></td>
                            <td><?= ((int) $ag['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
                            <td>
                                <button type="button" class="acct-topup-open" data-agency-id="<?= Html::e((string) $ag['id']) ?>" data-agency-name="<?= Html::e((string) $ag['name']) ?>" data-agency-balance="<?= Html::e((string) $ag['credit_balance']) ?>">Top up</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <!-- Users -->
            <section class="acct-card acct-card-wide">
                <h2 class="acct-card-title">Users</h2>
                <table class="acct-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Agency</th><th>Role</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= Html::e((string) $u['name']) ?></td>
                            <td><?= Html::e((string) $u['email']) ?></td>
                            <td><?= Html::e((string) ($u['agency_name'] ?? '—')) ?></td>
                            <td><?= Html::e(ucfirst((string) $u['role'])) ?></td>
                            <td><?= ((int) $u['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
                            <td style="white-space:nowrap;">
                                <?php if (in_array($u['role'], ['agent', 'internal'], true)): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_internal">
                                    <input type="hidden" name="user_id" value="<?= Html::e((string) $u['id']) ?>">
                                    <button type="submit" class="acct-pill-btn"><?= $u['role'] === 'internal' ? 'Unflag internal' : 'Flag internal' ?></button>
                                </form>
                                <?php endif; ?>
                                <?php if ($u['role'] !== 'superadmin'): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= Html::e((string) $u['id']) ?>">
                                    <button type="submit" class="acct-pill-btn"><?= ((int) $u['is_active']) === 1 ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
    </div>
</div>

<div class="acct-modal-backdrop" id="topupBackdrop" hidden>
    <div class="acct-modal" role="dialog" aria-modal="true" aria-labelledby="topupTitle">
        <div class="acct-modal-header">
            <span class="acct-modal-icon">&#9889;</span>
            <div>
                <div class="acct-modal-title" id="topupTitle">Top up credits</div>
                <div class="acct-modal-sub" id="topupAgencyName"></div>
            </div>
        </div>
        <form method="post" id="topupForm">
            <input type="hidden" name="action" value="add_credits">
            <input type="hidden" name="agency_id" id="topupAgencyId" value="">
            <input type="hidden" name="reason" id="topupReason" value="Manual top-up">
            <div class="acct-topup-quick" id="topupQuick">
                <button type="button" class="acct-topup-quick-btn" data-amount="25">+25</button>
                <button type="button" class="acct-topup-quick-btn" data-amount="50">+50</button>
                <button type="button" class="acct-topup-quick-btn" data-amount="100">+100</button>
                <button type="button" class="acct-topup-quick-btn" data-amount="custom">Custom</button>
            </div>
            <div class="auth-field" id="topupCustomField" hidden>
                <label class="auth-label" for="topupCustomAmount">Custom amount (use a minus sign to deduct)</label>
                <input class="auth-input" type="number" id="topupCustomAmount" name="amount" placeholder="e.g. 75 or -10">
            </div>
            <input type="hidden" name="amount" id="topupAmount" value="">
            <div class="acct-modal-actions">
                <button type="button" class="acct-pill-btn" id="topupCancel">Cancel</button>
                <button type="submit" class="auth-submit acct-btn-sm" id="topupSubmit" disabled>Add credits</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var backdrop = document.getElementById('topupBackdrop');
    var agencyNameEl = document.getElementById('topupAgencyName');
    var agencyIdInput = document.getElementById('topupAgencyId');
    var reasonInput = document.getElementById('topupReason');
    var amountInput = document.getElementById('topupAmount');
    var customField = document.getElementById('topupCustomField');
    var customAmount = document.getElementById('topupCustomAmount');
    var submitBtn = document.getElementById('topupSubmit');
    var quickBtns = document.querySelectorAll('.acct-topup-quick-btn');

    function openModal(id, name, balance) {
        agencyIdInput.value = id;
        agencyNameEl.textContent = name + ' — balance ' + balance + ' credits';
        amountInput.value = '';
        customField.hidden = true;
        submitBtn.disabled = true;
        quickBtns.forEach(function (b) { b.classList.remove('is-selected'); });
        backdrop.hidden = false;
    }
    function closeModal() { backdrop.hidden = true; }

    document.querySelectorAll('.acct-topup-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.dataset.agencyId, btn.dataset.agencyName, btn.dataset.agencyBalance);
        });
    });

    quickBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            quickBtns.forEach(function (b) { b.classList.remove('is-selected'); });
            btn.classList.add('is-selected');
            if (btn.dataset.amount === 'custom') {
                customField.hidden = false;
                reasonInput.value = 'Manual adjustment';
                amountInput.value = '';
                submitBtn.disabled = true;
                customAmount.focus();
            } else {
                customField.hidden = true;
                reasonInput.value = 'Manual top-up';
                amountInput.value = btn.dataset.amount;
                submitBtn.disabled = false;
            }
        });
    });

    customAmount.addEventListener('input', function () {
        amountInput.value = customAmount.value;
        submitBtn.disabled = customAmount.value.trim() === '' || Number(customAmount.value) === 0;
    });

    document.getElementById('topupCancel').addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !backdrop.hidden) closeModal(); });
})();
</script>
</body>
</html>
