<?php
require 'config.php';

$action = $_GET['action'] ?? 'list';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// ── ADMIN ACTIONS: verify admin ──
$admin_actions = ['list','update','auto_expire','upload_trainer','upload_product'];
if (in_array($action, $admin_actions)) {
    $admin_id = $input['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ?? 0;
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

// ── LIST ALL MEMBERS ──────────────────────────────
if ($action === 'list') {
    // NOTE: ALTER TABLE migrations removed — they ran on every request and caused
    // metadata lock overhead under concurrent load. These columns are defined in
    // fittech_complete.sql. Run the migration patch once if on an older schema.

    $stmt = $pdo->query("
        SELECT id, full_name, email,
               phone, gender, birthdate, age,
               membership_type, status, expiry_date, join_date,
               created_at, username,
               DATEDIFF(expiry_date, CURDATE()) as days_left
        FROM members
        WHERE is_admin = 0
        ORDER BY created_at DESC
    ");
    echo json_encode($stmt->fetchAll());

// ── MEMBER STATS ──────────────────────────────────
} elseif ($action === 'stats') {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM members");
    $total = $stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM members WHERE status = 'Active'");
    $active = $stmt->fetch()['active'];
    echo json_encode(['total' => $total, 'active' => $active]);

// ── UPDATE MEMBER (admin) ─────────────────────────
} elseif ($action === 'update') {
    $id     = (int)($input['id'] ?? 0);
    $status = $input['status']   ?? 'Active';

    // ── Whitelist allowed membership types ──
    $allowed_memberships = ['Basic', 'Premium', 'VIP'];
    $membership = in_array($input['membership_type'] ?? '', $allowed_memberships)
                  ? $input['membership_type']
                  : 'Basic';

    // Auto-calculate expiry based on membership type
    if ($membership === 'VIP') {
        $expiry = date('Y-m-d', strtotime('+1 year'));
    } elseif ($membership === 'Premium') {
        $expiry = date('Y-m-d', strtotime('+6 months'));
    } else {
        $expiry = date('Y-m-d', strtotime('+1 month'));
    }

    // Allow manual override if provided
    if (!empty($input['expiry_date'])) {
        $expiry = $input['expiry_date'];
    }

    // ── Personal info fields ──
    $full_name = mb_substr(trim($input['full_name'] ?? ''), 0, 100);
    $username  = mb_substr(trim($input['username']  ?? ''), 0, 50);
    $email     = mb_substr(trim($input['email']     ?? ''), 0, 100);
    $phone     = preg_replace('/[\s\-]/', '', $input['phone'] ?? '');
    $gender    = in_array($input['gender'] ?? '', ['Male','Female','Other','']) ? ($input['gender'] ?? '') : '';
    $birthdate = $input['birthdate'] ?? '';

    // ── Require core fields ──
    if (strlen($full_name) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Full name must be at least 2 characters.']);
        exit;
    }
    if (strlen($full_name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Full name must not exceed 100 characters.']);
        exit;
    }
    if (!preg_match('/^[a-zA-ZÀ-ÿ\s\-\.]+$/', $full_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Full name may only contain letters, spaces, hyphens, and periods.']);
        exit;
    }
    if (empty($username)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username is required.']);
        exit;
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid email address is required.']);
        exit;
    }
    if (!empty($phone) && !preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Philippine phone number (e.g. 09XXXXXXXXX).']);
        exit;
    }

    // ── Validate birthdate if provided ──
    $age = null;
    if (!empty($birthdate)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) || !strtotime($birthdate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid birthdate format.']);
            exit;
        }
        $bd    = new DateTime($birthdate);
        $today = new DateTime('today');
        if ($bd >= $today) {
            http_response_code(400);
            echo json_encode(['error' => 'Birthdate cannot be today or in the future.']);
            exit;
        }
        $age = (int)$today->diff($bd)->y;
        if ($age < 15 || $age > 80) {
            http_response_code(400);
            echo json_encode(['error' => 'Member must be between 15 and 80 years old.']);
            exit;
        }
    }

    // ── Check uniqueness (exclude this member's own record) ──
    $uCheck = $pdo->prepare("SELECT id FROM members WHERE username = ? AND id != ?");
    $uCheck->execute([$username, $id]);
    if ($uCheck->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'That username is already taken by another member.']);
        exit;
    }

    $eCheck = $pdo->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
    $eCheck->execute([$email, $id]);
    if ($eCheck->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'That email is already registered to another member.']);
        exit;
    }

    if (!empty($phone)) {
        $pCheck = $pdo->prepare("SELECT id FROM members WHERE phone = ? AND id != ?");
        $pCheck->execute([$phone, $id]);
        if ($pCheck->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'That phone number is already registered to another member.']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE members
            SET full_name       = ?,
                username        = ?,
                email           = ?,
                phone           = ?,
                gender          = ?,
                birthdate       = ?,
                age             = ?,
                membership_type = ?,
                status          = ?,
                expiry_date     = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $full_name,
            $username,
            $email,
            $phone ?: null,
            $gender ?: null,
            $birthdate ?: null,
            $age,
            $membership,
            $status,
            $expiry,
            $id
        ]);
        echo json_encode(['success' => true, 'expiry_date' => $expiry]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── AUTO EXPIRE CHECK ─────────────────────────────
} elseif ($action === 'auto_expire') {
    // Mark expired memberships automatically
    $stmt = $pdo->prepare("
        UPDATE members
        SET status = 'Expired'
        WHERE expiry_date < CURDATE()
        AND status = 'Active'
    ");
    $stmt->execute();
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);

// ── SELF EXPIRE (called automatically when member logs in past expiry) ──
} elseif ($action === 'auto_expire_self') {
    // No admin check — but we strictly guard what can be changed:
    //   1. id must be a positive integer
    //   2. The row must exist AND is_admin must be 0 (cannot touch admin accounts)
    //   3. expiry_date must genuinely be in the past (server-enforced, not client-trusted)
    //   4. Status must currently be 'Active' — already-Expired rows are left alone
    // This means even if someone POSTs a foreign member's id, the expiry_date
    // guard prevents accidentally expiring a member whose membership is still valid.
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE members
            SET status = 'Expired'
            WHERE id          = ?
              AND is_admin     = 0
              AND status       = 'Active'
              AND expiry_date  < CURDATE()
        ");
        $stmt->execute([$id]);
    }
    echo json_encode(['success' => true]);

