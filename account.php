<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\DB;
use RoamingNepal\PnrConverter\Support\Html;

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireLogin('login.php');
$user = Auth::user();
$pdo = DB::conn();
$settings = load_settings();
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');
$isOwnerLike = in_array($user['role'], ['owner', 'superadmin'], true);

$notice = null;
$error = null;

$agencyStmt = $pdo->prepare('SELECT * FROM agencies WHERE id = :id LIMIT 1');
$agencyStmt->execute(['id' => $user['agency_id']]);
$agency = $agencyStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_agency' && $isOwnerLike && $agency !== false) {
        $name = trim((string) ($_POST['agency_name'] ?? ''));
        $phone = trim((string) ($_POST['contact_phone'] ?? ''));
        $email = trim((string) ($_POST['contact_email'] ?? ''));

        if ($name === '') {
            $error = 'Agency name cannot be empty.';
        } else {
            $pdo->prepare('UPDATE agencies SET name = :name, contact_phone = :phone, contact_email = :email WHERE id = :id')
                ->execute(['name' => $name, 'phone' => $phone, 'email' => $email, 'id' => $agency['id']]);
            $notice = 'Agency details updated.';
            $agencyStmt->execute(['id' => $user['agency_id']]);
            $agency = $agencyStmt->fetch();
        }
    }

    if ($action === 'add_agent' && $isOwnerLike && $agency !== false) {
        $name = trim((string) ($_POST['agent_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['agent_email'] ?? '')));
        $password = (string) ($_POST['agent_password'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 8) {
            $error = 'Enter a name, valid email, and a password of at least 8 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $exists->execute(['email' => $email]);
            if ($exists->fetch() !== false) {
                $error = 'That email is already registered.';
            } else {
                $pdo->prepare(
                    'INSERT INTO users (agency_id, email, password_hash, name, role) VALUES (:aid, :email, :hash, :name, "agent")'
                )->execute([
                    'aid' => $agency['id'],
                    'email' => $email,
                    'hash' => Auth::hashPassword($password),
                    'name' => $name,
                ]);
                $notice = 'Team member added.';
            }
        }
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => Auth::hashPassword($new), 'id' => $user['id']]);
            $notice = 'Password changed.';
        }
    }
}

$team = [];
if ($agency !== false) {
    $teamStmt = $pdo->prepare('SELECT id, name, email, role, is_active, created_at FROM users WHERE agency_id = :aid ORDER BY created_at ASC');
    $teamStmt->execute(['aid' => $agency['id']]);
    $team = $teamStmt->fetchAll();
}

$ledger = [];
if ($agency !== false) {
    $ledgerStmt = $pdo->prepare('SELECT * FROM credit_ledger WHERE agency_id = :aid ORDER BY created_at DESC LIMIT 10');
    $ledgerStmt->execute(['aid' => $agency['id']]);
    $ledger = $ledgerStmt->fetchAll();
}

