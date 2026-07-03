<?php
declare(strict_types=1);

// One-off CLI setup script. Run once from the server after importing schema.sql:
//   php bin/create-admin.php you@example.com "Your Name" "a-strong-password"
// Creates (or promotes) a superadmin user under the seeded 'roaming-nepal' agency.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require_once __DIR__ . '/../app/bootstrap.php';

use RoamingNepal\PnrConverter\Support\Auth;
use RoamingNepal\PnrConverter\Support\DB;

[, $email, $name, $password] = array_pad($argv, 4, null);

if ($email === null || $name === null || $password === null) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <email> <name> <password>\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo = DB::conn();

$agency = $pdo->query("SELECT id FROM agencies WHERE slug = 'roaming-nepal' LIMIT 1")->fetch();
if ($agency === false) {
    fwrite(STDERR, "Seed agency 'roaming-nepal' not found — import schema.sql first.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (agency_id, email, password_hash, name, role)
     VALUES (:agency_id, :email, :hash, :name, "superadmin")
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name), role = "superadmin", is_active = 1'
);
$stmt->execute([
    'agency_id' => $agency['id'],
    'email' => strtolower(trim($email)),
    'hash' => Auth::hashPassword($password),
    'name' => $name,
]);

echo "Superadmin account ready: {$email}\n";
