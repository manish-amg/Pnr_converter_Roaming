<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Support;

use PDO;
use PDOException;

final class DB
{
    private static ?PDO $instance = null;

    public static function conn(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $settings = load_settings();
        $cfg = $settings['db'] ?? [];
        $host = (string) ($cfg['host'] ?? 'localhost');
        $name = (string) ($cfg['name'] ?? '');
        $user = (string) ($cfg['user'] ?? '');
        $pass = (string) ($cfg['pass'] ?? '');
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }

        return self::$instance;
    }
}
