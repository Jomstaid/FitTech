<?php
require 'config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'get';

$userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);

// ── Basic guard: user_id must be a positive integer ──
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user.']);
    exit;
}

if ($action === 'get') {
    $stmt = $pdo->prepare("SELECT * FROM workouts WHERE member_id = ? ORDER BY date DESC, id DESC LIMIT 10");
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetchAll());
} elseif ($action === 'create') {
    $duration        = (int)($input['duration']        ?? 0);
    $workout_type    = trim($input['workout_type']     ?? '');
    $mode            = trim($input['mode']             ?? 'manual'); // 'manual' | 'estimated'
    $weight_kg       = (float)($input['weight_kg']     ?? 0);

    // ── Verify the member actually exists ──
    $mem_check = $pdo->prepare("SELECT id FROM members WHERE id = ?");
    $mem_check->execute([$userId]);
    if (!$mem_check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Member not found.']);
        exit;
    }

    // ── Realistic duration: 1–300 minutes (5 hours max) ──
    if ($duration <= 0 || $duration > 300) {
        http_response_code(400);
        echo json_encode(['error' => 'Duration must be between 1 and 300 minutes.']);
        exit;
    }

    // ── Workout type whitelist ──
    $valid_types = ['Strength','Cardio','HIIT','Yoga','Boxing','CrossFit','Cycling','Swimming','Stretching','Other'];
    if (empty($workout_type) || !in_array($workout_type, $valid_types)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid workout type. Allowed: ' . implode(', ', $valid_types)]);
        exit;
    }

    // ── Calorie calculation — scoped to THIS session only ──
    if ($mode === 'estimated') {
        // MET values per workout type
        $met_map = [
            'Cardio'     => 5.0,
            'HIIT'       => 8.0,
            'Strength'   => 6.0,
            'Yoga'       => 3.0,
            'Boxing'     => 8.0,
            'CrossFit'   => 8.0,
            'Cycling'    => 5.0,
            'Swimming'   => 5.0,
            'Stretching' => 3.0,
            'Other'      => 4.0,
        ];
        $met = $met_map[$workout_type] ?? 4.0;
        // Require weight for estimation
        if ($weight_kg <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Weight (kg) is required for calorie estimation.']);
            exit;
        }
        $calories_burned = (int)round($met * $weight_kg * ($duration / 60));
    } else {
        // Manual mode — trust the value the user entered
        $calories_burned = (int)($input['calories_burned'] ?? 0);
    }

    // ── Realistic calories guard: 0–3000 kcal per session ──
    if ($calories_burned < 0 || $calories_burned > 3000) {
        http_response_code(400);
        echo json_encode(['error' => 'Calories burned must be between 0 and 3000 per session.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO workouts (member_id, workout_type, duration, calories_burned, date, notes) VALUES (?, ?, ?, ?, CURDATE(), ?)");
    $success = $stmt->execute([$userId, $workout_type, $duration, $calories_burned, $input['notes'] ?? '']);
    echo json_encode([
        'success'        => $success,
        'calories_burned'=> $calories_burned,
        'mode'           => $mode,
    ]);
} elseif ($action === 'stats') {
    // ── LATEST SESSION only (dashboard cards) ──
    // Returns the single most recent workout row — never accumulates past sessions.
    $stmt = $pdo->prepare("
        SELECT calories_burned, duration, workout_type, date
        FROM workouts
        WHERE member_id = ?
        ORDER BY date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $latest = $stmt->fetch();

    // Total session count (safe to aggregate — it is just counting rows, not summing values)
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM workouts WHERE member_id = ?");
    $countStmt->execute([$userId]);
    $total = (int)$countStmt->fetch()['total'];

    echo json_encode([
        'total_calories' => $latest ? (int)($latest['calories_burned'] ?? 0) : 0,
        'total_duration' => $latest ? (int)($latest['duration']        ?? 0) : 0,
        'total_workouts' => $total,
        'latest_type'    => $latest ? $latest['workout_type'] : null,
        'latest_date'    => $latest ? $latest['date']         : null,
        'scope'          => 'latest_session',
    ]);

} elseif ($action === 'stats_all') {
    // ── ALL-TIME totals (history/analytics view — explicit opt-in) ──
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(calories_burned),0) as total_calories,
               COALESCE(SUM(duration),0)        as total_duration,
               COUNT(*)                         as total_workouts
        FROM workouts WHERE member_id = ?
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    echo json_encode(array_merge($result, ['scope' => 'all_time']));

} elseif ($action === 'stats_week') {
    // ── THIS WEEK's totals (Mon 00:00 → Sun 23:59 of the current calendar week) ──
    // DAYOFWEEK: 1=Sun,2=Mon...7=Sat — subtract to get Monday as week start
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(calories_burned), 0) AS week_calories,
            COALESCE(SUM(duration), 0)        AS week_duration,
            COUNT(*)                          AS week_workouts,
            DATE_SUB(CURDATE(), INTERVAL (DAYOFWEEK(CURDATE()) - 2 + 7) % 7 DAY) AS week_start,
            DATE_ADD(
                DATE_SUB(CURDATE(), INTERVAL (DAYOFWEEK(CURDATE()) - 2 + 7) % 7 DAY),
                INTERVAL 6 DAY
            )                                 AS week_end
        FROM workouts
        WHERE member_id = ?
          AND date >= DATE_SUB(CURDATE(), INTERVAL (DAYOFWEEK(CURDATE()) - 2 + 7) % 7 DAY)
          AND date <= DATE_ADD(
                DATE_SUB(CURDATE(), INTERVAL (DAYOFWEEK(CURDATE()) - 2 + 7) % 7 DAY),
                INTERVAL 6 DAY)
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();

    // Days remaining until Sunday midnight (reset point)
    $today       = new DateTime('today');
    $sunday      = new DateTime('this sunday');
    // If today IS Sunday, next reset is in 0 days (resets tonight)
    $days_left   = (int)$today->diff($sunday)->days;
    if($today->format('N') == 7) $days_left = 0; // Sunday = day 7 in ISO

    echo json_encode([
        'week_calories' => (int)$result['week_calories'],
        'week_duration' => (int)$result['week_duration'],
        'week_workouts' => (int)$result['week_workouts'],
        'week_start'    => $result['week_start'],
        'week_end'      => $result['week_end'],
        'days_until_reset' => $days_left,
        'scope'         => 'current_week',
    ]);
}
?>