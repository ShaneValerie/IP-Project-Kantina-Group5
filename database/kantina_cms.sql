-- ============================================================
-- Kantina CMS database (content management)
-- Owns: stall profiles, opening hours, photos, menu content
--       (names, descriptions, sections, add-on links), announcements.
-- Tables use the cms_ prefix so this can share one database
-- with the other apps if the host only allows one.
-- Import into the database named in cms/config (default: kantina_cms).
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS cms_webhook_deliveries;
DROP TABLE IF EXISTS cms_announcements;
DROP TABLE IF EXISTS cms_menu_entries;
DROP TABLE IF EXISTS cms_stalls;
DROP TABLE IF EXISTS cms_admins;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE cms_admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(40)  NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cms_stalls (
  id                 VARCHAR(40)  NOT NULL PRIMARY KEY,   -- slug, e.g. lolas-silog
  name               VARCHAR(80)  NOT NULL,
  category           VARCHAR(40)  NOT NULL,
  description        TEXT         NULL,
  photo              VARCHAR(255) NULL,                   -- path inside cms/media/
  counter_label      VARCHAR(40)  NOT NULL DEFAULT 'Counter 1',
  prep_minutes       SMALLINT     NOT NULL DEFAULT 10,
  opens_at           TIME         NOT NULL DEFAULT '07:00:00',
  closes_at          TIME         NOT NULL DEFAULT '18:00:00',
  temporarily_closed TINYINT(1)   NOT NULL DEFAULT 0,
  closed_note        VARCHAR(80)  NULL,
  sort_order         INT          NOT NULL DEFAULT 0,
  is_published       TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cms_menu_entries (
  sku          VARCHAR(40)  NOT NULL PRIMARY KEY,         -- same SKU as the ERP product
  stall_id     VARCHAR(40)  NOT NULL,
  type         ENUM('menu','addon') NOT NULL DEFAULT 'menu',
  section      VARCHAR(60)  NOT NULL DEFAULT 'Menu',
  name         VARCHAR(80)  NOT NULL,
  description  TEXT         NULL,
  photo        VARCHAR(255) NULL,
  addon_skus   VARCHAR(255) NULL,                         -- comma separated SKUs of add-ons offered with this item
  sort_order   INT          NOT NULL DEFAULT 0,
  is_published TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cms_menu_stall FOREIGN KEY (stall_id) REFERENCES cms_stalls(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cms_announcements (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(120) NOT NULL,
  body         TEXT         NULL,
  image        VARCHAR(255) NULL,
  link_url     VARCHAR(255) NULL,
  starts_at    DATETIME     NULL,
  ends_at      DATETIME     NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_published TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outgoing webhooks (content.published) sent to Kantina
CREATE TABLE cms_webhook_deliveries (
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
  KEY idx_cms_wh_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Seed data
-- ------------------------------------------------------------
-- Admin login for the CMS admin pages: admin / kantina123
INSERT INTO cms_admins (username, name, password_hash) VALUES
('admin', 'Canteen Admin', '$2y$10$vYz5nBV1XYAmWeBoQ7MyMe0XVNAtH34hA1NUDYEGRNkbV8amtHVYe');

INSERT INTO cms_stalls (id, name, category, description, photo, counter_label, prep_minutes, opens_at, closes_at, temporarily_closed, closed_note, sort_order) VALUES
('lolas-silog',   'Lola''s Silog', 'Rice meals', 'Classic Filipino silog plates cooked to order: garlic rice, a fried egg and your choice of ulam.', 'stalls/lolas-silog.jpg',   'Counter 2', 10, '07:00:00', '18:00:00', 0, NULL, 1),
('kape-kanto',    'Kape Kanto',    'Drinks',     'Brewed and iced coffee, Spanish lattes and freshly baked merienda.',                                  'stalls/kape-kanto.jpg',    'Counter 1',  5, '07:00:00', '19:00:00', 0, NULL, 2),
('noodle-bar',    'Noodle Bar',    'Noodles',    'Hot bowls of mami and lomi plus pancit for sharing.',                                                 'stalls/noodle-bar.jpg',    'Counter 3', 12, '14:00:00', '19:00:00', 0, NULL, 3),
('grill-station', 'Grill Station', 'Snacks',     'Charcoal-grilled skewers and inasal, best with a cup of rice.',                                       'stalls/grill-station.jpg', 'Counter 4', 15, '13:00:00', '19:00:00', 0, NULL, 4);

INSERT INTO cms_menu_entries (sku, stall_id, type, section, name, description, photo, addon_skus, sort_order) VALUES
-- Lola's Silog
('LS-TAPSILOG',   'lolas-silog', 'menu',  'Silog',   'Tapsilog',               'Marinated beef tapa, garlic fried rice and a sunny-side-up egg. Served with atchara on the side.', 'food/tapsilog.jpg',  'LS-ADD-RICE,LS-ADD-EGG', 1),
('LS-LONGSILOG',  'lolas-silog', 'menu',  'Silog',   'Longsilog',              'Sweet pork longganisa, garlic fried rice and egg.',                                                 'food/longsilog.jpg', 'LS-ADD-RICE,LS-ADD-EGG', 2),
('LS-BANGSILOG',  'lolas-silog', 'menu',  'Silog',   'Bangsilog',              'Crispy daing na bangus (milkfish), garlic fried rice and egg.',                                     'food/bangsilog.jpg', 'LS-ADD-RICE,LS-ADD-EGG', 3),
('LS-TOCILOG',    'lolas-silog', 'menu',  'Silog',   'Tocilog',                'Sweet cured pork tocino, garlic fried rice and egg.',                                               NULL,                 'LS-ADD-RICE,LS-ADD-EGG', 4),
('LS-ADOBO',      'lolas-silog', 'menu',  'Ulam',    'Chicken adobo with rice', 'Chicken braised in soy, vinegar and garlic, with a cup of plain rice.',                            NULL,                 'LS-ADD-RICE,LS-ADD-EGG', 5),
('LS-SINIGANG',   'lolas-silog', 'menu',  'Ulam',    'Pork sinigang with rice', 'Sour tamarind soup with pork and vegetables, with a cup of plain rice.',                           NULL,                 'LS-ADD-RICE',            6),
('LS-ADD-RICE',   'lolas-silog', 'addon', 'Add-ons', 'Extra rice',              'One more cup of garlic rice.',                                                                     NULL,                 NULL,                     7),
('LS-ADD-EGG',    'lolas-silog', 'addon', 'Add-ons', 'Fried egg',               'Sunny side up or scrambled.',                                                                      NULL,                 NULL,                     8),
-- Kape Kanto
('KK-ICED-COFFEE','kape-kanto',  'menu',  'Coffee',  'Iced coffee',             'House blend over ice with milk and a little brown sugar.',                                         NULL,                 'KK-ADD-SHOT,KK-ADD-OAT', 1),
('KK-HOT-BREWED', 'kape-kanto',  'menu',  'Coffee',  'Hot brewed coffee',       'Freshly brewed Benguet arabica.',                                                                  NULL,                 'KK-ADD-SHOT',            2),
('KK-SPANISH',    'kape-kanto',  'menu',  'Coffee',  'Spanish latte',           'Espresso, milk and condensed milk, iced or hot.',                                                  NULL,                 'KK-ADD-SHOT,KK-ADD-OAT', 3),
('KK-ENSAYMADA',  'kape-kanto',  'menu',  'Merienda','Ensaymada',               'Soft brioche with butter, sugar and grated cheese.',                                               NULL,                 NULL,                     4),
('KK-BANANA',     'kape-kanto',  'menu',  'Merienda','Banana bread',            'Moist banana loaf, baked every morning.',                                                          NULL,                 NULL,                     5),
('KK-ADD-SHOT',   'kape-kanto',  'addon', 'Add-ons', 'Extra espresso shot',     'Make it stronger.',                                                                                NULL,                 NULL,                     6),
('KK-ADD-OAT',    'kape-kanto',  'addon', 'Add-ons', 'Oat milk',                'Swap regular milk for oat milk.',                                                                  NULL,                 NULL,                     7),
-- Noodle Bar
('NB-CANTON',     'noodle-bar',  'menu',  'Noodles', 'Pancit canton',           'Stir-fried egg noodles with pork, shrimp and vegetables.',                                         NULL,                 'NB-ADD-EGG,NB-ADD-SIOMAI', 1),
('NB-MAMI',       'noodle-bar',  'menu',  'Noodles', 'Beef mami',               'Egg noodles in rich beef broth with tender beef slices.',                                          NULL,                 'NB-ADD-EGG,NB-ADD-SIOMAI', 2),
('NB-LOMI',       'noodle-bar',  'menu',  'Noodles', 'Lomi',                    'Thick noodles in a hearty egg-drop soup, Batangas style.',                                         NULL,                 'NB-ADD-EGG,NB-ADD-SIOMAI', 3),
('NB-ADD-EGG',    'noodle-bar',  'addon', 'Add-ons', 'Boiled egg',              'Half a boiled egg on top.',                                                                        NULL,                 NULL,                     4),
('NB-ADD-SIOMAI', 'noodle-bar',  'addon', 'Add-ons', 'Siomai (3 pcs)',          'Steamed pork siomai with chili-garlic.',                                                           NULL,                 NULL,                     5),
-- Grill Station
('GS-PORK-BBQ',   'grill-station','menu', 'Skewers', 'Pork barbecue',           'Sweet-glazed pork barbecue stick.',                                                                NULL,                 NULL,                     1),
('GS-ISAW',       'grill-station','menu', 'Skewers', 'Chicken isaw',            'Grilled chicken intestines with spiced vinegar.',                                                  NULL,                 NULL,                     2),
('GS-LIEMPO',     'grill-station','menu', 'Meals',   'Liempo with rice',        'Grilled pork belly with rice and atchara.',                                                        NULL,                 'GS-ADD-RICE',            3),
('GS-INASAL',     'grill-station','menu', 'Meals',   'Chicken inasal with rice','Bacolod-style grilled chicken leg quarter with rice.',                                             NULL,                 'GS-ADD-RICE',            4),
('GS-ADD-RICE',   'grill-station','addon','Add-ons', 'Extra rice',              'One more cup of rice.',                                                                            NULL,                 NULL,                     5);

INSERT INTO cms_announcements (title, body, image, link_url, starts_at, ends_at, sort_order) VALUES
('Skip the lunch rush',          'Pre-order before 11 AM and pick a pickup slot — your tray will be waiting at the counter.', NULL, 'home.html',    NULL, NULL, 1),
('Reserve a table for your group','Tables are held for 10 minutes past your start time, then released for others.',          NULL, 'reserve.html', NULL, NULL, 2);
