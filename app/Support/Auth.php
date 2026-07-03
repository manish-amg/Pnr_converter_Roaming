<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Support;

final class Auth
{
    private const SESSION_KEY = 'pnrc_user_id';
    private const DAILY_FREE_LIMIT = 50;

    private static ?array $cachedUser = null;
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;
    }

    public static function attempt(string $email, string $password): bool
    {
        self::start();

        $stmt = DB::conn()->prepare(
            'SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::$cachedUser = $user;

        DB::conn()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        self::start();

        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }

        $stmt = DB::conn()->prepare('SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $user = $stmt->fetch();
        if ($user === false) {
            return null;
        }

        self::$cachedUser = $user;
        return $user;
    }

    public static function requireLogin(string $redirectTo = '/login.php'): void
    {
        if (!self::check()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    public static function requireRole(array $roles): void
    {
        $user = self::user();
        if ($user === null || !in_array($user['role'], $roles, true)) {
            http_response_code(403);
            echo 'Forbidden — you do not have access to this page.';
            exit;
        }
    }

    public static function isRole(string $role): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === $role;
    }

    public static function isInternal(): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role'], ['internal', 'superadmin'], true);
    }

    /** True when the current user has hit today's free-conversion cap. */
    public static function dailyLimitReached(): bool
    {
        $user = self::user();
        if ($user === null || self::isInternal()) {
            return false;
        }

        $stmt = DB::conn()->prepare(
            'SELECT conversions_count FROM usage_daily WHERE user_id = :uid AND usage_date = CURDATE() LIMIT 1'
        );
        $stmt->execute(['uid' => $user['id']]);
        $row = $stmt->fetch();

        return $row !== false && (int) $row['conversions_count'] >= self::DAILY_FREE_LIMIT;
    }

    public static function dailyLimit(): int
    {
        return self::DAILY_FREE_LIMIT;
    }

    public static function conversionsToday(): int
    {
        $user = self::user();
        if ($user === null) {
            return 0;
        }

        $stmt = DB::conn()->prepare(
            'SELECT conversions_count FROM usage_daily WHERE user_id = :uid AND usage_date = CURDATE() LIMIT 1'
        );
        $stmt->execute(['uid' => $user['id']]);
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['conversions_count'] : 0;
    }

    public static function recordConversion(): void
    {
        $user = self::user();
        if ($user === null) {
            return;
        }

        DB::conn()->prepare(
            'INSERT INTO usage_daily (user_id, usage_date, conversions_count)
             VALUES (:uid, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE conversions_count = conversions_count + 1'
        )->execute(['uid' => $user['id']]);
    }

    public static function creditBalance(int $agencyId): int
    {
        $stmt = DB::conn()->prepare('SELECT credit_balance FROM agencies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $agencyId]);
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['credit_balance'] : 0;
    }

    /** Adds (or subtracts, if $amount is negative) credits and logs the ledger entry. */
    public static function addCredits(int $agencyId, int $amount, string $reason, ?int $actingUserId = null): int
    {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE agencies SET credit_balance = credit_balance + :amt WHERE id = :id')
                ->execute(['amt' => $amount, 'id' => $agencyId]);

            $balance = self::creditBalance($agencyId);

            $pdo->prepare(
                'INSERT INTO credit_ledger (agency_id, user_id, delta, reason, balance_after)
                 VALUES (:aid, :uid, :delta, :reason, :bal)'
            )->execute([
                'aid' => $agencyId,
                'uid' => $actingUserId,
                'delta' => $amount,
                'reason' => $reason,
                'bal' => $balance,
            ]);

            $pdo->commit();
            return $balance;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Deducts one credit for a paid document. Returns false if the agency has no balance. */
    public static function deductCredit(int $agencyId, int $userId, string $reason): bool
    {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT credit_balance FROM agencies WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $agencyId]);
            $row = $stmt->fetch();

            if ($row === false || (int) $row['credit_balance'] < 1) {
                $pdo->rollBack();
                return false;
            }

            $pdo->prepare('UPDATE agencies SET credit_balance = credit_balance - 1 WHERE id = :id')
                ->execute(['id' => $agencyId]);

            $pdo->prepare(
                'INSERT INTO credit_ledger (agency_id, user_id, delta, reason, balance_after)
                 VALUES (:aid, :uid, -1, :reason, :bal)'
            )->execute([
                'aid' => $agencyId,
                'uid' => $userId,
                'reason' => $reason,
                'bal' => (int) $row['credit_balance'] - 1,
            ]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}
