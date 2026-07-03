<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\Html;

require_once __DIR__ . '/app/bootstrap.php';

Auth::start();
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$settings = load_settings();
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');
$logoPath = (string) ($settings['logo_path'] ?? '');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (trim($email) === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (Auth::attempt($email, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Incorrect email or password.';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — <?= Html::e($agencyName) ?> PNR Converter</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-brand">
        <div class="auth-brand-logo">
            <?php if ($logoPath !== '' && is_file(__DIR__ . '/' . ltrim($logoPath, '/'))): ?>
                <img src="<?= Html::e($logoPath) ?>" alt="<?= Html::e($agencyName) ?>">
            <?php endif; ?>
            <span><?= Html::e($agencyName) ?></span>
        </div>
        <div class="auth-brand-mid">
            <div class="auth-brand-title">Turn raw GDS text into branded itineraries in seconds.</div>
            <div class="auth-brand-sub">Sign in with your agency account to convert PNRs, manage your team, and track credit usage.</div>
        </div>
        <div class="auth-brand-foot">&copy; <?= date('Y') ?> <?= Html::e($agencyName) ?>. Internal tool.</div>
    </div>
    <div class="auth-main">
        <div class="auth-card">
            <div class="auth-card-title">Sign in</div>
            <div class="auth-card-sub">Enter your credentials to continue.</div>

            <?php if ($error !== null): ?>
                <div class="auth-alert"><?= Html::e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="auth-field">
                    <label class="auth-label" for="email">Email</label>
                    <input class="auth-input" type="email" id="email" name="email" required autofocus value="<?= Html::e($_POST['email'] ?? '') ?>">
                </div>
                <div class="auth-field">
                    <label class="auth-label" for="password">Password</label>
                    <input class="auth-input" type="password" id="password" name="password" required>
                </div>
                <button class="auth-submit" type="submit">Sign in</button>
            </form>

            <div class="auth-foot">New agency? <a href="register.php">Create an account</a></div>
            <div class="auth-foot">Just want to convert a PNR? <a href="index.php">Continue as guest</a></div>
        </div>
    </div>
</div>
</body>
</html>