$conversionsToday = Auth::conversionsToday();
$dailyLimit = Auth::dailyLimit();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account — <?= Html::e($agencyName) ?> PNR Converter</title>
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
            <a class="rail-item is-active" href="account.php" title="Account">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span class="rail-item-label">Account</span>
            </a>
            <?php if ($user['role'] === 'superadmin'): ?>
            <a class="rail-item" href="admin.php" title="Admin">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span class="rail-item-label">Admin</span>
            </a>
            <?php endif; ?>
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
            <h1 class="acct-title">Account</h1>
            <span class="acct-role-badge"><?= Html::e(ucfirst((string) $user['role'])) ?></span>
        </div>

        <?php if ($notice !== null): ?><div class="alert" style="background:rgba(16,185,129,.09);color:#059669;border:1px solid rgba(16,185,129,.22);"><?= Html::e($notice) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="alert alert-danger"><?= Html::e($error) ?></div><?php endif; ?>

        <div class="acct-grid">
            <!-- Profile card -->
            <section class="acct-card">
                <h2 class="acct-card-title">Your profile</h2>
                <div class="acct-kv"><span>Name</span><strong><?= Html::e((string) $user['name']) ?></strong></div>
                <div class="acct-kv"><span>Email</span><strong><?= Html::e((string) $user['email']) ?></strong></div>
                <div class="acct-kv"><span>Role</span><strong><?= Html::e(ucfirst((string) $user['role'])) ?></strong></div>
                <div class="acct-kv"><span>Today's conversions</span><strong><?= Auth::isInternal() ? 'Unlimited' : Html::e($conversionsToday . ' / ' . $dailyLimit) ?></strong></div>
            </section>

            <!-- Change password -->
            <section class="acct-card">
                <h2 class="acct-card-title">Change password</h2>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="change_password">
                    <div class="auth-field">
                        <label class="auth-label" for="current_password">Current password</label>
                        <input class="auth-input" type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="new_password">New password</label>
                        <input class="auth-input" type="password" id="new_password" name="new_password" required minlength="8">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="new_password_confirm">Confirm new password</label>
                        <input class="auth-input" type="password" id="new_password_confirm" name="new_password_confirm" required minlength="8">
                    </div>
                    <button class="auth-submit acct-btn-sm" type="submit">Update password</button>
                </form>
            </section>

            <?php if ($agency !== false): ?>
            <!-- Agency & credits -->
            <section class="acct-card">
                <h2 class="acct-card-title">Agency</h2>
                <div class="acct-kv"><span>Name</span><strong><?= Html::e((string) $agency['name']) ?></strong></div>
                <div class="acct-kv"><span>Credit balance</span><strong class="acct-credit"><?= Html::e((string) $agency['credit_balance']) ?></strong></div>

                <?php if ($isOwnerLike): ?>
                <form method="post" autocomplete="off" class="acct-inline-form">
                    <input type="hidden" name="action" value="update_agency">
                    <div class="auth-field">
                        <label class="auth-label" for="agency_name">Agency name</label>
                        <input class="auth-input" type="text" id="agency_name" name="agency_name" value="<?= Html::e((string) $agency['name']) ?>" required>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="contact_phone">Contact phone</label>
                        <input class="auth-input" type="text" id="contact_phone" name="contact_phone" value="<?= Html::e((string) $agency['contact_phone']) ?>">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="contact_email">Contact email</label>
                        <input class="auth-input" type="email" id="contact_email" name="contact_email" value="<?= Html::e((string) $agency['contact_email']) ?>">
                    </div>
                    <button class="auth-submit acct-btn-sm" type="submit">Save agency details</button>
                </form>
                <?php endif; ?>

                <?php if (count($ledger) > 0): ?>
                <h3 class="acct-subtitle">Recent credit activity</h3>
                <ul class="acct-ledger">
                    <?php foreach ($ledger as $row): ?>
                        <li>
                            <span class="acct-ledger-delta <?= ((int) $row['delta']) >= 0 ? 'is-pos' : 'is-neg' ?>"><?= ((int) $row['delta']) >= 0 ? '+' : '' ?><?= Html::e((string) $row['delta']) ?></span>
                            <span class="acct-ledger-reason"><?= Html::e((string) $row['reason']) ?></span>
                            <span class="acct-ledger-date"><?= Html::e(date('d M, H:i', strtotime((string) $row['created_at']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <!-- Team -->
            <?php if ($isOwnerLike): ?>
            <section class="acct-card acct-card-wide">
                <h2 class="acct-card-title">Team</h2>
                <table class="acct-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($team as $member): ?>
                        <tr>
                            <td><?= Html::e((string) $member['name']) ?></td>
                            <td><?= Html::e((string) $member['email']) ?></td>
                            <td><?= Html::e(ucfirst((string) $member['role'])) ?></td>
                            <td><?= ((int) $member['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 class="acct-subtitle">Add a team member</h3>
                <form method="post" autocomplete="off" class="acct-inline-form">
                    <input type="hidden" name="action" value="add_agent">
                    <div class="auth-field">
                        <label class="auth-label" for="agent_name">Name</label>
                        <input class="auth-input" type="text" id="agent_name" name="agent_name" required>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="agent_email">Email</label>
                        <input class="auth-input" type="email" id="agent_email" name="agent_email" required>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="agent_password">Temporary password</label>
                        <input class="auth-input" type="password" id="agent_password" name="agent_password" required minlength="8">
                    </div>
                    <button class="auth-submit acct-btn-sm" type="submit">Add team member</button>
                </form>
            </section>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    </div>
</div>
</body>
</html>