// ── UPLOAD TRAINER PHOTO ──────────────────────────
} elseif ($action === 'upload_trainer') {
    $trainer_id = $_POST['trainer_id'] ?? 0;
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
    // ── Server-side file size check (max 5MB) ──
    if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'File too large. Maximum allowed size is 5MB.']);
        exit;
    }
    $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp'];
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type']);
        exit;
    }
    $dir = 'uploads/trainers/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'trainer_' . $trainer_id . '.' . $ext;
    move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $filename);

    // Update trainer photo in DB
    $stmt = $pdo->prepare("UPDATE trainers SET photo = ? WHERE id = ?");
    $stmt->execute([$dir . $filename, $trainer_id]);
    echo json_encode(['success' => true, 'photo' => $dir . $filename]);

// ── UPLOAD PRODUCT IMAGE ──────────────────────────
} elseif ($action === 'upload_product') {
    $product_id = $_POST['product_id'] ?? 0;
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
    // ── Server-side file size check (max 5MB) ──
    if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'File too large. Maximum allowed size is 5MB.']);
        exit;
    }
    $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp'];
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type']);
        exit;
    }
    $dir = 'uploads/products/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'product_' . $product_id . '.' . $ext;
    move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $filename);

    // Update product image in DB
    $stmt = $pdo->prepare("UPDATE products SET image_url = ? WHERE id = ?");
    $stmt->execute([$dir . $filename, $product_id]);
    echo json_encode(['success' => true, 'image_url' => $dir . $filename]);

