<?php
declare(strict_types=1);

// One-time web setup page for hosts without SSH/Terminal access.
// Self-locking: refuses to run once a superadmin already exists, so it is
// safe to leave on the server (though deleting it afterward is still best
// practice). Alternative to bin/create-admin.php for shell-less hosting.

use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\DB;
use RoamingNepal\PnrConverter\Support\Html;

require_once __DIR__ . '/app/bootstrap.php';

$settings = load_settings();
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');

$pdo = DB::conn();
$existingSuperadmin = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' LIMIT 1")->fetch();

$error = null;
$done = false;

if ($existingSuperadmin !== false) {
    $error = 'A superadmin account already exists. This setup page is now locked — delete setup-admin.php from the server.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($name === '' || $email === '') {
        $error = 'Enter a name and email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        $agency = $pdo->query("SELECT id FROM agencies WHERE slug = 'roaming-nepal' LIMIT 1")->fetch();
        if ($agency === false) {
            $error = "Seed agency 'roaming-nepal' not found — import schema.sql first.";
        } else {
            $pdo->prepare(
                'INSERT INTO users (agency_id, email, password_hash, name, role) VALUES (:aid, :email, :hash, :name, "superadmin")'
            )->execute([
                'aid' => $agency['id'],
                'email' => $email,
                'hash' => Auth::hashPassword($password),
                'name' => $name,
            ]);
            $done = true;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup — <?= Html::e($agencyName) ?> PNR Converter</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-brand">
        <div class="auth-brand-logo"><span><?= Html::e($agencyName) ?></span></div>
        <div class="auth-brand-mid">
            <div class="auth-brand-title">One-time superadmin setup.</div>
            <div class="auth-brand-sub">This page creates the first superadmin account. It locks itself after one successful use.</div>
        </div>
    </div>
    <div class="auth-main">
        <div class="auth-card">
            <?php if ($done): ?>
                <div class="auth-card-title">Superadmin created</div>
                <div class="auth-alert auth-alert-ok">Account created. For security, delete <code>setup-admin.php</code> from the server now, then sign in.</div>
                <div class="auth-foot"><a href="login.php">Go to sign in</a></div>
            <?php else: ?>
                <div class="auth-card-title">Create superadmin</div>
                <div class="auth-card-sub">Runs once. This page refuses to run again after a superadmin exists.</div>

                <?php if ($error !== null): ?>
                    <div class="auth-alert"><?= Html::e($error) ?></div>
                <?php endif; ?>

                <?php if ($existingSuperadmin === false): ?>
                <form method="post" autocomplete="off">
                    <div class="auth-field">
                        <label class="auth-label" for="name">Your name</label>
                        <input class="auth-input" type="text" id="name" name="name" required value="<?= Html::e($_POST['name'] ?? '') ?>">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="email">Email</label>
                        <input class="auth-input" type="email" id="email" name="email" required value="<?= Html::e($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="password">Password</label>
                        <input class="auth-input" type="password" id="password" name="password" required minlength="8">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="password_confirm">Confirm password</label>
                        <input class="auth-input" type="password" id="password_confirm" name="password_confirm" required minlength="8">
                    </div>
                    <button class="auth-submit" type="submit">Create superadmin</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
