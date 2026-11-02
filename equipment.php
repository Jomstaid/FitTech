<?php
require 'config.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'list';

// ── WRITE ACTIONS require admin ───────────────────
$write_actions = ['create', 'update', 'delete'];
if (in_array($action, $write_actions)) {
    $admin_id = $input['admin_id'] ?? $_GET['admin_id'] ?? 0;
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
}

// ── LIST ALL EQUIPMENT ────────────────────────────
if ($action === 'list') {
    $category = $_GET['category'] ?? '';
    if ($category) {
        $stmt = $pdo->prepare("SELECT * FROM equipment WHERE category = ? ORDER BY condition_status, name");
        $stmt->execute([$category]);
    } else {
        $stmt = $pdo->query("SELECT * FROM equipment ORDER BY condition_status DESC, category, name");
    }
    echo json_encode($stmt->fetchAll());

// ── EQUIPMENT SUMMARY (counts by condition) ───────
} elseif ($action === 'summary') {
    $stmt = $pdo->query("
        SELECT
            condition_status,
            COUNT(*) as count
        FROM equipment
        GROUP BY condition_status
        ORDER BY FIELD(condition_status,'Excellent','Good','Fair','Needs Repair','Out of Service')
    ");
    $conditions = $stmt->fetchAll();

    $stmt2 = $pdo->query("SELECT COUNT(*) as total FROM equipment");
    $total = $stmt2->fetch()['total'];

    $stmt3 = $pdo->query("
        SELECT category, COUNT(*) as count
        FROM equipment
        GROUP BY category
        ORDER BY count DESC
    ");
    $by_category = $stmt3->fetchAll();

    echo json_encode([
        'conditions'  => $conditions,
        'total'       => $total,
        'by_category' => $by_category
    ]);

// ── ADD EQUIPMENT ─────────────────────────────────
} elseif ($action === 'create') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO equipment (name, category, location, condition_status, last_maintained, notes, added_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['name'],
            $input['category'],
            $input['location']        ?? '',
            $input['condition_status']?? 'Good',
            $input['last_maintained'] ?: null,
            $input['notes']           ?? '',
            $input['added_by']        ?? null
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── UPDATE EQUIPMENT ──────────────────────────────
} elseif ($action === 'update') {
    $id = $input['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("
            UPDATE equipment
            SET name = ?, category = ?, location = ?,
                condition_status = ?, last_maintained = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $input['name'],
            $input['category'],
            $input['location']         ?? '',
            $input['condition_status'] ?? 'Good',
            $input['last_maintained']  ?: null,
            $input['notes']            ?? '',
            $id
        ]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── DELETE EQUIPMENT ──────────────────────────────
} elseif ($action === 'delete') {
    $id = $input['id'] ?? $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM equipment WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
}
?>
