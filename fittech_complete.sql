-- ================================================================
--  FITTECH — COMPLETE DATABASE SETUP
--  Paste this ENTIRE file into:
--  phpMyAdmin > SQL tab > click Go
--
--  Creates the full database from scratch. Run once on a fresh
--  install. Do NOT re-run on an existing database — it will wipe
--  all data. See the migration patch at the bottom for upgrades.
-- ================================================================

CREATE DATABASE IF NOT EXISTS fittech
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE fittech;

SET FOREIGN_KEY_CHECKS = 0;

-- ── DROP ALL TABLES (children first, then parents) ───────────
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS workouts;
DROP TABLE IF EXISTS renewal_requests;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS equipment;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS trainers;
DROP TABLE IF EXISTS membership_pricing;
DROP TABLE IF EXISTS members;

-- ================================================================
--  TABLE DEFINITIONS
-- ================================================================

-- ── MEMBERS ───────────────────────────────────────────────────
-- is_admin = 1 → admin account (excluded from all analytics).
-- Walk-in guests are NOT stored here — they live in attendance.
-- age is computed server-side from birthdate; CHECK constraint guards range.
CREATE TABLE members (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    username        VARCHAR(50)   NOT NULL UNIQUE,
    email           VARCHAR(100)  NOT NULL UNIQUE,
    password        VARCHAR(255)  NOT NULL,
    full_name       VARCHAR(100)  NOT NULL,
    phone           VARCHAR(20)   UNIQUE,
    birthdate       DATE,
    age             INT,
    gender          ENUM('Male','Female','Other'),
    membership_type ENUM('Basic','Premium','VIP') DEFAULT 'Basic',
    join_date       DATE          DEFAULT (CURDATE()),
    expiry_date     DATE,
    status          ENUM('Active','Inactive','Expired') DEFAULT 'Active',
    is_admin        TINYINT(1)    DEFAULT 0,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_age CHECK (age IS NULL OR (age >= 15 AND age <= 80))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MEMBERSHIP PRICING ────────────────────────────────────────
-- Authoritative price table — PHP always reads price from here.
-- Walk-in is included so admin dashboard can reference it;
-- Walk-in guests are NOT stored in members.
CREATE TABLE membership_pricing (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    membership_type VARCHAR(50)   NOT NULL UNIQUE,
    price           DECIMAL(10,2) NOT NULL,
    duration_months INT           NOT NULL,
    description     VARCHAR(255),
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TRAINERS ──────────────────────────────────────────────────
-- is_active = 0 after soft-delete (booking history stays intact).
CREATE TABLE trainers (
    id          INT           PRIMARY KEY AUTO_INCREMENT,
    name        VARCHAR(100)  NOT NULL,
    specialty   VARCHAR(100),
    tags        VARCHAR(255),
    rate        VARCHAR(50),
    emoji       VARCHAR(20),
    color       VARCHAR(100)  DEFAULT 'linear-gradient(135deg,#00e5ff,#0080ff)',
    photo       VARCHAR(255)  DEFAULT NULL,
    is_active   TINYINT(1)    DEFAULT 1,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── BOOKINGS ──────────────────────────────────────────────────
-- amount_due = trainer rate × duration_hours (calculated server-side).
-- session_end_time pre-computed for overlap detection in schedule.php.
CREATE TABLE bookings (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    member_id        INT,
    trainer_id       INT,
    session_type     VARCHAR(100),
    session_date     DATE          NOT NULL,
    session_time     TIME          NOT NULL,
    session_end_time TIME          NULL,
    duration_hours   DECIMAL(3,1)  DEFAULT 1.0,
    amount_due       DECIMAL(10,2) DEFAULT 0.00,
    notes            TEXT,
    status           ENUM('confirmed','cancelled','completed') DEFAULT 'confirmed',
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id)  REFERENCES members(id),
    FOREIGN KEY (trainer_id) REFERENCES trainers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PRODUCTS ──────────────────────────────────────────────────
-- is_active:  1 = visible,  0 = hidden,  -1 = soft-deleted
-- Soft delete (is_active = -1) used when a product has order
-- history and a hard DELETE would violate the FK constraint.
CREATE TABLE products (
    id          INT           PRIMARY KEY AUTO_INCREMENT,
    name        VARCHAR(150)  NOT NULL,
    category    VARCHAR(50),
    price       DECIMAL(10,2) NOT NULL,
    emoji       VARCHAR(20),
    badge       VARCHAR(20)   DEFAULT '',
    description TEXT,
    stock       INT           DEFAULT 100,
    image_url   VARCHAR(255)  DEFAULT NULL,
    is_active   TINYINT(1)    DEFAULT 1,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ORDERS ────────────────────────────────────────────────────
-- Status flow: pending → confirmed (admin-approved) or cancelled.
-- Stock is only deducted when admin confirms.
-- Analytics count only 'confirmed' orders.
-- DEFAULT 'pending' — shop.php always inserts as pending first.
CREATE TABLE orders (
    id           INT           PRIMARY KEY AUTO_INCREMENT,
    member_id    INT           NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status       ENUM('pending','confirmed','completed','cancelled') DEFAULT 'pending',
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ORDER ITEMS ───────────────────────────────────────────────
-- price captured at purchase time so history survives price changes.
CREATE TABLE order_items (
    id         INT           PRIMARY KEY AUTO_INCREMENT,
    order_id   INT           NOT NULL,
    product_id INT           NOT NULL,
    quantity   INT           NOT NULL,
    price      DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WORKOUTS ──────────────────────────────────────────────────
-- workout_type validated server-side against a whitelist in workouts.php.
CREATE TABLE workouts (
    id              INT  PRIMARY KEY AUTO_INCREMENT,
    member_id       INT,
    workout_type    VARCHAR(50),
    duration        INT  COMMENT 'minutes',
    calories_burned INT,
    date            DATE,
    notes           TEXT,
    FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ATTENDANCE ────────────────────────────────────────────────
-- member visits: member_id = <id>,  type = 'member',   name = NULL
-- walk-ins:      member_id = NULL,  type = 'walk_in',  name = guest name
-- recorded_by = admin_id who logged the walk-in.
CREATE TABLE attendance (
    id          INT           PRIMARY KEY AUTO_INCREMENT,
    member_id   INT           NULL,
    type        ENUM('member','walk_in') DEFAULT 'member',
    name        VARCHAR(100)  NULL       COMMENT 'walk-in guest name',
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    recorded_by INT           NULL       COMMENT 'admin who logged the walk-in',
    check_in    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    check_out   DATETIME      NULL,
    FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── EQUIPMENT ─────────────────────────────────────────────────
-- added_by = member id of the admin who created the record.
CREATE TABLE equipment (
    id               INT          PRIMARY KEY AUTO_INCREMENT,
    name             VARCHAR(150) NOT NULL,
    category         VARCHAR(80),
    location         VARCHAR(100),
    condition_status ENUM('Excellent','Good','Fair','Needs Repair','Out of Service') DEFAULT 'Good',
    last_maintained  DATE         NULL,
    notes            TEXT,
    added_by         INT          NULL,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (added_by) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── RENEWAL REQUESTS ──────────────────────────────────────────
-- Lifecycle: pending → approved | rejected | cancelled (by member)
--            approved → refunded (admin, with full chain-unwinding)
--
-- Snapshot columns (previous_*) record the member's EXACT state
-- just BEFORE approval — enables perfect refund chain reversal.
--
-- Analytics: SUM(amount) WHERE status = 'approved'
-- Refunded rows are auto-excluded — no extra work needed.
CREATE TABLE renewal_requests (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    member_id        INT           NOT NULL,
    requested_type   ENUM('Basic','Premium','VIP') NOT NULL,
    current_type     VARCHAR(50),
    current_expiry   DATE,
    status           ENUM('pending','approved','rejected','cancelled','refunded') DEFAULT 'pending',
    reviewed_by      INT           NULL,
    reviewed_at      TIMESTAMP     NULL,
    note             TEXT,
    refund_note      TEXT          NULL,
    refunded_by      INT           NULL,
    refunded_at      TIMESTAMP     NULL,
    amount           DECIMAL(10,2) DEFAULT 0.00,
    previous_type    VARCHAR(50)   NULL  COMMENT 'membership_type before this approval',
    previous_expiry  DATE          NULL  COMMENT 'expiry_date before this approval',
    previous_status  VARCHAR(20)   NULL  COMMENT 'status before this approval',
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id)   REFERENCES members(id),
    FOREIGN KEY (reviewed_by) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PASSWORD RESET TOKENS ─────────────────────────────────────
-- Admin-mediated OTP reset flow (no email needed).
--
-- Lifecycle:
--   1. Member submits forgot_password → status='pending', token=NULL
--   2. Admin approves → 6-digit OTP generated, status='approved', expires in 30 min
--   3. Member enters OTP → status='used', password updated
--   4. Admin can deny pending request → status='denied'
--
-- token is NULL until admin approves (NOT NULL would break step 1).
-- attempts is incremented on EVERY reset_password call and locked at 5.
CREATE TABLE password_reset_tokens (
    id         INT          PRIMARY KEY AUTO_INCREMENT,
    member_id  INT          NOT NULL,
    token      VARCHAR(64)  NULL       COMMENT 'NULL until admin approves and generates OTP',
    status     ENUM('pending','approved','used','denied') DEFAULT 'pending',
    expires_at DATETIME     NULL,
    used       TINYINT(1)   DEFAULT 0,
    attempts   INT          DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
--  SEED DATA
-- ================================================================

-- ── MEMBERSHIP PRICING ────────────────────────────────────────
INSERT INTO membership_pricing (membership_type, price, duration_months, description) VALUES
('Walk-in',   300.00,   0, 'Single day gym access — no registration needed'),
('Basic',    2500.00,   1, 'Full gym access for 1 month'),
('Premium', 12000.00,   6, 'Full gym access for 6 months'),
('VIP',     20000.00,  12, 'Full gym access for 1 year — all amenities');

-- ── TRAINERS ──────────────────────────────────────────────────
INSERT INTO trainers (name, specialty, tags, rate, emoji, color) VALUES
('Coach Marco Reyes',     'Strength & Powerlifting', 'Powerlifting,Bulking,Form Correction', '₱500/hr', '🏋️', 'linear-gradient(135deg,#00e5ff,#0080ff)'),
('Coach Ana Santos',      'Weight Loss & Cardio',    'HIIT,Fat Burn,Cardio',                 '₱450/hr', '🏃', 'linear-gradient(135deg,#ff3d6b,#ff8c00)'),
('Coach Jake Villanueva', 'CrossFit & Endurance',    'CrossFit,Endurance,Agility',           '₱480/hr', '⚡', 'linear-gradient(135deg,#7cff6b,#00b300)'),
('Coach Mia Cruz',        'Yoga & Flexibility',      'Yoga,Stretching,Mindfulness',          '₱400/hr', '🧘', 'linear-gradient(135deg,#b57bff,#6c00ff)'),
('Coach Luis Garcia',     'Boxing & Combat Fitness', 'Boxing,Footwork,Conditioning',         '₱520/hr', '🥊', 'linear-gradient(135deg,#ffd700,#ff8c00)'),
('Coach Sofia Tan',       'Nutrition & Body Comp.',  'Nutrition,Diet Plan,Wellness',         '₱550/hr', '🥗', 'linear-gradient(135deg,#00e5ff,#7cff6b)');

-- ── PRODUCTS ──────────────────────────────────────────────────
INSERT INTO products (name, category, price, badge, description, stock) VALUES
('ON Gold Standard Whey',       'Protein',     1899.00, 'hot',  '24g protein per serving. Available in Chocolate, Vanilla & Strawberry. 5 lbs.',  100),
('Dymatize ISO100',             'Protein',     2299.00, '',     'Hydrolyzed whey isolate. 25g protein. Ultra-fast post-workout absorption.',        100),
('Creatine Monohydrate 500g',   'Creatine',     899.00, 'sale', 'Pure micronized creatine monohydrate. Unflavored. Mix with any drink.',            100),
('Creatine HCL 90 caps',        'Creatine',    1199.00, '',     'No loading phase. Superior absorption vs monohydrate. 90 capsules.',               100),
('Monster Energy Original',     'Energy',        89.00, '',     '160mg caffeine + B-vitamins. The iconic green can. 500ml.',                        100),
('Monster Ultra White',         'Energy',        89.00, 'new',  'Zero sugar. Crisp citrus taste. 150mg caffeine. 500ml.',                           100),
('Monster Mango Loco',          'Energy',        89.00, '',     'Tropical mango juice blend. 160mg caffeine. 500ml.',                               100),
('Monster Pipeline Punch',      'Energy',        89.00, 'new',  'Passion fruit + guava + orange. 160mg caffeine. 500ml.',                           100),
('C4 Pre-Workout Original',     'Pre-Workout', 1599.00, 'hot',  '150mg caffeine, beta-alanine, arginine AKG. 30 servings. Explosive energy.',       100),
('BCAA 2:1:1 Powder',           'Amino Acids',  799.00, '',     'Essential amino acids: Leucine, Isoleucine, Valine. 30 servings.',                 100),
('FitTech Shaker 700ml',        'Gear',         299.00, 'new',  'Leak-proof BPA-free shaker bottle with stainless mixing ball included.',            100),
('Resistance Bands Set (5pcs)', 'Gear',         649.00, 'sale', '5 resistance levels. Durable fabric bands. Great for home & gym workouts.',         100);

-- ── EQUIPMENT ─────────────────────────────────────────────────
INSERT INTO equipment (name, category, location, condition_status, last_maintained, notes) VALUES
('Treadmill #1',          'Cardio',      'Cardio Zone',     'Good',           '2026-02-01', 'Belt replaced last month'),
('Treadmill #2',          'Cardio',      'Cardio Zone',     'Excellent',      '2026-02-15', 'Fully serviced'),
('Treadmill #3',          'Cardio',      'Cardio Zone',     'Needs Repair',   '2025-12-10', 'Screen flickering, report submitted'),
('Stationary Bike #1',    'Cardio',      'Cardio Zone',     'Good',           '2026-01-20', NULL),
('Stationary Bike #2',    'Cardio',      'Cardio Zone',     'Fair',           '2025-11-05', 'Resistance knob loose'),
('Rowing Machine',        'Cardio',      'Cardio Zone',     'Excellent',      '2026-02-10', NULL),
('Elliptical #1',         'Cardio',      'Cardio Zone',     'Good',           '2026-01-15', NULL),
('Barbell Rack #1',       'Strength',    'Free Weights',    'Excellent',      '2026-02-20', NULL),
('Barbell Rack #2',       'Strength',    'Free Weights',    'Good',           '2026-01-10', NULL),
('Dumbbell Set (5-50kg)', 'Strength',    'Free Weights',    'Good',           '2026-02-01', '40kg pair missing, replacement ordered'),
('Smith Machine',         'Strength',    'Weight Room',     'Excellent',      '2026-02-18', NULL),
('Cable Crossover',       'Strength',    'Weight Room',     'Fair',           '2025-12-20', 'Left pulley cable fraying, needs inspection'),
('Leg Press Machine',     'Strength',    'Weight Room',     'Good',           '2026-01-25', NULL),
('Lat Pulldown Machine',  'Strength',    'Weight Room',     'Good',           '2026-02-05', NULL),
('Chest Press Machine',   'Strength',    'Weight Room',     'Out of Service', '2025-10-01', 'Motor failure, waiting for parts'),
('Incline Bench #1',      'Strength',    'Free Weights',    'Excellent',      '2026-02-12', NULL),
('Flat Bench #1',         'Strength',    'Free Weights',    'Good',           '2026-01-30', NULL),
('Flat Bench #2',         'Strength',    'Free Weights',    'Fair',           '2025-12-15', 'Upholstery torn, scheduled for reupholstery'),
('Pull-up Station',       'Functional',  'Functional Zone', 'Excellent',      '2026-02-08', NULL),
('Battle Ropes',          'Functional',  'Functional Zone', 'Good',           '2026-01-20', NULL),
('Yoga Mat Area',         'Flexibility', 'Studio',          'Good',           '2026-02-01', '2 mats need replacing'),
('Boxing Bag #1',         'Combat',      'Boxing Area',     'Good',           '2026-01-15', NULL),
('Boxing Bag #2',         'Combat',      'Boxing Area',     'Needs Repair',   '2025-11-20', 'Chain mount cracked'),
('Speed Bag',             'Combat',      'Boxing Area',     'Excellent',      '2026-02-10', NULL);

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--  AFTER RUNNING THIS FILE:
--
--  1. Register your admin account normally through the FitTech app
--  2. Run this in phpMyAdmin to grant admin rights
--     (replace 'your_username' with your actual username):
--
--       UPDATE members SET is_admin = 1 WHERE username = 'your_username';
--
--  3. Log out and log back in — admin dashboard activates
-- ================================================================

-- ================================================================
--  MIGRATION PATCH — EXISTING INSTALLS ONLY
--  ─────────────────────────────────────────
--  Already have a running database? Do NOT re-run the full file
--  above (it wipes everything). Run only the statements below
--  that apply to your install, then refresh the page.
--
--  Check what you're missing:
--    DESCRIBE members;
--    DESCRIBE password_reset_tokens;
--    DESCRIBE orders;
--    DESCRIBE equipment;
--    SHOW TABLES;
-- ================================================================

/*

-- ── 1. New members columns (phone, birthdate, gender, age) ──
ALTER TABLE members ADD COLUMN IF NOT EXISTS phone     VARCHAR(20)  UNIQUE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS gender    ENUM('Male','Female','Other');
ALTER TABLE members ADD COLUMN IF NOT EXISTS birthdate DATE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS age       INT;

-- ── 2. orders default status fix (was 'confirmed', should be 'pending') ──
ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','confirmed','completed','cancelled') DEFAULT 'pending';

-- ── 3. equipment added_by column ──
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS added_by INT NULL;
ALTER TABLE equipment ADD FOREIGN KEY IF NOT EXISTS (added_by) REFERENCES members(id);

-- ── 4. attendance walk-in columns ──
ALTER TABLE attendance MODIFY COLUMN member_id INT NULL;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS type        ENUM('member','walk_in') DEFAULT 'member';
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS name        VARCHAR(100) NULL;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS recorded_by INT NULL;

-- ── 5. renewal_requests refund chain columns ──
ALTER TABLE renewal_requests
    MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled','refunded') DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS refund_note     TEXT          NULL,
    ADD COLUMN IF NOT EXISTS refunded_by     INT           NULL,
    ADD COLUMN IF NOT EXISTS refunded_at     TIMESTAMP     NULL,
    ADD COLUMN IF NOT EXISTS previous_type   VARCHAR(50)   NULL,
    ADD COLUMN IF NOT EXISTS previous_expiry DATE          NULL,
    ADD COLUMN IF NOT EXISTS previous_status VARCHAR(20)   NULL;

-- ── 6. password_reset_tokens full fix ──
--    token must be NULLable (pending requests have no token yet)
--    status and attempts columns must exist for the OTP flow
ALTER TABLE password_reset_tokens
    MODIFY COLUMN token    VARCHAR(64) NULL,
    MODIFY COLUMN expires_at DATETIME  NULL,
    ADD COLUMN IF NOT EXISTS status   ENUM('pending','approved','used','denied') DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS attempts INT DEFAULT 0;

-- ── 7. Seed pricing if table is empty ──
INSERT IGNORE INTO membership_pricing (membership_type, price, duration_months, description) VALUES
('Walk-in',   300.00,  0, 'Single day gym access — no registration needed'),
('Basic',    2500.00,  1, 'Full gym access for 1 month'),
('Premium', 12000.00,  6, 'Full gym access for 6 months'),
('VIP',     20000.00, 12, 'Full gym access for 1 year — all amenities');

*/
