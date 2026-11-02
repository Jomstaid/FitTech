<?php
require 'config.php';

$action   = $_GET['action'] ?? 'overview';
$admin_id = $_GET['admin_id'] ?? 0;

// ── ALL admin.php actions require admin ──
if (!$admin_id) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$chk = $pdo->prepare("SELECT is_admin FROM members WHERE id = ? AND is_admin = 1");
$chk->execute([$admin_id]);
if (!$chk->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized — admin only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ── OVERVIEW STATS ────────────────────────────────
if ($action === 'overview') {
    $data = [];

    // Membership breakdown — exclude admin accounts
    $stmt = $pdo->query("SELECT membership_type, COUNT(*) as count FROM members WHERE is_admin = 0 GROUP BY membership_type");
    $data['membership_breakdown'] = $stmt->fetchAll();

    // Member status counts — exclude admin accounts
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM members WHERE is_admin = 0 GROUP BY status");
    $data['member_status'] = $stmt->fetchAll();

    // Shop revenue — only 'confirmed' (admin-approved) orders count
    $stmt = $pdo->query("SELECT COALESCE(SUM(o.total_amount),0) as total FROM orders o JOIN members m ON o.member_id = m.id WHERE o.status = 'confirmed' AND m.is_admin = 0");
    $data['shop_revenue'] = $stmt->fetch()['total'];

    // Pending shop orders count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'");
    $data['pending_orders'] = $stmt->fetch()['count'];

    // Trainer session revenue — sum actual amount_due from bookings by non-admin members
    $stmt = $pdo->query("SELECT COALESCE(SUM(b.amount_due),0) as total FROM bookings b JOIN members m ON b.member_id = m.id WHERE b.status='confirmed' AND m.is_admin = 0");
    $data['trainer_revenue'] = $stmt->fetch()['total'];
    // Also keep confirmed session count for display
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM bookings b JOIN members m ON b.member_id = m.id WHERE b.status='confirmed' AND m.is_admin = 0");
    $data['confirmed_sessions'] = $stmt->fetch()['total'];

    // Walk-in revenue
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) as total FROM attendance WHERE type = 'walk_in'");
    $data['walkin_revenue'] = $stmt->fetch()['total'];

    // Active membership value — only Active non-admin members, excludes Walk-in.
    // NOTE: this represents the value of currently-active memberships, NOT total
    // revenue collected. Revenue collected through renewals is tracked separately
    // in renewal_revenue. Limiting to Active prevents double-counting expired members
    // whose renewal was already captured in renewal_revenue.
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(mp.price), 0) as total
        FROM members m
        JOIN membership_pricing mp ON m.membership_type = mp.membership_type
        WHERE m.is_admin = 0
          AND m.status   = 'Active'
          AND m.membership_type != 'Walk-in'
    ");
    $data['membership_revenue'] = $stmt->fetch()['total'];

    // Renewal revenue — only 'approved' rows count.
    // 'refunded' rows are automatically excluded, so analytics
    // net out correctly the moment a refund is processed.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_requests (
            id               INT PRIMARY KEY AUTO_INCREMENT,
            member_id        INT NOT NULL,
            requested_type   ENUM('Basic','Premium','VIP') NOT NULL,
            current_type     VARCHAR(50),
            current_expiry   DATE,
            status           ENUM('pending','approved','rejected','cancelled','refunded') DEFAULT 'pending',
            reviewed_by      INT NULL,
            reviewed_at      TIMESTAMP NULL,
            note             TEXT,
            refund_note      TEXT NULL,
            refunded_by      INT NULL,
            refunded_at      TIMESTAMP NULL,
            amount           DECIMAL(10,2) DEFAULT 0.00,
            previous_type    VARCHAR(50) NULL,
            previous_expiry  DATE NULL,
            previous_status  VARCHAR(20) NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id)
        )");
    } catch(PDOException $e){}
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) as total FROM renewal_requests WHERE status = 'approved'");
    $data['renewal_revenue'] = $stmt->fetch()['total'];

    // Pending renewal requests count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM renewal_requests WHERE status='pending'");
    $data['pending_renewals'] = $stmt->fetch()['count'];

    // Total revenue
    $data['total_revenue'] = $data['shop_revenue'] + $data['trainer_revenue']
                           + $data['walkin_revenue'] + $data['membership_revenue']
                           + $data['renewal_revenue'];

    // Today's check-ins — walk-ins always count; member rows count only for non-admin members.
    // Using COALESCE on type avoids NULL-comparison issues on older rows without the type column.
    // The explicit m.is_admin = 0 check (with IS NOT NULL guard) prevents admin check-ins
    // from being included even if they somehow appear in the attendance table.
    $stmt = $pdo->query("
        SELECT COUNT(*) as today
        FROM attendance a
        LEFT JOIN members m ON a.member_id = m.id
        WHERE DATE(a.check_in) = CURDATE()
          AND (
              a.type = 'walk_in'
              OR (a.member_id IS NOT NULL AND m.is_admin = 0)
          )
    ");
    $data['today_checkins'] = $stmt->fetch()['today'];

    // Today walk-ins specifically
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM attendance WHERE DATE(check_in) = CURDATE() AND type = 'walk_in'");
    $data['today_walkins'] = $stmt->fetch()['total'];

    // This week check-ins — exclude admin, same COALESCE + IS NOT NULL pattern as today_checkins
    $stmt = $pdo->query("
        SELECT COUNT(*) as week
        FROM attendance a
        LEFT JOIN members m ON a.member_id = m.id
        WHERE a.check_in >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND (
              a.type = 'walk_in'
              OR (a.member_id IS NOT NULL AND m.is_admin = 0)
          )
    ");
    $data['week_checkins'] = $stmt->fetch()['week'];

    // Total members — real paying members only, no admin
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM members WHERE is_admin = 0");
    $data['total_members'] = $stmt->fetch()['total'];

    // New members this month — no admin
    $stmt = $pdo->query("SELECT COUNT(*) as new_this_month FROM members WHERE is_admin = 0 AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $data['new_this_month'] = $stmt->fetch()['new_this_month'];

    // Top workout types
    $stmt = $pdo->query("SELECT workout_type, COUNT(*) as count FROM workouts GROUP BY workout_type ORDER BY count DESC LIMIT 5");
    $data['top_workouts'] = $stmt->fetchAll();

    // Recent orders
    $stmt = $pdo->query("SELECT o.id, o.total_amount, o.created_at, m.full_name FROM orders o JOIN members m ON o.member_id=m.id ORDER BY o.created_at DESC LIMIT 5");
    $data['recent_orders'] = $stmt->fetchAll();

    // Membership pricing
    $stmt = $pdo->query("SELECT * FROM membership_pricing ORDER BY price ASC");
    $data['pricing'] = $stmt->fetchAll();

    echo json_encode($data);

// ── WALK-IN LOG IN ────────────────────────────────
} elseif ($action === 'walkin') {
    $name   = mb_substr(strip_tags(trim($input['name'] ?? 'Walk-in Guest')), 0, 100);
    $amount = (float)($input['amount'] ?? 300);

    // ── Name fallback if empty after sanitization ──
    if ($name === '') $name = 'Walk-in Guest';

    // ── Amount must be within a realistic range (₱50 – ₱10,000) ──
    if ($amount < 50 || $amount > 10000) {
        http_response_code(400);
        echo json_encode(['error' => 'Walk-in fee must be between ₱50 and ₱10,000.']);
        exit;
    }

    // Auto-migrate attendance table if walkin.sql hasn't been run yet
    try { $pdo->exec("ALTER TABLE attendance MODIFY COLUMN member_id INT NULL"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS type ENUM('member','walk_in') DEFAULT 'member'"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS name VARCHAR(100) NULL"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(10,2) DEFAULT 0.00"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS recorded_by INT NULL"); } catch(PDOException $e){}

    // Auto-create pricing table if missing
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS membership_pricing (
            id INT PRIMARY KEY AUTO_INCREMENT,
            membership_type VARCHAR(50) NOT NULL UNIQUE,
            price DECIMAL(10,2) NOT NULL,
            duration_months INT NOT NULL,
            description VARCHAR(255),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $pdo->exec("INSERT IGNORE INTO membership_pricing (membership_type,price,duration_months,description) VALUES
            ('Walk-in',300.00,0,'Single day gym access'),
            ('Basic',2500.00,1,'Full gym access for 1 month'),
            ('Premium',12000.00,6,'Full gym access for 6 months'),
            ('VIP',20000.00,12,'Full gym access for 1 year')");
    } catch(PDOException $e){}

    try {
        $stmt = $pdo->prepare("INSERT INTO attendance (member_id, check_in, type, name, amount_paid, recorded_by) VALUES (NULL, NOW(), 'walk_in', ?, ?, ?)");
        $stmt->execute([$name, $amount, $admin_id]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── MEMBER CHECK-IN ───────────────────────────────
} elseif ($action === 'checkin') {
    $member_id = (int)($input['member_id'] ?? 0);
    if ($member_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid member ID.']);
        exit;
    }
    // Verify member exists and is not expired before logging
    $chkMem = $pdo->prepare("SELECT id, full_name, status FROM members WHERE id = ? AND is_admin = 0");
    $chkMem->execute([$member_id]);
    $member = $chkMem->fetch();
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Member not found.']);
        exit;
    }
    if ($member['status'] === 'Expired') {
        http_response_code(400);
        echo json_encode(['error' => 'Membership is expired. Please renew before checking in.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO attendance (member_id, check_in, type, amount_paid) VALUES (?, NOW(), 'member', 0)");
        $stmt->execute([$member_id]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'member_name' => $member['full_name']]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── DAILY ATTENDANCE LOG ──────────────────────────
} elseif ($action === 'attendance') {
    $days = $_GET['days'] ?? 7;
    try {
        $stmt = $pdo->prepare("
            SELECT DATE(check_in) as date,
                   COUNT(*) as total_checkins,
                   COUNT(DISTINCT CASE WHEN type='member' THEN member_id END) as member_checkins,
                   COUNT(CASE WHEN type='walk_in' THEN 1 END) as walkin_checkins,
                   COALESCE(SUM(CASE WHEN type='walk_in' THEN amount_paid ELSE 0 END),0) as daily_walkin_revenue
            FROM attendance
            WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(check_in)
            ORDER BY date ASC
        ");
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("
            SELECT DATE(check_in) as date, COUNT(*) as total_checkins,
                   COUNT(DISTINCT member_id) as member_checkins, 0 as walkin_checkins, 0 as daily_walkin_revenue
            FROM attendance
            WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(check_in) ORDER BY date ASC
        ");
    }
    $stmt->execute([$days]);
    echo json_encode($stmt->fetchAll());

// ── TODAY'S VISITORS ──────────────────────────────
} elseif ($action === 'today') {
    // Check if new columns exist, fall back to basic query if not
    try {
        $stmt = $pdo->query("
            SELECT a.id, a.check_in,
                   COALESCE(a.type,'member') as type,
                   a.name as walkin_name,
                   COALESCE(a.amount_paid,0) as amount_paid,
                   m.full_name, m.membership_type, m.username
            FROM attendance a
            LEFT JOIN members m ON a.member_id = m.id
            WHERE DATE(a.check_in) = CURDATE()
            ORDER BY a.check_in DESC
        ");
    } catch (PDOException $e) {
        // Fallback if new columns don't exist yet
        $stmt = $pdo->query("
            SELECT a.id, a.check_in, 'member' as type, NULL as walkin_name, 0 as amount_paid,
                   m.full_name, m.membership_type, m.username
            FROM attendance a
            LEFT JOIN members m ON a.member_id = m.id
            WHERE DATE(a.check_in) = CURDATE()
            ORDER BY a.check_in DESC
        ");
    }
    echo json_encode($stmt->fetchAll());

// ── REVENUE BREAKDOWN ─────────────────────────────
} elseif ($action === 'revenue') {
    $data = [];

    // Shop revenue by month — confirmed orders only, matching the overview card.
    // Previously used status != 'cancelled' which included pending (not-yet-approved)
    // orders and caused the chart to show higher numbers than the overview total.
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%Y-%m') as month,
               DATE_FORMAT(created_at,'%b %Y') as label,
               SUM(total_amount) as revenue, COUNT(*) as orders
        FROM orders WHERE status = 'confirmed'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at,'%Y-%m')
        ORDER BY month ASC
    ");
    $data['monthly_shop'] = $stmt->fetchAll();

    // Walk-in revenue by month — with fallback for older schemas without the type column.
    try {
        $stmt = $pdo->query("
            SELECT DATE_FORMAT(check_in,'%Y-%m') as month,
                   DATE_FORMAT(check_in,'%b %Y') as label,
                   SUM(amount_paid) as revenue, COUNT(*) as count
            FROM attendance WHERE type = 'walk_in'
            AND check_in >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(check_in,'%Y-%m')
            ORDER BY month ASC
        ");
        $data['monthly_walkin'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        // type column not yet migrated — return empty so chart doesn't crash
        $data['monthly_walkin'] = [];
    }

    // Top products
    $stmt = $pdo->query("
        SELECT p.name, p.category,
               SUM(oi.quantity) as total_sold,
               SUM(oi.quantity * oi.price) as revenue
        FROM order_items oi JOIN products p ON oi.product_id=p.id
        GROUP BY p.id ORDER BY total_sold DESC LIMIT 8
    ");
    $data['top_products'] = $stmt->fetchAll();

    echo json_encode($data);

// ── GET / UPDATE PRICING ──────────────────────────
} elseif ($action === 'get_pricing') {
    $stmt = $pdo->query("SELECT * FROM membership_pricing ORDER BY price ASC");
    echo json_encode($stmt->fetchAll());

} elseif ($action === 'update_pricing') {
    $items = $input['items'] ?? [];
    try {
        $stmt = $pdo->prepare("UPDATE membership_pricing SET price=?, description=? WHERE membership_type=?");
        foreach ($items as $item) {
            $stmt->execute([$item['price'], $item['description'], $item['membership_type']]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── ANALYTICS OVERVIEW (Java desktop app) ────────
// Same data as 'overview' but shaped for the Analytics tab labels:
//   total_members, active_members, expired_members, expiring_soon,
//   today_checkins, total_revenue
} elseif ($action === 'analytics_overview') {
    $data = [];

    $stmt = $pdo->query("SELECT COUNT(*) as c FROM members WHERE is_admin = 0");
    $data['total_members'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->query("SELECT COUNT(*) as c FROM members WHERE is_admin = 0 AND status = 'Active'");
    $data['active_members'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->query("SELECT COUNT(*) as c FROM members WHERE is_admin = 0 AND status = 'Expired'");
    $data['expired_members'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->query("
        SELECT COUNT(*) as c FROM members
        WHERE is_admin = 0 AND status = 'Active'
          AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    $data['expiring_soon'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->query("
        SELECT COUNT(*) as c
        FROM attendance a
        LEFT JOIN members m ON a.member_id = m.id
        WHERE DATE(a.check_in) = CURDATE()
          AND (a.type = 'walk_in' OR (a.member_id IS NOT NULL AND m.is_admin = 0))
    ");
    $data['today_checkins'] = (int)$stmt->fetch()['c'];

    // Total revenue (same formula as overview)
    $shop     = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) as t FROM orders WHERE status = 'confirmed'")->fetch()['t'];
    $trainer  = (float)$pdo->query("SELECT COALESCE(SUM(b.amount_due),0) as t FROM bookings b JOIN members m ON b.member_id=m.id WHERE b.status='confirmed' AND m.is_admin=0")->fetch()['t'];
    $walkin   = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) as t FROM attendance WHERE type='walk_in'")->fetch()['t'];
    $memrev   = (float)$pdo->query("SELECT COALESCE(SUM(mp.price),0) as t FROM members m JOIN membership_pricing mp ON m.membership_type=mp.membership_type WHERE m.is_admin=0 AND m.status='Active' AND m.membership_type!='Walk-in'")->fetch()['t'];
    try {
        $renewal = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) as t FROM renewal_requests WHERE status='approved'")->fetch()['t'];
    } catch (PDOException $e) { $renewal = 0; }

    $data['total_revenue'] = $shop + $trainer + $walkin + $memrev + $renewal;

    echo json_encode($data);

// ── MEMBERSHIP BREAKDOWN (Java desktop app) ───────
// Returns one row per membership type:
//   membership_type, total, active, expired, revenue
} elseif ($action === 'membership_breakdown') {
    $stmt = $pdo->query("
        SELECT
            m.membership_type,
            COUNT(*)                                                        AS total,
            SUM(CASE WHEN m.status = 'Active'  THEN 1 ELSE 0 END)         AS active,
            SUM(CASE WHEN m.status = 'Expired' THEN 1 ELSE 0 END)         AS expired,
            COALESCE(SUM(mp.price), 0)                                     AS revenue
        FROM members m
        LEFT JOIN membership_pricing mp ON m.membership_type = mp.membership_type
        WHERE m.is_admin = 0
        GROUP BY m.membership_type
        ORDER BY mp.price ASC
    ");
    echo json_encode($stmt->fetchAll());

// ── RECENT CHECK-INS (Java desktop app) ──────────
// Returns the last 20 check-ins with member name, membership, type, and time
} elseif ($action === 'recent_checkins') {
    try {
        $stmt = $pdo->query("
            SELECT
                COALESCE(m.full_name, a.name, 'Walk-in Guest') AS full_name,
                COALESCE(m.membership_type, 'Walk-in')         AS membership_type,
                COALESCE(a.type, 'member')                     AS checkin_type,
                a.check_in                                      AS checked_in_at
            FROM attendance a
            LEFT JOIN members m ON a.member_id = m.id
            ORDER BY a.check_in DESC
            LIMIT 20
        ");
    } catch (PDOException $e) {
        // Fallback for older schema without type / name columns
        $stmt = $pdo->query("
            SELECT
                COALESCE(m.full_name, 'Walk-in Guest') AS full_name,
                COALESCE(m.membership_type, 'Walk-in') AS membership_type,
                'member'                               AS checkin_type,
                a.check_in                             AS checked_in_at
            FROM attendance a
            LEFT JOIN members m ON a.member_id = m.id
            ORDER BY a.check_in DESC
            LIMIT 20
        ");
    }
    echo json_encode($stmt->fetchAll());
}
?>