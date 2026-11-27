-- ============================================================
-- Kantina database (main app)
-- Owns: users, orders and their status workflow, pickup slots,
--       tables and reservations, payments, notifications and the
--       integration layer (outbox, webhook log).
-- stalls / menu_items / announcements are a local CACHE of data
-- owned by Kantina CMS (content) and Kantina ERP (price, stock);
-- they are refreshed by webhooks and by the sync job.
-- Import into the database named in kantina/config (default: kantina).
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS request_log;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS webhook_log;
DROP TABLE IF EXISTS integration_outbox;
DROP TABLE IF EXISTS kv_store;
DROP TABLE IF EXISTS table_blocks;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS dining_tables;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS checkouts;
DROP TABLE IF EXISTS favourites;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS stalls;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Users ----------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role          ENUM('student','staff','admin') NOT NULL DEFAULT 'student',
  student_id    VARCHAR(20)  NULL UNIQUE,      -- students sign in with this or their email
  account       VARCHAR(40)  NULL UNIQUE,      -- staff/admin sign in with this (e.g. stall-01)
  email         VARCHAR(120) NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  program       VARCHAR(60)  NULL,
  grad_year     SMALLINT     NULL,
  stall_id      VARCHAR(40)  NULL,             -- staff only: the stall they work for
  notify_ready  TINYINT(1)   NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Catalog cache (from CMS + ERP) ----------
