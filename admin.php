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
            <span class="rail-item is-disabled" title="Visa Itinerary — coming soon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <span class="rail-item-label">Visa Doc</span>
            </span>
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
        <div class="acct-header">
            <h1 class="acct-title">Admin</h1>
            <span class="acct-role-badge">Superadmin</span>
        </div>

        <?php if ($notice !== null): ?><div class="alert" style="background:rgba(16,185,129,.09);color:#059669;border:1px solid rgba(16,185,129,.22);"><?= Html::e($notice) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="alert alert-danger"><?= Html::e($error) ?></div><?php endif; ?>

        <div class="acct-grid">
            <!-- Agencies + credit top-up -->
            <section class="acct-card acct-card-wide">
                <h2 class="acct-card-title">Agencies</h2>
                <table class="acct-table">
                    <thead><tr><th>Name</th><th>Credit balance</th><th>Status</th><th>Top up</th></tr></thead>
                    <tbody>
                        <?php foreach ($agencies as $ag): ?>
                        <tr>
                            <td><?= Html::e((string) $ag['name']) ?></td>
                            <td><strong><?= Html::e((string) $ag['credit_balance']) ?></strong></td>
                            <td><?= ((int) $ag['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
                            <td>
                                <form method="post" class="acct-credit-form">
                                    <input type="hidden" name="action" value="add_credits">
                                    <input type="hidden" name="agency_id" value="<?= Html::e((string) $ag['id']) ?>">
                                    <input type="hidden" name="reason" value="Manual top-up">
                                    <button type="submit" name="amount" value="25" class="acct-pill-btn">+25</button>
                                    <button type="submit" name="amount" value="50" class="acct-pill-btn">+50</button>
                                    <button type="submit" name="amount" value="100" class="acct-pill-btn">+100</button>
                                </form>
                                <form method="post" class="acct-credit-form" style="margin-top:6px;">
                                    <input type="hidden" name="action" value="add_credits">
                                    <input type="hidden" name="agency_id" value="<?= Html::e((string) $ag['id']) ?>">
                                    <input type="hidden" name="reason" value="Manual adjustment">
                                    <input class="auth-input" type="number" name="amount" placeholder="Custom &plusmn;" style="width:110px; padding:7px 10px;">
                                    <button type="submit" class="acct-pill-btn">Apply</button>
                                </form>
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
</body>
</html>
