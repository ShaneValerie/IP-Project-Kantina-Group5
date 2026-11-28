-- ============================================================
-- Kantina CRM database (customer relationship management)
-- Owns: CRM admin accounts and the log of webhooks sent to Kantina.
-- The CRM tables (customers, loyalty, feedback tickets) are added
-- here as the CRM features are built.
-- Tables use the crm_ prefix so this can share one database
-- with the other apps if the host only allows one.
-- Import into the database named in crm/config (default: kantina_crm).
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS crm_webhook_deliveries;
DROP TABLE IF EXISTS crm_admins;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE crm_admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(40)  NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhooks sent to Kantina (kept so failed ones can be resent)
CREATE TABLE crm_webhook_deliveries (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id      VARCHAR(40)  NOT NULL UNIQUE,
  event         VARCHAR(60)  NOT NULL,
  payload       TEXT         NOT NULL,
  status        ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  attempts      INT          NOT NULL DEFAULT 0,
  response_code SMALLINT     NULL,
  last_error    TEXT         NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at  DATETIME     NULL,
  KEY idx_crm_wh_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demo admin (password: kantina123)
INSERT INTO crm_admins (username, name, password_hash) VALUES
('admin', 'Canteen Admin', '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe');