CREATE TABLE stalls (
  id                 VARCHAR(40)  NOT NULL PRIMARY KEY,
  name               VARCHAR(80)  NOT NULL,
  category           VARCHAR(40)  NOT NULL,
  description        TEXT         NULL,
  photo_path         VARCHAR(255) NULL,          -- path inside the CMS media folder
  counter_label      VARCHAR(40)  NOT NULL,
  prep_minutes       SMALLINT     NOT NULL DEFAULT 10,
  opens_at           TIME         NOT NULL,
  closes_at          TIME         NOT NULL,
  temporarily_closed TINYINT(1)   NOT NULL DEFAULT 0,
  closed_note        VARCHAR(80)  NULL,
  sort_order         INT          NOT NULL DEFAULT 0,
  synced_at          DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_items (
  sku           VARCHAR(40)   NOT NULL PRIMARY KEY,
  stall_id      VARCHAR(40)   NOT NULL,
  type          ENUM('menu','addon') NOT NULL DEFAULT 'menu',
  -- content (from CMS)
  section       VARCHAR(60)   NOT NULL DEFAULT 'Menu',
  name          VARCHAR(80)   NOT NULL,
  description   TEXT          NULL,
  photo_path    VARCHAR(255)  NULL,
  addon_skus    VARCHAR(255)  NULL,
  sort_order    INT           NOT NULL DEFAULT 0,
  is_published  TINYINT(1)    NOT NULL DEFAULT 1,
  cms_synced_at DATETIME      NULL,
  -- price and stock (from ERP)
  price         DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock         INT           NOT NULL DEFAULT 0,
  track_stock   TINYINT(1)    NOT NULL DEFAULT 1,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  erp_synced_at DATETIME      NULL,
  KEY idx_menu_stall (stall_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE announcements (
  id         INT UNSIGNED NOT NULL PRIMARY KEY,   -- same id as in the CMS
  title      VARCHAR(120) NOT NULL,
  body       TEXT         NULL,
  image_path VARCHAR(255) NULL,
  link_url   VARCHAR(255) NULL,
  starts_at  DATETIME     NULL,
  ends_at    DATETIME     NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  synced_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE favourites (
  user_id    INT UNSIGNED NOT NULL,
  stall_id   VARCHAR(40)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, stall_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Tables & reservations ----------
CREATE TABLE dining_tables (
  code   VARCHAR(8)  NOT NULL PRIMARY KEY,   -- T-01 ... T-40
  seats  TINYINT     NOT NULL,
  row_no TINYINT     NOT NULL,
  col_no TINYINT     NOT NULL,
  zone   VARCHAR(40) NOT NULL DEFAULT 'Main hall'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reservations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref           VARCHAR(20)  NULL UNIQUE,     -- RSV-1042
  user_id       INT UNSIGNED NOT NULL,
  table_code    VARCHAR(8)   NOT NULL,
  res_date      DATE         NOT NULL,
  start_time    TIME         NOT NULL,
  end_time      TIME         NOT NULL,
  party_size    TINYINT      NOT NULL,
  status        ENUM('booked','checked_in','completed','cancelled','released') NOT NULL DEFAULT 'booked',
  checked_in_at DATETIME     NULL,
  cancelled_at  DATETIME     NULL,
  released_at   DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_res_lookup (res_date, table_code, status),
  KEY idx_res_user (user_id, res_date),
  CONSTRAINT fk_res_user  FOREIGN KEY (user_id)    REFERENCES users(id),
  CONSTRAINT fk_res_table FOREIGN KEY (table_code) REFERENCES dining_tables(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slots blocked by staff (cleaning, events)
CREATE TABLE table_blocks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_code VARCHAR(8)   NOT NULL,
  block_date DATE         NOT NULL,
  slot_time  TIME         NOT NULL,
  reason     VARCHAR(80)  NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_block (table_code, block_date, slot_time),
  CONSTRAINT fk_block_table FOREIGN KEY (table_code) REFERENCES dining_tables(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Ordering ----------
-- One checkout = one payment. It contains one order per stall.
CREATE TABLE checkouts (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref            VARCHAR(20)   NULL UNIQUE,     -- CHK-10023
  user_id        INT UNSIGNED  NOT NULL,
  payment_method ENUM('gcash','counter') NOT NULL,
  payment_status ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  subtotal       DECIMAL(10,2) NOT NULL,
  service_fee    DECIMAL(10,2) NOT NULL,
  total          DECIMAL(10,2) NOT NULL,
  pickup_date    DATE          NOT NULL,
  pickup_time    TIME          NOT NULL,
  reservation_id INT UNSIGNED  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at        DATETIME      NULL,
  CONSTRAINT fk_chk_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_chk_res  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=10001;

-- A kitchen ticket for one stall. Status workflow:
-- pending -> paid -> preparing -> ready -> completed  (or cancelled)
CREATE TABLE orders (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,   -- shown to stalls as #90214
  ref               VARCHAR(20)   NULL UNIQUE,                 -- KTN-90214
  checkout_id       INT UNSIGNED  NOT NULL,
  user_id           INT UNSIGNED  NOT NULL,
  stall_id          VARCHAR(40)   NOT NULL,
  status            ENUM('pending','paid','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  subtotal          DECIMAL(10,2) NOT NULL,
  pickup_date       DATE          NOT NULL,
  pickup_time       TIME          NOT NULL,
  erp_status        ENUM('queued','synced','failed') NOT NULL DEFAULT 'queued',
  erp_so_no         VARCHAR(20)   NULL,
  cancel_reason     VARCHAR(160)  NULL,
  placed_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at           DATETIME      NULL,
  preparing_at      DATETIME      NULL,
  ready_at          DATETIME      NULL,
  completed_at      DATETIME      NULL,
  cancelled_at      DATETIME      NULL,
  status_changed_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_orders_stall (stall_id, status),
  KEY idx_orders_user (user_id, placed_at),
  KEY idx_orders_slot (stall_id, pickup_date, pickup_time),
  CONSTRAINT fk_order_checkout FOREIGN KEY (checkout_id) REFERENCES checkouts(id),
  CONSTRAINT fk_order_user     FOREIGN KEY (user_id)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED  NOT NULL,
  sku          VARCHAR(40)   NOT NULL,
  name         VARCHAR(80)   NOT NULL,
  qty          INT           NOT NULL,
  unit_price   DECIMAL(10,2) NOT NULL,
  addons       TEXT          NULL,          -- JSON: [{"sku":"LS-ADD-RICE","name":"Extra rice","price":15}]
  addons_total DECIMAL(10,2) NOT NULL DEFAULT 0,   -- per unit
  note         VARCHAR(200)  NULL,
  line_total   DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Integration layer ----------
-- Transactional outbox: calls to the ERP/CMS are written here in the
-- same DB transaction as the business change, then delivered (and
-- retried with backoff) so nothing is lost when the ERP is offline.
CREATE TABLE integration_outbox (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target          ENUM('erp','cms') NOT NULL,
  event           VARCHAR(60)  NOT NULL,       -- e.g. erp.sales_order.create
  aggregate_ref   VARCHAR(40)  NOT NULL,       -- e.g. KTN-90214 (keeps calls for one order in order)
  payload         TEXT         NOT NULL,
  status          ENUM('pending','sent','failed','dead') NOT NULL DEFAULT 'pending',
  attempts        INT          NOT NULL DEFAULT 0,
  next_attempt_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error      TEXT         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at         DATETIME     NULL,
  KEY idx_outbox_due (status, next_attempt_at),
  KEY idx_outbox_ref (aggregate_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incoming webhooks from the ERP / CMS (also used to ignore duplicates)
CREATE TABLE webhook_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source       ENUM('erp','cms','paymongo') NOT NULL,
  event        VARCHAR(60)  NULL,
  event_id     VARCHAR(64)  NULL,
  payload      TEXT         NULL,
  signature_ok TINYINT(1)   NOT NULL DEFAULT 0,
  status       ENUM('processed','rejected','failed','duplicate') NOT NULL,
  error        TEXT         NULL,
  received_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webhook_event (source, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  kind        VARCHAR(40)  NOT NULL,          -- order_received, order_ready, reservation_confirmed
  to_email    VARCHAR(120) NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  body        TEXT         NOT NULL,
  status      ENUM('sent','logged','failed') NOT NULL,
  provider_id VARCHAR(120) NULL,
  error       TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  method     VARCHAR(8)   NOT NULL,
  path       VARCHAR(255) NOT NULL,
  status     SMALLINT     NOT NULL,
  user_id    INT UNSIGNED NULL,
  ms         INT          NOT NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reqlog_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE kv_store (
  k          VARCHAR(60) NOT NULL PRIMARY KEY,
  v          TEXT        NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Seed data   (every demo password is: kantina123)
-- ------------------------------------------------------------
INSERT INTO users (id, role, student_id, account, email, name, program, grad_year, stall_id, password_hash) VALUES
(1, 'student', '21-0942', NULL,       'ana.reyes@example.com',    'Ana Reyes',            'BS CS', 2027, NULL,            '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(2, 'student', '22-1188', NULL,       'leo.martinez@example.com', 'Leo Martinez',         'BS IT', 2026, NULL,            '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(3, 'student', '23-0571', NULL,       'mia.santos@example.com',   'Mia Santos',           'BS IS', 2027, NULL,            '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(4, 'staff',   NULL,      'stall-01', NULL,                       'Lola''s Silog staff',  NULL,    NULL, 'lolas-silog',   '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(5, 'staff',   NULL,      'stall-02', NULL,                       'Kape Kanto staff',     NULL,    NULL, 'kape-kanto',    '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(6, 'staff',   NULL,      'stall-03', NULL,                       'Noodle Bar staff',     NULL,    NULL, 'noodle-bar',    '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(7, 'staff',   NULL,      'stall-04', NULL,                       'Grill Station staff',  NULL,    NULL, 'grill-station', '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe'),
(8, 'admin',   NULL,      'admin',    NULL,                       'Canteen Admin',        NULL,    NULL, NULL,            '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe');

INSERT INTO favourites (user_id, stall_id) VALUES (1, 'lolas-silog'), (1, 'kape-kanto');

-- Catalog cache: same data as the CMS and ERP seeds, so the app works
-- right after import. The first sync / webhook refreshes it.
INSERT INTO stalls (id, name, category, description, photo_path, counter_label, prep_minutes, opens_at, closes_at, temporarily_closed, closed_note, sort_order, synced_at) VALUES
('lolas-silog',   'Lola''s Silog', 'Rice meals', 'Classic Filipino silog plates cooked to order: garlic rice, a fried egg and your choice of ulam.', 'stalls/lolas-silog.jpg',   'Counter 2', 10, '07:00:00', '18:00:00', 0, NULL, 1, NOW()),
('kape-kanto',    'Kape Kanto',    'Drinks',     'Brewed and iced coffee, Spanish lattes and freshly baked merienda.',                                  'stalls/kape-kanto.jpg',    'Counter 1',  5, '07:00:00', '19:00:00', 0, NULL, 2, NOW()),
('noodle-bar',    'Noodle Bar',    'Noodles',    'Hot bowls of mami and lomi plus pancit for sharing.',                                                 'stalls/noodle-bar.jpg',    'Counter 3', 12, '14:00:00', '19:00:00', 0, NULL, 3, NOW()),
('grill-station', 'Grill Station', 'Snacks',     'Charcoal-grilled skewers and inasal, best with a cup of rice.',                                       'stalls/grill-station.jpg', 'Counter 4', 15, '13:00:00', '19:00:00', 0, NULL, 4, NOW());

INSERT INTO menu_items (sku, stall_id, type, section, name, description, photo_path, addon_skus, sort_order, price, stock, track_stock, cms_synced_at, erp_synced_at) VALUES
('LS-TAPSILOG',   'lolas-silog',  'menu',  'Silog',   'Tapsilog',               'Marinated beef tapa, garlic fried rice and a sunny-side-up egg. Served with atchara on the side.', 'food/tapsilog.jpg',  'LS-ADD-RICE,LS-ADD-EGG', 1, 85, 24, 1, NOW(), NOW()),
('LS-LONGSILOG',  'lolas-silog',  'menu',  'Silog',   'Longsilog',              'Sweet pork longganisa, garlic fried rice and egg.',                                                 'food/longsilog.jpg', 'LS-ADD-RICE,LS-ADD-EGG', 2, 75, 11, 1, NOW(), NOW()),
('LS-BANGSILOG',  'lolas-silog',  'menu',  'Silog',   'Bangsilog',              'Crispy daing na bangus (milkfish), garlic fried rice and egg.',                                     'food/bangsilog.jpg', 'LS-ADD-RICE,LS-ADD-EGG', 3, 90,  0, 1, NOW(), NOW()),
('LS-TOCILOG',    'lolas-silog',  'menu',  'Silog',   'Tocilog',                'Sweet cured pork tocino, garlic fried rice and egg.',                                               NULL,                 'LS-ADD-RICE,LS-ADD-EGG', 4, 80, 18, 1, NOW(), NOW()),
('LS-ADOBO',      'lolas-silog',  'menu',  'Ulam',    'Chicken adobo with rice', 'Chicken braised in soy, vinegar and garlic, with a cup of plain rice.',                            NULL,                 'LS-ADD-RICE,LS-ADD-EGG', 5, 70, 20, 1, NOW(), NOW()),
('LS-SINIGANG',   'lolas-silog',  'menu',  'Ulam',    'Pork sinigang with rice', 'Sour tamarind soup with pork and vegetables, with a cup of plain rice.',                           NULL,                 'LS-ADD-RICE',            6, 85, 12, 1, NOW(), NOW()),
('LS-ADD-RICE',   'lolas-silog',  'addon', 'Add-ons', 'Extra rice',              'One more cup of garlic rice.',                                                                     NULL,                 NULL,                     7, 15,  0, 0, NOW(), NOW()),
('LS-ADD-EGG',    'lolas-silog',  'addon', 'Add-ons', 'Fried egg',               'Sunny side up or scrambled.',                                                                      NULL,                 NULL,                     8, 12,  0, 0, NOW(), NOW()),
('KK-ICED-COFFEE','kape-kanto',   'menu',  'Coffee',  'Iced coffee',             'House blend over ice with milk and a little brown sugar.',                                         NULL,                 'KK-ADD-SHOT,KK-ADD-OAT', 1, 60, 40, 1, NOW(), NOW()),
('KK-HOT-BREWED', 'kape-kanto',   'menu',  'Coffee',  'Hot brewed coffee',       'Freshly brewed Benguet arabica.',                                                                  NULL,                 'KK-ADD-SHOT',            2, 45, 40, 1, NOW(), NOW()),
('KK-SPANISH',    'kape-kanto',   'menu',  'Coffee',  'Spanish latte',           'Espresso, milk and condensed milk, iced or hot.',                                                  NULL,                 'KK-ADD-SHOT,KK-ADD-OAT', 3, 85, 30, 1, NOW(), NOW()),
('KK-ENSAYMADA',  'kape-kanto',   'menu',  'Merienda','Ensaymada',               'Soft brioche with butter, sugar and grated cheese.',                                               NULL,                 NULL,                     4, 35, 15, 1, NOW(), NOW()),
('KK-BANANA',     'kape-kanto',   'menu',  'Merienda','Banana bread',            'Moist banana loaf, baked every morning.',                                                          NULL,                 NULL,                     5, 40,  4, 1, NOW(), NOW()),
('KK-ADD-SHOT',   'kape-kanto',   'addon', 'Add-ons', 'Extra espresso shot',     'Make it stronger.',                                                                                NULL,                 NULL,                     6, 20,  0, 0, NOW(), NOW()),
('KK-ADD-OAT',    'kape-kanto',   'addon', 'Add-ons', 'Oat milk',                'Swap regular milk for oat milk.',                                                                  NULL,                 NULL,                     7, 25,  0, 0, NOW(), NOW()),
('NB-CANTON',     'noodle-bar',   'menu',  'Noodles', 'Pancit canton',           'Stir-fried egg noodles with pork, shrimp and vegetables.',                                         NULL,                 'NB-ADD-EGG,NB-ADD-SIOMAI', 1, 55, 20, 1, NOW(), NOW()),
('NB-MAMI',       'noodle-bar',   'menu',  'Noodles', 'Beef mami',               'Egg noodles in rich beef broth with tender beef slices.',                                          NULL,                 'NB-ADD-EGG,NB-ADD-SIOMAI', 2, 75, 18, 1, NOW(), NOW()),
('NB-LOMI',       'noodle-bar',   'menu',  'Noodles', 'Lomi',                    'Thick noodles in a hearty egg-drop soup, Batangas style.',                                         NULL,                 'NB-ADD-EGG,NB-ADD-SIOMAI', 3, 70, 15, 1, NOW(), NOW()),
('NB-ADD-EGG',    'noodle-bar',   'addon', 'Add-ons', 'Boiled egg',              'Half a boiled egg on top.',                                                                        NULL,                 NULL,                     4, 12,  0, 0, NOW(), NOW()),
('NB-ADD-SIOMAI', 'noodle-bar',   'addon', 'Add-ons', 'Siomai (3 pcs)',          'Steamed pork siomai with chili-garlic.',                                                           NULL,                 NULL,                     5, 30, 30, 1, NOW(), NOW()),
('GS-PORK-BBQ',   'grill-station','menu',  'Skewers', 'Pork barbecue',           'Sweet-glazed pork barbecue stick.',                                                                NULL,                 NULL,                     1, 25, 60, 1, NOW(), NOW()),
('GS-ISAW',       'grill-station','menu',  'Skewers', 'Chicken isaw',            'Grilled chicken intestines with spiced vinegar.',                                                  NULL,                 NULL,                     2, 15, 50, 1, NOW(), NOW()),
('GS-LIEMPO',     'grill-station','menu',  'Meals',   'Liempo with rice',        'Grilled pork belly with rice and atchara.',                                                        NULL,                 'GS-ADD-RICE',            3, 95, 14, 1, NOW(), NOW()),
('GS-INASAL',     'grill-station','menu',  'Meals',   'Chicken inasal with rice','Bacolod-style grilled chicken leg quarter with rice.',                                             NULL,                 'GS-ADD-RICE',            4, 99, 12, 1, NOW(), NOW()),
('GS-ADD-RICE',   'grill-station','addon', 'Add-ons', 'Extra rice',              'One more cup of rice.',                                                                            NULL,                 NULL,                     5, 15,  0, 0, NOW(), NOW());

INSERT INTO announcements (id, title, body, image_path, link_url, starts_at, ends_at, sort_order, synced_at) VALUES
(1, 'Skip the lunch rush',           'Pre-order before 11 AM and pick a pickup slot — your tray will be waiting at the counter.', NULL, 'home.html',    NULL, NULL, 1, NOW()),
(2, 'Reserve a table for your group','Tables are held for 10 minutes past your start time, then released for others.',          NULL, 'reserve.html', NULL, NULL, 2, NOW());

-- 40 tables in the Lawrence Bunzel Canteen, 4 columns x 10 rows
INSERT INTO dining_tables (code, seats, row_no, col_no) VALUES
('T-01',2,1,1),('T-02',2,1,2),('T-03',2,1,3),('T-04',4,1,4),
('T-05',2,2,1),('T-06',2,2,2),('T-07',6,2,3),('T-08',4,2,4),
('T-09',2,3,1),('T-10',4,3,2),('T-11',2,3,3),('T-12',2,3,4),
('T-13',2,4,1),('T-14',6,4,2),('T-15',4,4,3),('T-16',4,4,4),
('T-17',2,5,1),('T-18',6,5,2),('T-19',2,5,3),('T-20',4,5,4),
('T-21',6,6,1),('T-22',2,6,2),('T-23',2,6,3),('T-24',4,6,4),
('T-25',2,7,1),('T-26',4,7,2),('T-27',2,7,3),('T-28',6,7,4),
('T-29',2,8,1),('T-30',4,8,2),('T-31',2,8,3),('T-32',4,8,4),
('T-33',2,9,1),('T-34',2,9,2),('T-35',6,9,3),('T-36',4,9,4),
('T-37',2,10,1),('T-38',2,10,2),('T-39',2,10,3),('T-40',4,10,4);

-- Sample reservations (dates are relative to the day you import)
INSERT INTO reservations (id, ref, user_id, table_code, res_date, start_time, end_time, party_size, status, checked_in_at, released_at, created_at) VALUES
(1001, 'RSV-1001', 1, 'T-07', DATE_SUB(CURDATE(), INTERVAL 8 DAY),  '12:00:00', '12:45:00', 4, 'completed', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 8 DAY), '12:03:00'), NULL, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(1002, 'RSV-1002', 1, 'T-03', DATE_SUB(CURDATE(), INTERVAL 15 DAY), '11:00:00', '11:45:00', 2, 'released',  NULL, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 15 DAY), '11:10:00'), DATE_SUB(NOW(), INTERVAL 16 DAY)),
(1003, 'RSV-1003', 2, 'T-18', CURDATE(),                            '11:30:00', '12:15:00', 5, 'booked',    NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1004, 'RSV-1004', 3, 'T-37', CURDATE(),                            '12:00:00', '12:45:00', 2, 'booked',    NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1005, 'RSV-1005', 1, 'T-04', DATE_ADD(CURDATE(), INTERVAL 1 DAY),  '11:30:00', '12:15:00', 4, 'booked',    NULL, NULL, NOW());

INSERT INTO table_blocks (table_code, block_date, slot_time, reason, created_by) VALUES
('T-09', CURDATE(), '11:00:00', 'Cleaning', 4),
('T-09', CURDATE(), '11:30:00', 'Cleaning', 4),
('T-30', CURDATE(), '13:00:00', 'Org event', 4),
('T-30', CURDATE(), '13:30:00', 'Org event', 4);

-- Ana's order history (these two also exist in the ERP sales ledger)
INSERT INTO checkouts (id, ref, user_id, payment_method, payment_status, subtotal, service_fee, total, pickup_date, pickup_time, created_at, paid_at) VALUES
(10001, 'CHK-10001', 1, 'gcash', 'paid',  60.00, 5.00,  65.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:30:00', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:20:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:20:30')),
(10002, 'CHK-10002', 1, 'gcash', 'paid', 150.00, 5.00, 155.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:30:00', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:10:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:10:30'));

INSERT INTO orders (id, ref, checkout_id, user_id, stall_id, status, subtotal, pickup_date, pickup_time, erp_status, erp_so_no, placed_at, paid_at, preparing_at, ready_at, completed_at, status_changed_at) VALUES
(90155, 'KTN-90155', 10001, 1, 'kape-kanto',  'completed',  60.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:30:00', 'synced', 'SO-002666',
  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:20:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:20:30'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:22:00'),
  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:27:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:31:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:31:00')),
(90180, 'KTN-90180', 10002, 1, 'lolas-silog', 'completed', 150.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:30:00', 'synced', 'SO-002667',
  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:10:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:10:30'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:15:00'),
  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:26:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:32:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:32:00'));

ALTER TABLE orders AUTO_INCREMENT = 90201;
ALTER TABLE reservations AUTO_INCREMENT = 1006;

INSERT INTO order_items (order_id, sku, name, qty, unit_price, addons, addons_total, note, line_total) VALUES
(90155, 'KK-ICED-COFFEE', 'Iced coffee', 1, 60.00, '[]', 0, NULL,  60.00),
(90180, 'LS-LONGSILOG',   'Longsilog',   2, 75.00, '[]', 0, NULL, 150.00);
