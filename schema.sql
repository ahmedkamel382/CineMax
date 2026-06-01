-- ============================================================
-- CINEMAX EGYPT - COMPLETE DATABASE SETUP
-- ============================================================
-- This is the ONLY SQL file needed for the final project.
--
-- WARNING:
-- This file rebuilds the `cinema` database from zero.
-- Export/backup your current database before importing it.
--
-- Import in phpMyAdmin, then run tmdb_sync.php once to generate
-- fresh movie showtimes from TMDB.
-- ============================================================

DROP DATABASE IF EXISTS cinema;
CREATE DATABASE cinema
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE cinema;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- CORE LOCATION AND ACCOUNT TABLES
-- ============================================================

CREATE TABLE governorates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name_en VARCHAR(80) NOT NULL,
  name_ar VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  role ENUM('user','admin','staff','regional_admin') NOT NULL DEFAULT 'user',
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  governorate_id INT NULL,
  INDEX idx_users_governorate (governorate_id),
  CONSTRAINT fk_users_governorate
    FOREIGN KEY (governorate_id) REFERENCES governorates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cinemas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  governorate_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  location VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  UNIQUE KEY unique_cinema_region_name (governorate_id, name),
  INDEX idx_cinemas_region (governorate_id),
  CONSTRAINT fk_cinemas_governorate
    FOREIGN KEY (governorate_id) REFERENCES governorates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE halls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cinema_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  total_rows INT NOT NULL DEFAULT 6,
  seats_per_row INT NOT NULL DEFAULT 12,
  type ENUM('standard','vip','imax','4dx') NOT NULL DEFAULT 'standard',
  UNIQUE KEY unique_hall_cinema_name (cinema_id, name),
  INDEX idx_halls_cinema (cinema_id),
  CONSTRAINT fk_halls_cinema
    FOREIGN KEY (cinema_id) REFERENCES cinemas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Many-to-many: a regional admin (or staff) can be responsible for
