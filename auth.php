<?php
require 'config.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? 'login';

// ── LOGIN ─────────────────────────────────────────
if ($action === 'login') {
    $username = $input['username'] ?? $_POST['username'] ?? '';
    $password = $input['password'] ?? $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM members WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // ── Auto-expire membership if past expiry date ──
        $status = $user['status'] ?? 'Active';
        if (!$user['is_admin'] && !empty($user['expiry_date']) && $user['expiry_date'] < date('Y-m-d') && $status === 'Active') {
            $pdo->prepare("UPDATE members SET status='Expired' WHERE id=?")->execute([$user['id']]);
            $status = 'Expired';
        }

        echo json_encode([
            'success' => true,
            'user' => [
                'id'              => $user['id'],
                'username'        => $user['username'],
                'full_name'       => $user['full_name'],
                'email'           => $user['email']      ?? null,
                'phone'           => $user['phone']      ?? null,
                'birthdate'       => $user['birthdate']  ?? null,
                'age'             => $user['age']        ?? null,
                'gender'          => $user['gender']     ?? null,
                'membership_type' => $user['membership_type'],
                'is_admin'        => (bool)($user['is_admin'] ?? 0),
                'expiry_date'     => $user['expiry_date']  ?? null,
                'join_date'       => $user['join_date']    ?? $user['created_at'] ?? null,
                'status'          => $status
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
    }

// ── FRONT DESK: MEMBER SEARCH ─────────────────────
// Used by the Java desktop app to search members by name or username.
// No admin check here — the desktop app itself is admin-only (login-gated).
} elseif ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) {
        echo json_encode([]);
        exit;
    }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT id, full_name, username, membership_type, status, expiry_date
        FROM members
        WHERE is_admin = 0
          AND (full_name LIKE ? OR username LIKE ?)
        ORDER BY full_name ASC
        LIMIT 30
    ");
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll());

