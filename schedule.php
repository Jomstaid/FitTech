<?php
require 'config.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'list';

// ── ADMIN ACTIONS ─────────────────────────────────
$admin_actions = ['all_trainers','create','update','delete','all'];
if (in_array($action, $admin_actions)) {
    $admin_id = $input['admin_id'] ?? $_GET['admin_id'] ?? 0;
    if (!$admin_id) { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }
    $chk = $pdo->prepare("SELECT is_admin FROM members WHERE id = ? AND is_admin = 1");
    $chk->execute([$admin_id]);
    if (!$chk->fetch()) { http_response_code(403); echo json_encode(['error'=>'Unauthorized — admin only']); exit; }
}

// ── GET ALL ACTIVE TRAINERS ───────────────────────
if ($action === 'trainers') {
    $stmt = $pdo->query("SELECT * FROM trainers WHERE is_active = 1 ORDER BY id");
    echo json_encode($stmt->fetchAll());

// ── CHECK TRAINER AVAILABILITY ────────────────────
} elseif ($action === 'availability') {
    $trainer_id    = $_GET['trainer_id']    ?? 0;
    $session_date  = $_GET['session_date']  ?? '';
    $session_time  = $_GET['session_time']  ?? '';
    $duration_hours= (float)($_GET['duration_hours'] ?? 1);

    // Calculate end time
    $end_seconds = strtotime($session_time) + ($duration_hours * 3600);
    $end_time    = date('H:i:s', $end_seconds);

    // Check for overlapping bookings
    $stmt = $pdo->prepare("
        SELECT b.id, b.session_time, b.session_end_time, m.full_name
        FROM bookings b
        JOIN members m ON b.member_id = m.id
        WHERE b.trainer_id   = ?
        AND   b.session_date = ?
        AND   b.status       = 'confirmed'
        AND   (
            (b.session_time < ? AND b.session_end_time > ?)
            OR (b.session_time >= ? AND b.session_time < ?)
        )
    ");
    $stmt->execute([$trainer_id, $session_date, $end_time, $session_time, $session_time, $end_time]);
    $conflicts = $stmt->fetchAll();

    // Get ALL bookings for this trainer on this date for the timeline
    $stmt2 = $pdo->prepare("
        SELECT b.session_time, b.session_end_time, b.duration_hours,
               m.full_name, b.status
        FROM bookings b
        JOIN members m ON b.member_id = m.id
        WHERE b.trainer_id = ? AND b.session_date = ? AND b.status = 'confirmed'
        ORDER BY b.session_time ASC
    ");
    $stmt2->execute([$trainer_id, $session_date]);
    $day_bookings = $stmt2->fetchAll();

    echo json_encode([
        'available'    => count($conflicts) === 0,
        'conflicts'    => $conflicts,
        'day_bookings' => $day_bookings,
        'end_time'     => $end_time
    ]);

// ── GET ALL TRAINERS including inactive (admin) ───
} elseif ($action === 'all_trainers') {
    $stmt = $pdo->query("SELECT * FROM trainers ORDER BY id");
    echo json_encode($stmt->fetchAll());

// ── ADD TRAINER (admin) ───────────────────────────
} elseif ($action === 'create') {
    try {
        $stmt = $pdo->prepare("INSERT INTO trainers (name, specialty, tags, rate, color, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$input['name'], $input['specialty']??'', $input['tags']??'', $input['rate']??'', $input['color']??'linear-gradient(135deg,#00e5ff,#0080ff)']);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }

// ── UPDATE TRAINER (admin) ────────────────────────
} elseif ($action === 'update') {
    $id = $input['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("UPDATE trainers SET name=?, specialty=?, tags=?, rate=?, color=?, is_active=? WHERE id=?");
        $stmt->execute([$input['name'], $input['specialty']??'', $input['tags']??'', $input['rate']??'', $input['color']??'linear-gradient(135deg,#00e5ff,#0080ff)', isset($input['is_active'])?(int)$input['is_active']:1, $id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }

// ── DELETE TRAINER ────────────────────────────────
} elseif ($action === 'delete') {
    $id = $input['id'] ?? 0;
    // Check if any bookings (past or future) reference this trainer.
    // If yes, soft-delete to preserve history (booking records still show trainer name).
    // If no bookings at all, hard-delete is safe.
    $bookingCheck = $pdo->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE trainer_id = ?");
    $bookingCheck->execute([$id]);
    $hasBookings = (int)$bookingCheck->fetch()['cnt'] > 0;

    if ($hasBookings) {
        // Soft delete — trainer becomes invisible for new bookings but history is intact
        $pdo->prepare("UPDATE trainers SET is_active = 0 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'method' => 'soft']);
    } else {
        try {
            $pdo->prepare("DELETE FROM trainers WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'method' => 'hard']);
        } catch (PDOException $e) {
            // FK constraint fired despite no bookings found (race condition) — fall back
            $pdo->prepare("UPDATE trainers SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'method' => 'soft']);
        }
    }

// ── GET BOOKINGS FOR A MEMBER ─────────────────────
} elseif ($action === 'list') {
    $member_id = $_GET['member_id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT b.*, t.name AS trainer_name, t.photo AS trainer_photo, t.specialty, t.rate AS trainer_rate
        FROM bookings b JOIN trainers t ON b.trainer_id = t.id
        WHERE b.member_id = ?
        ORDER BY b.session_date DESC, b.session_time DESC
    ");
    $stmt->execute([$member_id]);
    echo json_encode($stmt->fetchAll());

// ── UPCOMING (dashboard) ──────────────────────────
} elseif ($action === 'upcoming') {
    $member_id = $_GET['member_id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT b.*, t.name AS trainer_name, t.photo AS trainer_photo
        FROM bookings b JOIN trainers t ON b.trainer_id = t.id
        WHERE b.member_id = ? AND b.status = 'confirmed' AND b.session_date >= CURDATE()
        ORDER BY b.session_date ASC, b.session_time ASC LIMIT 3
    ");
    $stmt->execute([$member_id]);
    echo json_encode($stmt->fetchAll());

// ── COUNT CONFIRMED SESSIONS ──────────────────────
} elseif ($action === 'count') {
    $member_id = $_GET['member_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE member_id=? AND status='confirmed'");
    $stmt->execute([$member_id]);
    echo json_encode($stmt->fetch());

// ── CREATE BOOKING ────────────────────────────────
} elseif ($action === 'book') {
    $trainer_id     = $input['trainer_id']     ?? 0;
    $session_date   = $input['session_date']   ?? '';
    $session_time   = $input['session_time']   ?? '';
    $duration_hours = (float)($input['duration_hours'] ?? 1);
    $session_type   = $input['session_type']   ?? '';
    $notes          = $input['notes']          ?? '';
    $member_id      = $input['member_id']      ?? 0;

    // ── Cannot book a session in the past ──
    if (empty($session_date) || $session_date < date('Y-m-d')) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot book a session in the past. Please choose today or a future date.']);
        exit;
    }

    // ── If booking is for today, the session time must still be in the future ──
    if ($session_date === date('Y-m-d')) {
        $session_ts = strtotime(date('Y-m-d') . ' ' . $session_time);
        if ($session_ts !== false && $session_ts <= time()) {
            http_response_code(400);
            echo json_encode(['error' => 'Session time has already passed. Please choose a future time slot for today.']);
            exit;
        }
    }

    // ── Duration must be between 0.5 and 8 hours ──
    if ($duration_hours < 0.5 || $duration_hours > 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Session duration must be between 0.5 and 8 hours.']);
        exit;
    }

    // ── Enforce gym operating hours: start must be 05:00–22:59 ──
    $session_hour = (int)date('H', strtotime($session_time));
    if ($session_hour < 5 || $session_hour >= 23) {
        http_response_code(400);
        echo json_encode(['error' => 'Sessions can only be booked between 5:00 AM and 11:00 PM.']);
        exit;
    }

    // ── Session end time must not cross midnight (23:00 hard cap) ──
    // e.g. a session starting at 22:30 for 2 hrs would end at 00:30 — invalid.
    $end_seconds = strtotime($session_time) + ($duration_hours * 3600);
    $end_time    = date('H:i:s', $end_seconds);
    $end_hour_min = (int)date('H', $end_seconds) * 60 + (int)date('i', $end_seconds);
    if ($end_hour_min > 23 * 60) {
        http_response_code(400);
        echo json_encode(['error' => 'Session must end by 11:00 PM. Please reduce the duration or choose an earlier start time.']);
        exit;
    }

    // ── Get trainer rate and guard against zero/unparseable rates ──
    $stmt = $pdo->prepare("SELECT rate FROM trainers WHERE id=?");
    $stmt->execute([$trainer_id]);
    $trainer = $stmt->fetch();
    if (!$trainer) {
        http_response_code(400);
        echo json_encode(['error' => 'Trainer not found.']);
        exit;
    }
    $rate_raw    = $trainer['rate'] ?? '';
    $rate_str    = preg_replace('/[^0-9.]/', '', $rate_raw);
    $rate_per_hr = (float)$rate_str;
    // If the trainer record has a non-empty rate field but it parses to 0,
    // something is wrong with the stored value — reject rather than silently
    // record a ₱0 booking that would corrupt trainer_revenue analytics.
    if ($rate_per_hr <= 0 && trim($rate_raw) !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'Trainer rate is not configured correctly. Please contact admin.']);
        exit;
    }
    $amount_due = $rate_per_hr * $duration_hours;

    // Check for conflicts
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM bookings
        WHERE trainer_id=? AND session_date=? AND status='confirmed'
        AND (
            (session_time < ? AND session_end_time > ?)
            OR (session_time >= ? AND session_time < ?)
        )
    ");
    $stmt->execute([$trainer_id, $session_date, $end_time, $session_time, $session_time, $end_time]);
    if ($stmt->fetch()['cnt'] > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'This trainer is already booked during that time slot. Please choose a different time.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO bookings (member_id, trainer_id, session_type, session_date, session_time, session_end_time, duration_hours, amount_due, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$member_id, $trainer_id, $session_type, $session_date, $session_time, $end_time, $duration_hours, $amount_due, $notes]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'amount_due' => $amount_due, 'end_time' => $end_time]);
    } catch (PDOException $e) {
        http_response_code(400);
        // Give a friendly message for lock timeout instead of raw SQL error
        if (strpos($e->getMessage(), '1205') !== false || strpos($e->getMessage(), 'Lock wait') !== false) {
            echo json_encode(['error' => 'The server is busy. Please wait a moment and try again.']);
        } else {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

// ── CANCEL BOOKING ────────────────────────────────
} elseif ($action === 'cancel') {
    $id        = $input['id']        ?? $_GET['id']        ?? 0;
    $member_id = $input['member_id'] ?? $_GET['member_id'] ?? 0;
    $stmt = $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=? AND member_id=?");
    $stmt->execute([$id, $member_id]);
    echo json_encode(['success' => true]);

// ── ALL BOOKINGS (admin) ──────────────────────────
} elseif ($action === 'all') {
    $stmt = $pdo->query("
        SELECT b.*, t.name AS trainer_name, m.full_name AS member_name, m.username
        FROM bookings b JOIN trainers t ON b.trainer_id=t.id JOIN members m ON b.member_id=m.id
        ORDER BY b.session_date DESC
    ");
    echo json_encode($stmt->fetchAll());
}
?>