// ── MEMBER SELF-UPDATE (personal info only) ───────
// Member can update their own full_name, username, email, phone.
// They cannot change membership_type, status, expiry, or is_admin.
// Uniqueness checks exclude the member's own row.
} elseif ($action === 'self_update') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing member ID.']);
        exit;
    }

    // Confirm the id belongs to a real non-admin member
    $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND is_admin = 0");
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }

    $full_name = mb_substr(trim($input['full_name'] ?? ''), 0, 100);
    $username  = mb_substr(trim($input['username']  ?? ''), 0, 50);
    $email     = mb_substr(trim($input['email']     ?? ''), 0, 100);
    $phone     = preg_replace('/[\s\-]/', '', $input['phone'] ?? '');

    // ── Validate required fields ──
    if (strlen($full_name) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Full name must be at least 2 characters.']);
        exit;
    }
    if (strlen($full_name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Full name must not exceed 100 characters.']);
        exit;
    }
    if (!preg_match('/^[a-zA-ZÀ-ÿ\s\-\.]+$/', $full_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Full name may only contain letters, spaces, hyphens, and periods.']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username must be 3–20 characters (letters, numbers, underscores only).']);
        exit;
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid email address is required.']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9._%+\-]+@gmail\.com$/i', $email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only Gmail addresses are accepted (e.g. yourname@gmail.com).']);
        exit;
    }
    if (!empty($phone) && !preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Phone must be a valid Philippine number (e.g. 09XXXXXXXXX or +639XXXXXXXXX).']);
        exit;
    }

    // ── Uniqueness checks — exclude own row ──
    $uCheck = $pdo->prepare("SELECT id FROM members WHERE username = ? AND id != ?");
    $uCheck->execute([$username, $id]);
    if ($uCheck->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'That username is already taken.']);
        exit;
    }

    $eCheck = $pdo->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
    $eCheck->execute([$email, $id]);
    if ($eCheck->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'That email address is already registered to another account.']);
        exit;
    }

    if (!empty($phone)) {
        $pCheck = $pdo->prepare("SELECT id FROM members WHERE phone = ? AND id != ?");
        $pCheck->execute([$phone, $id]);
        if ($pCheck->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'That phone number is already registered to another account.']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE members
            SET full_name = ?,
                username  = ?,
                email     = ?,
                phone     = ?
            WHERE id = ? AND is_admin = 0
        ");
        $stmt->execute([
            $full_name,
            $username,
            $email,
            $phone ?: null,
            $id
        ]);
        echo json_encode([
            'success'   => true,
            'full_name' => $full_name,
            'username'  => $username,
            'email'     => $email,
            'phone'     => $phone ?: null,
        ]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── MEMBER CHANGE PASSWORD ────────────────────────
// Requires current password verification before allowing a change.
// Applies the same complexity rules as registration.
} elseif ($action === 'change_password') {
    $id           = (int)($input['id']           ?? 0);
    $old_password = trim($input['old_password']  ?? '');
    $new_password = trim($input['new_password']  ?? '');
    $confirm      = trim($input['confirm']        ?? '');

    if (!$id || !$old_password || !$new_password || !$confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'All password fields are required.']);
        exit;
    }

    // Fetch member's current hashed password
    $stmt = $pdo->prepare("SELECT id, password FROM members WHERE id = ? AND is_admin = 0");
    $stmt->execute([$id]);
    $member = $stmt->fetch();
    if (!$member) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }

    // Verify old password
    if (!password_verify($old_password, $member['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Current password is incorrect.']);
        exit;
    }

    // New password must differ from old
    if (password_verify($new_password, $member['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'New password must be different from your current password.']);
        exit;
    }

    if ($new_password !== $confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'New passwords do not match.']);
        exit;
    }
    if (strlen($new_password) < 8 || strlen($new_password) > 72) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be 8–72 characters.']);
        exit;
    }
    if (!preg_match('/[A-Za-z]/', $new_password) || !preg_match('/[0-9\W]/', $new_password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must contain at least one letter and one number or special character.']);
        exit;
    }

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE members SET password = ? WHERE id = ? AND is_admin = 0")
        ->execute([$hashed, $id]);

    echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
}
?>