// ── FRONT DESK: MEMBER CHECK-IN ───────────────────
// Called by the Java desktop app when a member arrives at the gym.
// Logs an attendance record with the current timestamp.
} elseif ($action === 'checkin') {
    $member_id = (int)($input['member_id'] ?? 0);
    if ($member_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid member ID.']);
        exit;
    }

    // Verify member exists and is not expired
    $check = $pdo->prepare("SELECT id, full_name, status FROM members WHERE id = ? AND is_admin = 0");
    $check->execute([$member_id]);
    $member = $check->fetch();

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

    // Log attendance
    $stmt = $pdo->prepare("
        INSERT INTO attendance (member_id, check_in_time, date)
        VALUES (?, NOW(), CURDATE())
    ");
    $stmt->execute([$member_id]);

    echo json_encode([
        'success'     => true,
        'id'          => $pdo->lastInsertId(),
        'member_name' => $member['full_name'],
        'message'     => $member['full_name'] . ' checked in successfully.'
    ]);

// ── REGISTER ──────────────────────────────────────
} elseif ($action === 'register') {
    $data      = $input;
    $password  = $data['password'] ?? '';

    // ── Membership type whitelist — Walk-in cannot self-register ──
    $allowed_memberships = ['Basic', 'Premium', 'VIP'];
    $membership = in_array($data['membership_type'] ?? '', $allowed_memberships)
                  ? $data['membership_type']
                  : 'Basic';

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // ── Server-side validation (catches direct API calls bypassing the browser) ──
    $full_name = trim($data['full_name'] ?? '');
    $phone     = preg_replace('/[\s\-]/', '', $data['phone'] ?? '');
    $birthdate = $data['birthdate'] ?? '';

    if (strlen($full_name) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Full name must be at least 2 characters.']);
        exit;
    }
    if (strlen($full_name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Full name must not exceed 100 characters.']);
        exit;
    }
    if (!preg_match('/^[a-zA-ZÀ-ÿ\s\-\.]+$/', $full_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Full name may only contain letters, spaces, hyphens, and periods.']);
        exit;
    }
    if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Invalid Philippine phone number (e.g. 09XXXXXXXXX).']);
        exit;
    }
    // ── Birthdate: required, valid format, not in the future ──
    if (empty($birthdate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) || !strtotime($birthdate)) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Please provide a valid birthdate.']);
        exit;
    }
    $bd    = new DateTime($birthdate);
    $today = new DateTime('today');
    if ($bd >= $today) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Birthdate cannot be today or in the future.']);
        exit;
    }
    // ── Compute age purely from birthdate — never trust the client-sent age ──
    $age = (int)$today->diff($bd)->y;
    if ($age < 15 || $age > 80) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: You must be between 15 and 80 years old to register.']);
        exit;
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Password must be 8–72 characters.']);
        exit;
    }
    // ── Password complexity: must contain at least one letter and one number or special char ──
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9\W]/', $password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: Password must contain at least one letter and one number or special character.']);
        exit;
    }

    // ── Check username uniqueness before insert ──
    $uname_check = $pdo->prepare("SELECT id FROM members WHERE username = ?");
    $uname_check->execute([$data['username']]);
    if ($uname_check->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: That username is already taken.']);
        exit;
    }

    // ── Check email uniqueness before insert ──
    $email_check = $pdo->prepare("SELECT id FROM members WHERE email = ?");
    $email_check->execute([$data['email']]);
    if ($email_check->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: That email address is already registered.']);
        exit;
    }

    // ── Check phone uniqueness before insert ──
    $phone_check = $pdo->prepare("SELECT id FROM members WHERE phone = ?");
    $phone_check->execute([$phone]);
    if ($phone_check->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: This phone number is already registered.']);
        exit;
    }

    // Auto-calculate expiry date based on membership type
    if ($membership === 'VIP') {
        $expiry = date('Y-m-d', strtotime('+1 year'));
    } elseif ($membership === 'Premium') {
        $expiry = date('Y-m-d', strtotime('+6 months'));
    } else {
        // Basic
        $expiry = date('Y-m-d', strtotime('+1 month'));
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO members
                (username, email, password, full_name, phone, birthdate, age, gender, membership_type, expiry_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
        ");
        $stmt->execute([
            $data['username'],
            $data['email'],
            $hashedPassword,
            $data['full_name'],
            $phone,
            $birthdate,
            $age,
            $data['gender'] ?? '',
            $membership,
            $expiry
        ]);
        echo json_encode(['success' => true, 'message' => 'Registration successful']);
    } catch (PDOException $e) {
        http_response_code(400);
        if ($e->errorInfo[1] === 1054) {
            try {
                $stmt2 = $pdo->prepare("
                    INSERT INTO members
                        (username, email, password, full_name, phone, age, gender, membership_type, expiry_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                ");
                $stmt2->execute([
                    $data['username'],
                    $data['email'],
                    $hashedPassword,
                    $data['full_name'],
                    $phone,
                    $age,
                    $data['gender'] ?? '',
                    $membership,
                    $expiry
                ]);
                echo json_encode(['success' => true, 'message' => 'Registration successful']);
            } catch (PDOException $e2) {
                echo json_encode(['error' => 'Registration failed: ' . $e2->getMessage()]);
            }
        } elseif ($e->errorInfo[1] === 1062) {
            echo json_encode(['error' => 'Registration failed: A duplicate value was detected. Check username, email, or phone.']);
        } else {
            echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
        }
    }

// ── PASSWORD RESET REQUEST ────────────────────────
} elseif ($action === 'forgot_password') {
    $email = trim($input['email'] ?? '');
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email address is required.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, full_name FROM members WHERE email = ? AND is_admin = 0");
    $stmt->execute([$email]);
    $member = $stmt->fetch();
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'No account found with that email address.']);
        exit;
    }
    $existing = $pdo->prepare("SELECT id FROM password_reset_tokens WHERE member_id = ? AND status = 'pending'");
    $existing->execute([$member['id']]);
    if ($existing->fetch()) {
        echo json_encode(['success' => true, 'message' => 'You already have a pending reset request. Please approach the gym admin.']);
        exit;
    }
    $pdo->prepare("INSERT INTO password_reset_tokens (member_id, status) VALUES (?, 'pending')")
        ->execute([$member['id']]);
    echo json_encode([
        'success' => true,
        'message' => 'Reset request submitted! Please approach the gym admin to get your reset token.'
    ]);