-- MORE THAN ONE region. users.governorate_id stays as the "primary"
-- region for backward compatibility; this table holds the full set.
CREATE TABLE admin_governorates (
  user_id INT NOT NULL,
  governorate_id INT NOT NULL,
  PRIMARY KEY (user_id, governorate_id),
  INDEX idx_admin_gov_user (user_id),
  INDEX idx_admin_gov_region (governorate_id),
  CONSTRAINT fk_admin_gov_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_gov_region
    FOREIGN KEY (governorate_id) REFERENCES governorates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SHOWTIMES, BOOKINGS, PAYMENTS, TICKETS
-- ============================================================

CREATE TABLE showtimes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tmdb_movie_id INT NOT NULL,
  movie_title VARCHAR(255) NOT NULL,
  movie_poster VARCHAR(500) DEFAULT NULL,
  hall_id INT NOT NULL,
  show_datetime DATETIME NOT NULL,
  price DECIMAL(8,2) NOT NULL DEFAULT 150.00,
  UNIQUE KEY unique_showtime_slot (tmdb_movie_id, hall_id, show_datetime),
  INDEX idx_showtimes_tmdb (tmdb_movie_id),
  INDEX idx_showtimes_time (show_datetime),
  INDEX idx_showtimes_hall (hall_id),
  CONSTRAINT fk_showtimes_hall
    FOREIGN KEY (hall_id) REFERENCES halls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  showtime_id INT NOT NULL,
  movie_title VARCHAR(255) NOT NULL,
  movie_poster VARCHAR(500) DEFAULT NULL,
  total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  payment_method VARCHAR(50) NULL,
  payment_reference VARCHAR(100) NULL,
  paid_at DATETIME NULL,
  ticket_code VARCHAR(100) NULL UNIQUE,
  ticket_status ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
  status ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bookings_user (user_id),
  INDEX idx_bookings_showtime (showtime_id),
  INDEX idx_bookings_payment (payment_status),
  CONSTRAINT fk_bookings_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bookings_showtime
    FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE booking_seats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  showtime_id INT NOT NULL,
  seat_row INT NOT NULL,
  seat_number INT NOT NULL,
  seat_ticket_code VARCHAR(140) NULL UNIQUE,
  seat_ticket_status ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
  INDEX idx_booking_seats_booking (booking_id),
  INDEX idx_booking_seats_showtime (showtime_id),
  UNIQUE KEY unique_showtime_seat (showtime_id, seat_row, seat_number),
  UNIQUE KEY unique_booking_seat (booking_id, seat_row, seat_number),
  CONSTRAINT fk_booking_seats_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_seats_showtime
    FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cancelled_booking_seats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  showtime_id INT NULL,
  cinema_id INT NULL,
  governorate_id INT NULL,
  seat_row INT NOT NULL,
  seat_number INT NOT NULL,
  seat_ticket_code VARCHAR(140) NULL,
  seat_ticket_status ENUM('valid','used','cancelled') NOT NULL DEFAULT 'cancelled',
  cancelled_by_user_id INT NULL,
  refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  reason VARCHAR(60) NOT NULL DEFAULT 'user_partial_cancel',
  cancelled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cancelled_booking (booking_id),
  INDEX idx_cancelled_showtime (showtime_id, seat_row, seat_number),
  INDEX idx_cancelled_user (cancelled_by_user_id),
  UNIQUE KEY unique_cancelled_booking_seat_reason (booking_id, seat_row, seat_number, reason),
  CONSTRAINT fk_cancelled_seats_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_cancelled_seats_showtime
    FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE SET NULL,
  CONSTRAINT fk_cancelled_seats_user
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE seat_locks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  showtime_id INT NOT NULL,
  seat_row INT NOT NULL,
  seat_number INT NOT NULL,
  user_id INT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_seat_lock (showtime_id, seat_row, seat_number),
  INDEX idx_locks_expiry (expires_at),
  INDEX idx_locks_user (user_id),
  CONSTRAINT fk_seat_locks_showtime
    FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE,
  CONSTRAINT fk_seat_locks_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE refund_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  user_id INT NOT NULL,
  showtime_id INT NOT NULL,
  governorate_id INT NOT NULL,
  seats_json TEXT NOT NULL,
  refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_by INT NULL,
  processed_at DATETIME NULL,
  admin_note TEXT NULL,
  INDEX idx_refund_requests_booking (booking_id),
  INDEX idx_refund_requests_region_status (governorate_id, status),
  INDEX idx_refund_requests_user (user_id),
  CONSTRAINT fk_refund_requests_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_requests_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_requests_showtime
    FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_requests_governorate
    FOREIGN KEY (governorate_id) REFERENCES governorates(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_requests_processed_by
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- REVIEWS, SUPPORT, NOTIFICATIONS, SYNC LOGS
-- ============================================================

CREATE TABLE comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tmdb_movie_id INT NOT NULL,
  cinema_id INT NULL,
  governorate_id INT NULL,
  body TEXT NOT NULL,
  rating TINYINT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comments_movie (tmdb_movie_id),
  INDEX idx_comments_user (user_id),
  INDEX idx_comments_cinema (cinema_id),
  INDEX idx_comments_governorate (governorate_id),
  UNIQUE KEY unique_user_movie_rating (user_id, tmdb_movie_id),
  CONSTRAINT fk_comments_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_cinema
    FOREIGN KEY (cinema_id) REFERENCES cinemas(id) ON DELETE SET NULL,
  CONSTRAINT fk_comments_governorate
    FOREIGN KEY (governorate_id) REFERENCES governorates(id) ON DELETE SET NULL,
  CONSTRAINT chk_comments_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  governorate_id INT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','pending','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_support_messages_user (user_id),
  INDEX idx_support_messages_governorate (governorate_id),
  INDEX idx_support_messages_status (status),
  CONSTRAINT fk_support_messages_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_support_messages_governorate
    FOREIGN KEY (governorate_id) REFERENCES governorates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  admin_id INT NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_support_replies_ticket (ticket_id),
  CONSTRAINT fk_support_replies_ticket
    FOREIGN KEY (ticket_id) REFERENCES support_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_replies_admin
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE forgot_password_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  email VARCHAR(120) NOT NULL,
  request_type ENUM('remember','change') NOT NULL,
  status ENUM('pending','code_sent','resolved','rejected') NOT NULL DEFAULT 'pending',
  admin_id INT NULL,
  admin_note VARCHAR(500) NULL,
  admin_visible_code VARCHAR(12) NULL,
  preferred_channel ENUM('email','phone') NOT NULL DEFAULT 'email',
  contact_phone VARCHAR(40) NULL,
  code_expires_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_forgot_requests_email (email),
  INDEX idx_forgot_requests_status (status),
  CONSTRAINT fk_forgot_requests_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_forgot_requests_admin
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_reset_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  email VARCHAR(120) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(64) NULL,
  INDEX idx_reset_codes_user (user_id, used_at, expires_at),
  INDEX idx_reset_codes_email (email),
  CONSTRAINT fk_reset_codes_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_outbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient_email VARCHAR(120) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('sent','failed','local_only') NOT NULL DEFAULT 'local_only',
  error_message VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_outbox_recipient (recipient_email),
  INDEX idx_email_outbox_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user (user_id, is_read),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  added INT DEFAULT 0,
  updated INT DEFAULT 0,
  errors INT DEFAULT 0,
  note VARCHAR(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- LOCATION TRIGGER
-- Only cancelled seat history stores location directly because old showtimes
-- may be deleted later. Other tables derive region/cinema through joins/views.
-- ============================================================
DELIMITER $$

CREATE TRIGGER trg_cancelled_seats_location_bi
BEFORE INSERT ON cancelled_booking_seats FOR EACH ROW
BEGIN
  DECLARE v_cinema INT DEFAULT NULL;
  DECLARE v_gov INT DEFAULT NULL;
  IF NEW.showtime_id IS NOT NULL THEN
    SELECT c.id, c.governorate_id INTO v_cinema, v_gov
    FROM showtimes s JOIN halls h ON s.hall_id = h.id JOIN cinemas c ON h.cinema_id = c.id
    WHERE s.id = NEW.showtime_id LIMIT 1;
    SET NEW.cinema_id = v_cinema;
    SET NEW.governorate_id = v_gov;
  END IF;
END $$

DELIMITER ;

-- ============================================================
-- SEED REGIONS, CINEMAS, HALLS
-- ============================================================

INSERT INTO governorates (id, name_en, name_ar, slug) VALUES
  (1, 'Cairo', 'Cairo', 'cairo'),
  (2, 'Giza', 'Giza', 'giza'),
  (3, 'Alexandria', 'Alexandria', 'alexandria'),
  (4, 'Qaliubiya', 'Qaliubiya', 'qaliubiya'),
  (5, 'Dakahlia', 'Dakahlia', 'dakahlia'),
  (6, 'Gharbia', 'Gharbia', 'gharbia'),
  (7, 'Sharkia', 'Sharkia', 'sharkia'),
  (8, 'Beheira', 'Beheira', 'beheira'),
  (9, 'Monufia', 'Monufia', 'monufia'),
  (10, 'Kafr El Sheikh', 'Kafr El Sheikh', 'kafr-el-sheikh'),
  (11, 'Damietta', 'Damietta', 'damietta'),
  (12, 'Port Said', 'Port Said', 'port-said'),
  (13, 'Ismailia', 'Ismailia', 'ismailia'),
  (14, 'Suez', 'Suez', 'suez'),
  (15, 'North Sinai', 'North Sinai', 'north-sinai'),
  (16, 'South Sinai', 'South Sinai', 'south-sinai'),
  (17, 'Fayoum', 'Fayoum', 'fayoum'),
  (18, 'Beni Suef', 'Beni Suef', 'beni-suef'),
  (19, 'Minya', 'Minya', 'minya'),
  (20, 'Asyut', 'Asyut', 'asyut'),
  (21, 'Sohag', 'Sohag', 'sohag'),
  (22, 'Qena', 'Qena', 'qena'),
  (23, 'Luxor', 'Luxor', 'luxor'),
  (24, 'Aswan', 'Aswan', 'aswan'),
  (25, 'Red Sea', 'Red Sea', 'red-sea'),
  (26, 'New Valley', 'New Valley', 'new-valley'),
  (27, 'Matruh', 'Matruh', 'matruh');

INSERT INTO cinemas (id, governorate_id, name, location, phone) VALUES
  (1, 1, 'Cairo Festival City Cinemas', 'Cairo Festival City Mall, New Cairo', NULL),
  (2, 1, 'City Stars Cinema', 'City Stars Mall, Nasr City', NULL),
  (3, 1, 'Mall of Arabia Cinemas', 'Mall of Arabia, 6th of October', NULL),
  (4, 1, 'Point 90 Mall Cinema', 'Point 90 Mall, New Cairo', NULL),
  (5, 1, 'Watania Cinema Heliopolis', 'Heliopolis, Cairo', NULL),
  (6, 2, 'Mall of Egypt Cinemas', 'Mall of Egypt, Sheikh Zayed', NULL),
  (7, 2, 'Grand Cinemas Dandy Mega Mall', 'Dandy Mega Mall, Sheikh Zayed', NULL),
  (8, 2, 'Americana Plaza Cinema', 'Americana Plaza, Mohandessin', NULL),
  (9, 3, 'San Stefano Grand Plaza Cinemas', 'San Stefano Mall, Alexandria', NULL),
  (10, 3, 'City Centre Alexandria', 'City Centre, Smouha, Alexandria', NULL),
  (11, 4, 'Cinema City Qalyubia', 'Benha, Qaliubiya', NULL),
  (12, 12, 'Mirage Cinema Port Said', 'El Nasr Road, Port Said', NULL),
  (13, 13, 'Ismailia Cinema', 'City Centre Ismailia', NULL),
  (14, 23, 'Luxor Cinema', 'Luxor City Centre', NULL),
  (15, 24, 'Aswan Grand Cinema', 'Aswan Corniche', NULL),
  (16, 25, 'Hurghada Cinema', 'Senzo Mall, Hurghada', NULL),
  (17, 16, 'Sinai Grand Cinema', 'Naama Bay, Sharm El Sheikh', NULL);

INSERT INTO halls (id, cinema_id, name, total_rows, seats_per_row, type) VALUES
  (1, 1, 'Hall 1 - Standard', 7, 12, 'standard'),
  (2, 1, 'Hall 2 - Standard', 7, 12, 'standard'),
  (3, 1, 'Hall 3 - VIP', 5, 8, 'vip'),
  (4, 1, 'Hall 4 - IMAX', 8, 14, 'imax'),
  (5, 2, 'Hall 1 - Standard', 6, 12, 'standard'),
  (6, 2, 'Hall 2 - Standard', 6, 12, 'standard'),
  (7, 2, 'Hall 3 - VIP', 4, 8, 'vip'),
  (8, 3, 'Hall 1 - Standard', 6, 12, 'standard'),
  (9, 3, 'Hall 2 - Standard', 6, 12, 'standard'),
  (10, 3, 'Hall 3 - IMAX', 8, 14, 'imax'),
  (11, 4, 'Hall 1 - Standard', 6, 10, 'standard'),
  (12, 4, 'Hall 2 - VIP', 4, 8, 'vip'),
  (13, 5, 'Hall 1 - Standard', 6, 10, 'standard'),
  (14, 5, 'Hall 2 - Standard', 6, 10, 'standard'),
  (15, 6, 'Hall 1 - Standard', 7, 12, 'standard'),
  (16, 6, 'Hall 2 - Standard', 7, 12, 'standard'),
  (17, 6, 'Hall 3 - VIP', 5, 8, 'vip'),
  (18, 6, 'Hall 4 - 4DX', 4, 8, '4dx'),
  (19, 7, 'Hall 1 - Standard', 6, 12, 'standard'),
  (20, 7, 'Hall 2 - Standard', 6, 12, 'standard'),
  (21, 7, 'Hall 3 - VIP', 4, 8, 'vip'),
  (22, 8, 'Hall 1 - Standard', 6, 10, 'standard'),
  (23, 8, 'Hall 2 - Standard', 6, 10, 'standard'),
  (24, 9, 'Hall 1 - Standard', 7, 12, 'standard'),
  (25, 9, 'Hall 2 - VIP', 5, 8, 'vip'),
  (26, 9, 'Hall 3 - IMAX', 8, 14, 'imax'),
  (27, 10, 'Hall 1 - Standard', 6, 12, 'standard'),
  (28, 10, 'Hall 2 - Standard', 6, 12, 'standard'),
  (29, 10, 'Hall 3 - VIP', 4, 8, 'vip'),
  (30, 11, 'Hall 1 - Standard', 6, 10, 'standard'),
  (31, 11, 'Hall 2 - Standard', 6, 10, 'standard'),
  (32, 12, 'Hall 1 - Standard', 6, 10, 'standard'),
  (33, 12, 'Hall 2 - VIP', 4, 8, 'vip'),
  (34, 13, 'Hall 1 - Standard', 6, 10, 'standard'),
  (35, 14, 'Hall 1 - Standard', 6, 10, 'standard'),
  (36, 14, 'Hall 2 - VIP', 4, 8, 'vip'),
  (37, 15, 'Hall 1 - Standard', 6, 10, 'standard'),
  (38, 16, 'Hall 1 - Standard', 6, 10, 'standard'),
  (39, 16, 'Hall 2 - VIP', 4, 8, 'vip'),
  (40, 17, 'Hall 1 - Standard', 6, 10, 'standard'),
  (41, 17, 'Hall 2 - VIP', 4, 8, 'vip');

-- ============================================================
-- READY TEST ACCOUNTS
-- ============================================================
-- Passwords:
-- manager.cinemax2026@gmail.com / Manager@2026!
-- cairo.admin.cinemax2026@gmail.com / Regional@2026!
-- alexandria.admin.cinemax2026@gmail.com / Regional@2026!
-- user.cinemax2026@gmail.com / User@2026!

INSERT INTO users (username, email, password_hash, role, is_blocked, governorate_id) VALUES
  ('manager_admin', 'manager.cinemax2026@gmail.com', '$2y$10$nqw6GCL5ey0h27/sl9CtzOEtJKnRRnizUOJla.8NDYFEYqtsJBqoS', 'admin', 0, NULL),
  ('cairo_admin', 'cairo.admin.cinemax2026@gmail.com', '$2y$10$KITXVo.25CS5piaX2okY.engdaR0dgFinSWXkw2ykCSGmjSPOWsqG', 'regional_admin', 0, 1),
  ('alexandria_admin', 'alexandria.admin.cinemax2026@gmail.com', '$2y$10$KITXVo.25CS5piaX2okY.engdaR0dgFinSWXkw2ykCSGmjSPOWsqG', 'regional_admin', 0, 3),
  ('demo_user', 'user.cinemax2026@gmail.com', '$2y$10$LsrL/3BZ3HeUrJsdRzxvQOKEwuX79bbn/xNFAH/3pFqq0/hQuVpVW', 'user', 0, NULL);

-- Give each seeded regional admin their primary region in the multi-region
-- table. The manager can add more regions to any of them from the UI.
INSERT INTO admin_governorates (user_id, governorate_id)
SELECT id, governorate_id
FROM users
WHERE role = 'regional_admin' AND governorate_id IS NOT NULL;

-- Keep auto increment above seeded IDs.
ALTER TABLE governorates AUTO_INCREMENT = 28;
ALTER TABLE cinemas AUTO_INCREMENT = 18;
ALTER TABLE halls AUTO_INCREMENT = 42;
ALTER TABLE users AUTO_INCREMENT = 5;

-- ============================================================
-- LOCATION REPORTING VIEWS
-- Read region/cinema NAMES (not just IDs) at a glance.
-- ============================================================
CREATE OR REPLACE VIEW v_bookings_location AS
SELECT b.id AS booking_id, b.user_id, u.username,
       b.movie_title, b.status, b.payment_status, b.payment_method,
       b.total_price, b.created_at, s.show_datetime,
       g.name_en AS region, c.name AS cinema, h.name AS hall
FROM bookings b
JOIN users u      ON b.user_id = u.id
JOIN showtimes s  ON b.showtime_id = s.id
JOIN halls h      ON s.hall_id = h.id
JOIN cinemas c    ON h.cinema_id = c.id
JOIN governorates g ON c.governorate_id = g.id;

CREATE OR REPLACE VIEW v_booking_seats_location AS
SELECT bs.id AS booking_seat_id, bs.booking_id,
       CONCAT(CHAR(64 + bs.seat_row), bs.seat_number) AS seat,
       bs.seat_row, bs.seat_number, bs.seat_ticket_code, bs.seat_ticket_status,
       b.movie_title, b.status, b.payment_status,
       g.name_en AS region, c.name AS cinema, h.name AS hall,
       s.show_datetime
FROM booking_seats bs
JOIN bookings b   ON bs.booking_id = b.id
JOIN showtimes s  ON b.showtime_id = s.id
JOIN halls h      ON s.hall_id = h.id
JOIN cinemas c    ON h.cinema_id = c.id
JOIN governorates g ON c.governorate_id = g.id;

CREATE OR REPLACE VIEW v_cancelled_seats_location AS
SELECT cbs.id AS cancelled_id, cbs.booking_id,
       CONCAT(CHAR(64 + cbs.seat_row), cbs.seat_number) AS seat,
       cbs.seat_ticket_code, cbs.seat_ticket_status,
       cbs.reason, cbs.refund_amount, cbs.cancelled_at,
       g.name_en AS region, c.name AS cinema, h.name AS hall,
       s.show_datetime
FROM cancelled_booking_seats cbs
LEFT JOIN showtimes s  ON cbs.showtime_id = s.id
LEFT JOIN halls h      ON s.hall_id = h.id
LEFT JOIN cinemas c    ON COALESCE(cbs.cinema_id, h.cinema_id) = c.id
LEFT JOIN governorates g ON COALESCE(cbs.governorate_id, c.governorate_id) = g.id;

CREATE OR REPLACE VIEW v_seat_locks_location AS
SELECT sl.id AS lock_id, sl.user_id, u.username,
       CONCAT(CHAR(64 + sl.seat_row), sl.seat_number) AS seat,
       sl.expires_at, (sl.expires_at > NOW()) AS is_active,
       g.name_en AS region, c.name AS cinema, h.name AS hall,
       s.show_datetime
FROM seat_locks sl
JOIN users u      ON sl.user_id = u.id
JOIN showtimes s  ON sl.showtime_id = s.id
JOIN halls h      ON s.hall_id = h.id
JOIN cinemas c    ON h.cinema_id = c.id
JOIN governorates g ON c.governorate_id = g.id;

CREATE OR REPLACE VIEW v_comments_location AS
SELECT cm.id AS comment_id, cm.user_id, u.username,
       cm.tmdb_movie_id, cm.rating, cm.body, cm.created_at,
       g.name_en AS region, c.name AS cinema
FROM comments cm
JOIN users u ON cm.user_id = u.id
LEFT JOIN cinemas c    ON cm.cinema_id = c.id
LEFT JOIN governorates g ON COALESCE(cm.governorate_id, c.governorate_id) = g.id;

-- ============================================================
-- VIRTUAL CINEMA FUTURE EXPANSION
-- Online trailer screening only. It does not use governorates,
-- cinemas, halls, or normal showtimes.
-- ============================================================
CREATE TABLE IF NOT EXISTS virtual_showtimes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tmdb_movie_id INT NOT NULL,
  movie_title VARCHAR(255) NOT NULL,
  movie_poster VARCHAR(500) NULL,
  trailer_url VARCHAR(500) NULL,
  show_datetime DATETIME NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 120.00,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_virtual_show_datetime (show_datetime),
  INDEX idx_virtual_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  virtual_showtime_id INT NOT NULL,
  ticket_code VARCHAR(80) NOT NULL UNIQUE,
  status ENUM('reserved','cancelled') NOT NULL DEFAULT 'reserved',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_one_active_virtual_ticket (user_id, status),
  INDEX idx_virtual_booking_user (user_id),
  INDEX idx_virtual_booking_showtime (virtual_showtime_id),
  CONSTRAINT fk_virtual_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_virtual_bookings_showtime FOREIGN KEY (virtual_showtime_id) REFERENCES virtual_showtimes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_presence (
  id INT AUTO_INCREMENT PRIMARY KEY,
  showtime_id INT NOT NULL,
  user_id INT NOT NULL,
  seat_row INT NOT NULL,
  seat_number INT NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL,
  UNIQUE KEY uniq_presence_seat (showtime_id, seat_row, seat_number),
  UNIQUE KEY uniq_presence_user (showtime_id, user_id),
  INDEX idx_presence_showtime (showtime_id),
  INDEX idx_presence_lastseen (last_seen),
  CONSTRAINT fk_virtual_presence_showtime FOREIGN KEY (showtime_id) REFERENCES virtual_showtimes(id) ON DELETE CASCADE,
  CONSTRAINT fk_virtual_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  showtime_id INT NOT NULL,
  user_id INT NOT NULL,
  seat_row INT NOT NULL,
  seat_number INT NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  body VARCHAR(280) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_msg_showtime_id (showtime_id, id),
  INDEX idx_msg_row (showtime_id, seat_row, id),
  CONSTRAINT fk_seat_messages_showtime FOREIGN KEY (showtime_id) REFERENCES virtual_showtimes(id) ON DELETE CASCADE,
  CONSTRAINT fk_seat_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO virtual_showtimes (tmdb_movie_id, movie_title, movie_poster, trailer_url, show_datetime, price, is_open, is_active)
SELECT 550, 'Virtual Cinema Demo Trailer', NULL, NULL, NOW(), 120.00, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM virtual_showtimes);

INSERT INTO virtual_showtimes (tmdb_movie_id, movie_title, movie_poster, trailer_url, show_datetime, price, is_open, is_active)
SELECT 603, 'Virtual Cinema Evening Trailer', NULL, NULL, NOW(), 120.00, 1, 1
WHERE (SELECT COUNT(*) FROM virtual_showtimes) < 2;

INSERT INTO virtual_showtimes (tmdb_movie_id, movie_title, movie_poster, trailer_url, show_datetime, price, is_open, is_active)
SELECT 11, 'Virtual Cinema Night Trailer', NULL, NULL, NOW(), 120.00, 1, 1
WHERE (SELECT COUNT(*) FROM virtual_showtimes) < 3;

SELECT 'CINEMAX complete database setup imported successfully.' AS status;
