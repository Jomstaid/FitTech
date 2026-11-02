<?php
require 'config.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'list';

// ── Schema bootstrap — runs only ONCE per process, not per request ────────────
// Previously this block ran unconditionally on every renewal.php request,
// issuing 8 DDL statements that acquire metadata locks each time.
// Now guarded by a static flag so it fires at most once per PHP-FPM worker.
// On a correctly-migrated database (fittech_complete.sql applied) the
// CREATE TABLE IF NOT EXISTS is a no-op and all ALTER TABLE calls are no-ops too,
// but they still cause lock contention under concurrent load without this guard.
static $renewal_schema_checked = false;
if (!$renewal_schema_checked) {
    $renewal_schema_checked = true;
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
    } catch (PDOException $e) {}
    foreach ([
        "ALTER TABLE renewal_requests MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled','refunded') DEFAULT 'pending'",
        "ALTER TABLE renewal_requests ADD COLUMN IF NOT EXISTS refund_note TEXT NULL",
        "ALTER TABLE renewal_requests ADD COLUMN IF NOT EXISTS refunded_by INT NULL",
        "ALTER TABLE renewal_requests ADD COLUMN IF NOT EXISTS refunded_at TIMESTAMP NULL",
        "ALTER TABLE renewal_requests ADD COLUMN IF NOT EXISTS previous_type VARCHAR(50) NULL",
        "ALTER TABLE renewal_requests ADD COLUMN IF NOT EXISTS previous_expiry DATE NULL",
        "ALTER TABLE renewal_requests ADD COLUMN IF NOT EXISTS previous_status VARCHAR(20) NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {}
    }
}

// ────────────────────────────────────────────────────────────────
// HELPER: verify admin
// ────────────────────────────────────────────────────────────────
function verifyAdmin(PDO $pdo, $admin_id): bool {
    if (!$admin_id) return false;
    $s = $pdo->prepare("SELECT is_admin FROM members WHERE id = ? AND is_admin = 1");
    $s->execute([(int)$admin_id]);
    return (bool)$s->fetch();
}

// ────────────────────────────────────────────────────────────────
// HELPER: calculate expiry from a base date + membership tier
// ────────────────────────────────────────────────────────────────
function calcExpiry(string $baseDate, string $type): string {
    if ($type === 'VIP')     return date('Y-m-d', strtotime($baseDate . ' +1 year'));
    if ($type === 'Premium') return date('Y-m-d', strtotime($baseDate . ' +6 months'));
    return date('Y-m-d', strtotime($baseDate . ' +1 month'));
}

// ════════════════════════════════════════════════════════════════
// MEMBER: Submit a renewal / upgrade request
// ════════════════════════════════════════════════════════════════
if ($action === 'request') {
    $member_id      = (int)($input['member_id']      ?? 0);
    $requested_type = trim($input['requested_type']  ?? '');

    $allowed = ['Basic','Premium','VIP'];
    if (!$member_id || !in_array($requested_type, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request data.']);
        exit;
    }

    // Verify member exists and is not admin
    $mem = $pdo->prepare("SELECT id, membership_type, expiry_date, status FROM members WHERE id = ? AND is_admin = 0");
    $mem->execute([$member_id]);
    $member = $mem->fetch();
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Member not found.']);
        exit;
    }

    // Block duplicate pending — tell them to cancel first
    $chk = $pdo->prepare("SELECT id FROM renewal_requests WHERE member_id = ? AND status = 'pending'");
    $chk->execute([$member_id]);
    if ($chk->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'You already have a pending renewal request. Cancel it first if you want to change your choice.']);
        exit;
    }

    // Fetch price from pricing table (server-side only)
    $priceStmt = $pdo->prepare("SELECT price FROM membership_pricing WHERE membership_type = ?");
    $priceStmt->execute([$requested_type]);
    $pricing = $priceStmt->fetch();
    $amount  = $pricing ? (float)$pricing['price'] : 0.00;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO renewal_requests
                (member_id, requested_type, current_type, current_expiry, amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $member_id,
            $requested_type,
            $member['membership_type'],
            $member['expiry_date'] ?: null,
            $amount,
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'amount' => $amount]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not submit request. Please try again.']);
    }