// ── ADMIN: LIST RESET REQUESTS ────────────────────────────────
} elseif ($action === 'list_reset_requests') {
    $admin_id = $input['admin_id'] ?? 0;
    $filter   = $input['filter'] ?? 'pending';
    $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND is_admin = 1");
    $chk->execute([$admin_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }
    $where = $filter === 'pending' ? "WHERE t.status = 'pending'" : "";
    $stmt  = $pdo->prepare("
        SELECT t.id, t.status, t.token, t.expires_at, t.created_at,
               m.full_name, m.username, m.email
        FROM password_reset_tokens t
        JOIN members m ON m.id = t.member_id
        $where
        ORDER BY t.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    echo json_encode($stmt->fetchAll());

// ── ADMIN: APPROVE RESET REQUEST ─────────────────────────────
} elseif ($action === 'approve_reset') {
    $admin_id   = $input['admin_id'] ?? 0;
    $request_id = $input['request_id'] ?? 0;
    $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND is_admin = 1");
    $chk->execute([$admin_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }
    $req = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE id = ? AND status = 'pending'");
    $req->execute([$request_id]);
    $row = $req->fetch();
    if (!$row) {
        http_response_code(400);
        echo json_encode(['error' => 'Request not found or already processed.']);
        exit;
    }
    $token     = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $pdo->prepare("
        UPDATE password_reset_tokens
        SET status = 'approved', token = ?, expires_at = ?, used = 0, attempts = 0
        WHERE id = ?
    ")->execute([$token, $expiresAt, $request_id]);
    echo json_encode([
        'success'    => true,
        'token'      => $token,
        'expires_at' => $expiresAt,
        'message'    => 'OTP generated. Read this 6-digit code to the member — expires in 30 minutes.'
    ]);

// ── ADMIN: DENY RESET REQUEST ─────────────────────────────────
} elseif ($action === 'deny_reset') {
    $admin_id   = $input['admin_id'] ?? 0;
    $request_id = $input['request_id'] ?? 0;
    $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND is_admin = 1");
    $chk->execute([$admin_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }
    $pdo->prepare("UPDATE password_reset_tokens SET status = 'denied', used = 1 WHERE id = ? AND status = 'pending'")
        ->execute([$request_id]);
    echo json_encode(['success' => true, 'message' => 'Request denied.']);

// ── ADMIN: GENERATE RESET TOKEN (front-desk, face-to-face) ───
// Admin enters member's email → server generates token immediately → returns it to desk.
// No "pending request" needed — admin is physically present with the member.
} elseif ($action === 'generate_reset_token') {
    $admin_id = (int)($input['admin_id'] ?? 0);
    $email    = trim($input['email'] ?? '');

    $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND is_admin = 1");
    $chk->execute([$admin_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, full_name FROM members WHERE email = ? AND is_admin = 0");
    $stmt->execute([$email]);
    $member = $stmt->fetch();
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'No member account found with that Gmail address.']);
        exit;
    }

    // Invalidate any previous pending/approved tokens for this member
    $pdo->prepare("UPDATE password_reset_tokens SET used = 1, status = 'used' WHERE member_id = ? AND status IN ('pending','approved')")
        ->execute([$member['id']]);

    // Generate a 6-digit token, valid for 10 minutes
    $token     = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $pdo->prepare("INSERT INTO password_reset_tokens (member_id, token, status, expires_at, used, attempts) VALUES (?, ?, 'approved', ?, 0, 0)")
        ->execute([$member['id'], $token, $expiresAt]);

    echo json_encode([
        'success'    => true,
        'token'      => $token,
        'full_name'  => $member['full_name'],
        'expires_at' => $expiresAt
    ]);

// ── RESET PASSWORD ────────────────────────────────
} elseif ($action === 'reset_password') {
    $token    = trim($input['token']        ?? '');
    // Accept 'new_password' (Java front-desk app) OR 'password' (website)
    $password = trim($input['new_password'] ?? $input['password'] ?? '');
    $confirm  = trim($input['confirm']      ?? $password); // Java doesn't send confirm — server skips that check
    if (empty($token) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token and new password are required.']);
        exit;
    }
    if ($password !== $confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwords do not match.']);
        exit;
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be 8–72 characters.']);
        exit;
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9\W]/', $password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must contain at least one letter and one number or special character.']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT t.id, t.member_id, t.expires_at, t.used, t.attempts
        FROM password_reset_tokens t
        WHERE t.token = ? AND t.status = 'approved'
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid reset code. Please check the 6-digit code and try again.']);
        exit;
    }
    $pdo->prepare("UPDATE password_reset_tokens SET attempts = attempts + 1 WHERE id = ?")
        ->execute([$row['id']]);
    $refreshStmt = $pdo->prepare("SELECT used, attempts, expires_at FROM password_reset_tokens WHERE id = ?");
    $refreshStmt->execute([$row['id']]);
    $fresh = $refreshStmt->fetch();
    if ($fresh['used']) {
        http_response_code(400);
        echo json_encode(['error' => 'This reset code has already been used.']);
        exit;
    }
    if ((int)$fresh['attempts'] > 5) {
        $pdo->prepare("UPDATE password_reset_tokens SET used = 1, status = 'used' WHERE id = ?")->execute([$row['id']]);
        http_response_code(400);
        echo json_encode(['error' => 'Too many failed attempts. Please request a new reset code from the admin.']);
        exit;
    }
    if (new DateTime() > new DateTime($fresh['expires_at'])) {
        http_response_code(400);
        echo json_encode(['error' => 'This reset code has expired (30 min). Please request a new one from the admin.']);
        exit;
    }
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE members SET password = ? WHERE id = ?")->execute([$hashed, $row['member_id']]);
    $pdo->prepare("UPDATE password_reset_tokens SET used = 1, status = 'used' WHERE id = ?")->execute([$row['id']]);
    echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
}
?>