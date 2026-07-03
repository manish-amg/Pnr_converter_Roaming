-- ═══════════════════════════════════════════════════════════════
-- PNR Converter — Phase 2 schema
-- MySQL / MariaDB (GoDaddy cPanel). Run once via phpMyAdmin or CLI.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS agencies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(160) NOT NULL,
    slug            VARCHAR(160) NOT NULL UNIQUE,
    logo_path       VARCHAR(255) NULL,
    brand_color     VARCHAR(9)   NULL,
    contact_phone   VARCHAR(120) NULL,
    contact_email   VARCHAR(190) NULL,
    credit_balance  INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agency_id       INT UNSIGNED NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    name            VARCHAR(160) NOT NULL,
    role            ENUM('superadmin','owner','agent','internal') NOT NULL DEFAULT 'agent',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME NULL,
    CONSTRAINT fk_users_agency FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_ledger (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agency_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NULL,
    delta           INT NOT NULL,
    reason          VARCHAR(160) NOT NULL,
    balance_after   INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ledger_agency FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usage_daily (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    usage_date          DATE NOT NULL,
    conversions_count   INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_user_date (user_id, usage_date),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agency_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    type            VARCHAR(40) NOT NULL DEFAULT 'visa_itinerary',
    pnr_text_hash   CHAR(64) NULL,
    credits_used    INT NOT NULL DEFAULT 1,
    verify_token    CHAR(32) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_verify_token (verify_token),
    CONSTRAINT fk_documents_agency FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the house agency. Create the first superadmin via bin/create-admin.php
-- (run once from the server after this schema is imported) — do not hardcode
-- a password hash here, since it must be generated with PHP's password_hash().
INSERT INTO agencies (name, slug, credit_balance)
VALUES ('Roaming Nepal Travel & Tours', 'roaming-nepal', 0)
ON DUPLICATE KEY UPDATE name = name;