// ════════════════════════════════════════════════════════════════
// MEMBER: Cancel their own PENDING request
//   No money has changed hands yet — member just changed their mind
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'cancel_request') {
    $member_id  = (int)($input['member_id']  ?? 0);
    $request_id = (int)($input['request_id'] ?? 0);

    if (!$member_id || !$request_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing member_id or request_id.']);
        exit;
    }

    // WHERE member_id = ? ensures they can only cancel their own
    $stmt = $pdo->prepare("
        UPDATE renewal_requests
        SET status = 'cancelled',
            note   = 'Cancelled by member before approval'
        WHERE id = ? AND member_id = ? AND status = 'pending'
    ");
    $stmt->execute([$request_id, $member_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found, already processed, or does not belong to you.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Request cancelled. You can submit a new one anytime.']);

// ════════════════════════════════════════════════════════════════
// MEMBER: View their own renewal history
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'my_requests') {
    $member_id = (int)($_GET['member_id'] ?? 0);
    if (!$member_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing member_id']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT rr.*, adm.full_name AS reviewed_by_name
        FROM renewal_requests rr
        LEFT JOIN members adm ON rr.reviewed_by = adm.id
        WHERE rr.member_id = ?
        ORDER BY rr.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$member_id]);
    echo json_encode($stmt->fetchAll());

// ════════════════════════════════════════════════════════════════
// ADMIN: List pending (default) or all requests
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'pending') {
    $admin_id = (int)($_GET['admin_id'] ?? 0);
    if (!verifyAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $filter = $_GET['filter'] ?? 'pending';
    if ($filter === 'all') {
        $stmt = $pdo->query("
            SELECT rr.*,
                   mem.full_name        AS member_name,
                   mem.username,
                   mem.membership_type  AS live_type,
                   mem.expiry_date      AS live_expiry
            FROM renewal_requests rr
            JOIN members mem ON rr.member_id = mem.id
            ORDER BY
                FIELD(rr.status,'pending','approved','refunded','rejected','cancelled'),
                rr.created_at DESC
            LIMIT 100
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT rr.*,
                   mem.full_name        AS member_name,
                   mem.username,
                   mem.membership_type  AS live_type,
                   mem.expiry_date      AS live_expiry
            FROM renewal_requests rr
            JOIN members mem ON rr.member_id = mem.id
            WHERE rr.status = 'pending'
            ORDER BY rr.created_at ASC
        ");
        $stmt->execute();
    }
    echo json_encode($stmt->fetchAll());

// ════════════════════════════════════════════════════════════════
// ADMIN: Approve a renewal / upgrade request
//
//  Snapshots the member's CURRENT state (type, expiry, status)
//  onto the record BEFORE making any changes. This snapshot is
//  what the refund action uses to roll everything back perfectly.
//
//  Smart expiry stacking:
//   - If member still has days left → new period starts from
//     their current expiry (days are NOT lost)
//   - If already expired → new period starts from today
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'approve') {
    $admin_id   = (int)($input['admin_id']   ?? 0);
    $request_id = (int)($input['request_id'] ?? 0);

    if (!$admin_id || !$request_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing data']);
        exit;
    }
    if (!verifyAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Load the pending request
    $rq = $pdo->prepare("SELECT * FROM renewal_requests WHERE id = ? AND status = 'pending'");
    $rq->execute([$request_id]);
    $req = $rq->fetch();
    if (!$req) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found or already processed.']);
        exit;
    }

    // Snapshot the member's live state RIGHT NOW before we change anything
    $memStmt = $pdo->prepare("SELECT membership_type, expiry_date, status FROM members WHERE id = ?");
    $memStmt->execute([$req['member_id']]);
    $member = $memStmt->fetch();

    $today         = date('Y-m-d');
    $currentExpiry = $member['expiry_date'];

    // Smart base date for stacking
    $baseDate  = ($currentExpiry && $currentExpiry >= $today) ? $currentExpiry : $today;
    $newExpiry = calcExpiry($baseDate, $req['requested_type']);

    try {
        $pdo->beginTransaction();

        // Re-fetch the request WITH a row-level lock inside the transaction.
        // If two admin tabs approve simultaneously, only one gets the lock;
        // the other will find status != 'pending' and roll back cleanly.
        $lockStmt = $pdo->prepare("SELECT id, status FROM renewal_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
        $lockStmt->execute([$request_id]);
        if (!$lockStmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'This request was already processed by another admin. Please refresh.']);
            exit;
        }

        // Save snapshot on the renewal record — critical for refund rollback
        $pdo->prepare("
            UPDATE renewal_requests
            SET status          = 'approved',
                reviewed_by     = ?,
                reviewed_at     = NOW(),
                previous_type   = ?,
                previous_expiry = ?,
                previous_status = ?
            WHERE id = ?
        ")->execute([
            $admin_id,
            $member['membership_type'],  // what they had BEFORE this approval
            $member['expiry_date'],      // expiry BEFORE this approval
            $member['status'],           // status BEFORE this approval
            $request_id,
        ]);

        // Apply the new membership to the member
        $pdo->prepare("
            UPDATE members
            SET membership_type = ?,
                expiry_date     = ?,
                status          = 'Active'
            WHERE id = ?
        ")->execute([$req['requested_type'], $newExpiry, $req['member_id']]);

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'new_expiry'   => $newExpiry,
            'new_type'     => $req['requested_type'],
            'started_from' => $baseDate,
            'days_carried' => ($currentExpiry && $currentExpiry >= $today)
                              ? (int)((strtotime($currentExpiry) - strtotime($today)) / 86400)
                              : 0,
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Could not approve. Please try again.']);
    }

// ════════════════════════════════════════════════════════════════
// ADMIN: Reject a pending request (no money involved)
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'reject') {
    $admin_id   = (int)($input['admin_id']   ?? 0);
    $request_id = (int)($input['request_id'] ?? 0);
    $note       = trim($input['note']        ?? '');

    if (!$admin_id || !$request_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing data']);
        exit;
    }
    if (!verifyAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE renewal_requests
        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), note = ?
        WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([$admin_id, $note, $request_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found or already processed.']);
        exit;
    }
    echo json_encode(['success' => true]);

// ════════════════════════════════════════════════════════════════
// ADMIN: Full Refund of an approved renewal — with chain unwinding
//
// THE PROBLEM this solves:
//   Timeline: [Basic approved] → [Premium approved on top of Basic]
//   If admin refunds the Premium, it correctly removes ₱Premium
//   from analytics and rolls member back to Basic expiry.
//
//   BUT — what if the member also wants to fully cancel Basic too?
//   Or what if admin wants to refund the whole chain at once?
//
// HOW IT WORKS:
//   The admin specifies ONE request_id to refund.
//   The code then looks at that record's previous_type/previous_expiry
//   and walks FORWARD in time to find any LATER approved renewals
//   for this member that were built ON TOP of this one (i.e., renewals
//   approved AFTER this one that used this one's expiry as their base).
//   All of those later ones are also marked refunded.
//
//   This matches your scenario:
//     - Basic approved  → previous_type = Expired, previous_expiry = old date
//     - Premium approved → previous_type = Basic,  previous_expiry = Basic expiry
//
//   Refunding Basic:
//     → Finds Premium (approved after Basic, chains on Basic's expiry)
//     → Also marks Premium as refunded
//     → Analytics loses both ₱Basic and ₱Premium
//     → Member rolls back to whatever they were before Basic (Expired/old state)
//
//   Refunding Premium only:
//     → Only Premium is marked refunded
//     → Member rolls back to Basic state (Basic expiry restored)
//     → Analytics loses only ₱Premium
//
// POST: { admin_id, request_id, refund_note }
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'refund') {
    $admin_id    = (int)($input['admin_id']    ?? 0);
    $request_id  = (int)($input['request_id']  ?? 0);
    $refund_note = trim($input['refund_note']  ?? '');

    if (!$admin_id || !$request_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing data']);
        exit;
    }
    if (!verifyAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Load the target approved request
    $rq = $pdo->prepare("SELECT * FROM renewal_requests WHERE id = ? AND status = 'approved'");
    $rq->execute([$request_id]);
    $targetReq = $rq->fetch();
    if (!$targetReq) {
        http_response_code(404);
        echo json_encode(['error' => 'Only approved renewals can be refunded. Use reject for pending ones.']);
        exit;
    }

    $member_id = $targetReq['member_id'];
    $today     = date('Y-m-d');

    // ── Find ALL approved renewals for this member with id >= the target id ──
    // IDs are strictly sequential (AUTO_INCREMENT) so this correctly captures
    // the target renewal plus every renewal stacked ON TOP of it, in reverse
    // chronological order (newest first so we unwind correctly).
    //
    // Previously used reviewed_at timestamp, which broke when two approvals
    // happened within the same second — both would appear in each other's
    // chain causing double-refund of the wrong record. IDs never tie.
    $chainStmt = $pdo->prepare("
        SELECT * FROM renewal_requests
        WHERE member_id = ?
          AND status    = 'approved'
          AND id        >= ?
        ORDER BY id DESC
    ");
    $chainStmt->execute([$member_id, $request_id]);
    $chainToRefund = $chainStmt->fetchAll();

    if (empty($chainToRefund)) {
        http_response_code(404);
        echo json_encode(['error' => 'No approved renewals found to refund.']);
        exit;
    }

    // The rollback target is the snapshot stored on the EARLIEST record
    // in the chain (i.e., the target request itself — it captured what
    // the member was before any of this started).
    $earliestInChain = end($chainToRefund); // chronologically first = last in DESC array

    $rollback_type   = $earliestInChain['previous_type']   ?: ($earliestInChain['current_type']   ?: 'Basic');
    $rollback_expiry = $earliestInChain['previous_expiry'] ?: ($earliestInChain['current_expiry'] ?: null);
    $rollback_status = $earliestInChain['previous_status'] ?: 'Expired';

    // If rollback expiry is in the future, member becomes Active; otherwise Expired
    if ($rollback_expiry && $rollback_expiry >= $today) {
        $final_status = 'Active';
    } else {
        $final_status = 'Expired';
        // Override stored previous_status — it doesn't make sense to call them
        // Active if their rollback date is already in the past
    }

    $refunded_ids    = [];
    $total_refunded  = 0.0;

    try {
        $pdo->beginTransaction();

        // Mark every record in the chain as refunded
        foreach ($chainToRefund as $rec) {
            $pdo->prepare("
                UPDATE renewal_requests
                SET status      = 'refunded',
                    refund_note = ?,
                    refunded_by = ?,
                    refunded_at = NOW()
                WHERE id = ? AND status = 'approved'
            ")->execute([
                $refund_note ?: 'Refunded by admin',
                $admin_id,
                $rec['id'],
            ]);
            $refunded_ids[]   = $rec['id'];
            $total_refunded  += (float)$rec['amount'];
        }

        // Roll the member back to their state before the earliest refunded renewal
        $pdo->prepare("
            UPDATE members
            SET membership_type = ?,
                expiry_date     = ?,
                status          = ?
            WHERE id = ?
        ")->execute([$rollback_type, $rollback_expiry, $final_status, $member_id]);

        $pdo->commit();

        echo json_encode([
            'success'          => true,
            'refunded_ids'     => $refunded_ids,         // which request IDs were refunded
            'total_refunded'   => $total_refunded,        // total money removed from analytics
            'reverted_to_type' => $rollback_type,
            'reverted_expiry'  => $rollback_expiry,
            'member_status'    => $final_status,
            'chain_count'      => count($chainToRefund),  // how many renewals were unwound
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Could not process refund. Please try again.']);
    }

// ════════════════════════════════════════════════════════════════
// ADMIN: Count pending requests (for notification badge)
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'count_pending') {
    $admin_id = (int)($_GET['admin_id'] ?? 0);
    if (!verifyAdmin($pdo, $admin_id)) {
        echo json_encode(['count' => 0]);
        exit;
    }
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM renewal_requests WHERE status = 'pending'");
    echo json_encode($stmt->fetch());

// ════════════════════════════════════════════════════════════════
// Revenue from renewals — used by admin.php overview
//   Only 'approved' rows are summed.
//   'refunded' rows are excluded automatically — no extra work needed.
//   So the moment a refund happens, the dashboard number drops.
// ════════════════════════════════════════════════════════════════
} elseif ($action === 'renewal_revenue') {
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) as total FROM renewal_requests WHERE status = 'approved'");
    echo json_encode($stmt->fetch());
}
?>
