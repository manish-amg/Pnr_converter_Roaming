<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\DB;
use RoamingNepal\PnrConverter\Support\Html;

require_once __DIR__ . '/app/bootstrap.php';

Auth::start();
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$settings = load_settings();
$agencyName = (string) ($settings['agency_name'] ?? 'Roaming Nepal');

$error = null;
$agencyNameInput = (string) ($_POST['agency_name'] ?? '');
$ownerNameInput  = (string) ($_POST['owner_name'] ?? '');
$emailInput      = (string) ($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (trim($agencyNameInput) === '' || trim($ownerNameInput) === '' || trim($emailInput) === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = DB::conn();
        $emailLower = strtolower(trim($emailInput));

        $existing = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => $emailLower]);
        if ($existing->fetch() !== false) {
            $error = 'An account with that email already exists.';
        } else {
            $slugBase = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($agencyNameInput)) ?? '', '-');
            $slug = $slugBase !== '' ? $slugBase : 'agency';
            $suffix = 1;
            $checkSlug = $pdo->prepare('SELECT id FROM agencies WHERE slug = :slug LIMIT 1');
            while (true) {
                $checkSlug->execute(['slug' => $slug]);
                if ($checkSlug->fetch() === false) {
                    break;
                }
                $suffix++;
                $slug = $slugBase . '-' . $suffix;
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO agencies (name, slug, credit_balance) VALUES (:name, :slug, 0)')
                    ->execute(['name' => trim($agencyNameInput), 'slug' => $slug]);
                $agencyId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO users (agency_id, email, password_hash, name, role) VALUES (:aid, :email, :hash, :name, "owner")'
                )->execute([
                    'aid' => $agencyId,
                    'email' => $emailLower,
                    'hash' => Auth::hashPassword($password),
                    'name' => trim($ownerNameInput),
                ]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            Auth::attempt($emailLower, $password);
            header('Location: index.php');
            exit;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account — <?= Html::e($agencyName) ?> PNR Converter</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-brand">
        <div class="auth-brand-logo"><span><?= Html::e($agencyName) ?></span></div>
        <div class="auth-brand-mid">
            <div class="auth-brand-title">Set up your agency in under a minute.</div>
            <div class="auth-brand-sub">Create your agency workspace, invite your team, and start converting PNRs into branded itineraries.</div>
        </div>
        <div class="auth-brand-foot">&copy; <?= date('Y') ?> <?= Html::e($agencyName) ?>. Internal tool.</div>
    </div>
    <div class="auth-main">
        <div class="auth-card">
            <div class="auth-card-title">Create your agency</div>
            <div class="auth-card-sub">You'll be the owner of this workspace.</div>

            <?php if ($error !== null): ?>
                <div class="auth-alert"><?= Html::e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="auth-field">
                    <label class="auth-label" for="agency_name">Agency name</label>
                    <input class="auth-input" type="text" id="agency_name" name="agency_name" required value="<?= Html::e($agencyNameInput) ?>">
                </div>
                <div class="auth-field">
                    <label class="auth-label" for="owner_name">Your name</label>
                    <input class="auth-input" type="text" id="owner_name" name="owner_name" required value="<?= Html::e($ownerNameInput) ?>">
                </div>
                <div class="auth-field">
                    <label class="auth-label" for="email">Email</label>
                    <input class="auth-input" type="email" id="email" name="email" required value="<?= Html::e($emailInput) ?>">
                </div>
                <div class="auth-field">
                    <label class="auth-label" for="password">Password</label>
                    <input class="auth-input" type="password" id="password" name="password" required minlength="8">
                </div>
                <div class="auth-field">
                    <label class="auth-label" for="password_confirm">Confirm password</label>
                    <input class="auth-input" type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>
                <button class="auth-submit" type="submit">Create account</button>
            </form>

            <div class="auth-foot">Already have an account? <a href="login.php">Sign in</a></div>
        </div>
    </div>
</div>
</body>
</html>
