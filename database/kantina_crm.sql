-- ============================================================
-- Kantina CRM database (customer relationship management)
-- Owns: customer records, the customer timeline (orders, point
-- changes, tickets), loyalty points and tiers, feedback tickets,
-- CRM admin accounts and the log of webhooks sent to Kantina.
-- Tables use the crm_ prefix so this can share one database
-- with the other apps if the host only allows one.
-- Import into the database named in crm/config (default: kantina_crm).
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS crm_tickets;
DROP TABLE IF EXISTS crm_interactions;
DROP TABLE IF EXISTS crm_customers;
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

-- One row per Kantina student. Created the first time Kantina sends
-- an order or a ticket for them; name/email are refreshed on every call.
CREATE TABLE crm_customers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kantina_user_id INT UNSIGNED  NOT NULL UNIQUE,
  student_id      VARCHAR(20)   NULL,
  name            VARCHAR(120)  NOT NULL,
  email           VARCHAR(120)  NULL,
  orders_count    INT UNSIGNED  NOT NULL DEFAULT 0,
  total_spent     DECIMAL(10,2) NOT NULL DEFAULT 0,
  points          INT UNSIGNED  NOT NULL DEFAULT 0,
  tier            ENUM('Bronze','Silver','Gold') NOT NULL DEFAULT 'Bronze',
  first_order_at  DATETIME      NULL,
  last_order_at   DATETIME      NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_crm_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer timeline. UNIQUE (type, ref) means the same Kantina order is
-- never counted twice, even if Kantina's outbox sends it again.
CREATE TABLE crm_interactions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED  NOT NULL,
  type        ENUM('order_completed','points_adjusted','ticket') NOT NULL,
  ref         VARCHAR(60)   NOT NULL,
  amount      DECIMAL(10,2) NULL,
  points      INT           NOT NULL DEFAULT 0,
  note        VARCHAR(255)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_interaction (type, ref),
  KEY idx_crm_interactions_customer (customer_id, created_at),
  CONSTRAINT fk_crm_interactions_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Feedback / support tickets. external_ref is Kantina's feedback id, so
-- sending the same feedback twice returns the existing ticket.
CREATE TABLE crm_tickets (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no    VARCHAR(20)  NULL UNIQUE,
  external_ref VARCHAR(40)  NOT NULL UNIQUE,
  customer_id  INT UNSIGNED NOT NULL,
  order_ref    VARCHAR(40)  NULL,
  stall_id     VARCHAR(40)  NULL,
  category     ENUM('order_issue','complaint','suggestion','praise') NOT NULL,
  rating       TINYINT UNSIGNED NULL,
  message      TEXT         NOT NULL,
  status       ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  reply        TEXT         NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at  DATETIME     NULL,
  KEY idx_crm_tickets_status (status, created_at),
  CONSTRAINT fk_crm_tickets_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demo admin (password: kantina123)
INSERT INTO crm_admins (username, name, password_hash) VALUES
('admin', 'Canteen Admin', '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe');

-- ------------------------------------------------------------
-- Demo data: the same three students as Kantina's demo users
-- (users.id 1–3 in kantina.sql). Ana's two orders match Kantina's
-- completed demo orders KTN-90155 (₱60) and KTN-90180 (₱150).
-- Loyalty: 1 point per ₱10 spent (rounded down per order);
-- Bronze < 100, Silver 100–299, Gold 300+.
-- ------------------------------------------------------------
INSERT INTO crm_customers (id, kantina_user_id, student_id, name, email, orders_count, total_spent, points, tier, first_order_at, last_order_at) VALUES
(1, 1, '21-0942', 'Ana Reyes',    'ana.reyes@example.com',    2, 210.00, 121, 'Silver',
    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:31:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:32:00')),
(2, 2, '22-1188', 'Leo Martinez', 'leo.martinez@example.com', 0,   0.00,  20, 'Bronze', NULL, NULL),
(3, 3, '23-0571', 'Mia Santos',   'mia.santos@example.com',   0,   0.00, 320, 'Gold',   NULL, NULL);

INSERT INTO crm_interactions (customer_id, type, ref, amount, points, note, created_at) VALUES
(1, 'order_completed', 'KTN-90155', 60.00,   6, 'Kape Kanto',            TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:31:00')),
(1, 'order_completed', 'KTN-90180', 150.00, 15, 'Lola''s Silog',         TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:32:00')),
(1, 'points_adjusted', 'ADJ-SEED-1', NULL, 100, 'Campus food fair bonus (by admin)', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '10:00:00')),
(1, 'ticket',          'TCK-0001',   NULL,   0, 'Order issue · KTN-90180', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:45:00')),
(2, 'points_adjusted', 'ADJ-SEED-2', NULL,  20, 'Welcome points (by admin)', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 3 DAY), '08:00:00')),
(2, 'ticket',          'TCK-0002',   NULL,   0, 'Suggestion',              TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:10:00')),
(3, 'points_adjusted', 'ADJ-SEED-3', NULL, 320, 'Moved from the paper loyalty card (by admin)', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 6 DAY), '09:00:00')),
(3, 'ticket',          'TCK-0003',   NULL,   0, 'Praise',                  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 4 DAY), '12:20:00'));

INSERT INTO crm_tickets (id, ticket_no, external_ref, customer_id, order_ref, stall_id, category, rating, message, status, reply, created_at, resolved_at) VALUES
(1, 'TCK-0001', 'FB-SEED-1', 1, 'KTN-90180', 'lolas-silog', 'order_issue', 2,
    'My Tapsilog was cold when I picked it up, even though I came on time.', 'resolved',
    'Sorry about that, Ana! We''ve asked the stall to hold orders on the warmer until pickup. Your next order gets free extra rice.',
    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:45:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '09:15:00')),
(2, 'TCK-0002', 'FB-SEED-2', 2, NULL, NULL, 'suggestion', NULL,
    'Please add more vegetarian meals. Most stalls only have meat dishes.', 'open', NULL,
    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:10:00'), NULL),
(3, 'TCK-0003', 'FB-SEED-3', 3, NULL, 'kape-kanto', 'praise', 5,
    'The iced coffee at Kape Kanto is the best on campus, and pre-ordering saves me so much time.', 'in_progress', NULL,
    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 4 DAY), '12:20:00'), NULL);

ALTER TABLE crm_tickets AUTO_INCREMENT = 4;
