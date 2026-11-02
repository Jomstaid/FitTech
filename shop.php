<?php
require 'config.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'products';

// ── ADMIN GUARD helper ────────────────────────────
function verifyShopAdmin(PDO $pdo, $admin_id): bool {
    if (!$admin_id) return false;
    $s = $pdo->prepare("SELECT is_admin FROM members WHERE id = ? AND is_admin = 1");
    $s->execute([(int)$admin_id]);
    return (bool)$s->fetch();
}

// ── GET ALL PRODUCTS (active only for members) ────
if ($action === 'products') {
    $stmt = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY category, id");
    echo json_encode($stmt->fetchAll());

// ── GET ALL PRODUCTS including inactive (admin) ───
// Excludes is_active = -1 (soft deleted) but shows hidden (is_active = 0)
} elseif ($action === 'all_products') {
    $stmt = $pdo->query("SELECT * FROM products WHERE is_active >= 0 ORDER BY category, id");
    echo json_encode($stmt->fetchAll());

// ── ADD NEW PRODUCT (admin) ───────────────────────
} elseif ($action === 'create') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO products (name, category, price, badge, description, stock, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $input['name'],
            $input['category'],
            $input['price'],
            $input['badge']       ?? '',
            $input['description'] ?? '',
            $input['stock']       ?? 100,
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── UPDATE PRODUCT (admin) ────────────────────────
} elseif ($action === 'update') {
    $id = $input['id'] ?? 0;
    try {
        $name        = $input['name']        ?? '';
        $category    = $input['category']    ?? '';
        $price       = $input['price']       ?? 0;
        $badge       = $input['badge']       ?? '';
        $description = $input['description'] ?? '';
        $stock       = $input['stock']       ?? 0;
        $is_active   = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        $emoji = trim($input['emoji'] ?? '');
        if ($emoji !== '') {
            $stmt = $pdo->prepare("
                UPDATE products
                SET name=?, category=?, price=?, emoji=?,
                    badge=?, description=?, stock=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([$name,$category,$price,$emoji,$badge,$description,$stock,$is_active,$id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE products
                SET name=?, category=?, price=?,
                    badge=?, description=?, stock=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([$name,$category,$price,$badge,$description,$stock,$is_active,$id]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ── TOGGLE PRODUCT VISIBLE/HIDDEN ────────────────
} elseif ($action === 'toggle') {
    $id = $input['id'] ?? 0;
    // Never un-soft-delete a product via toggle — only flip between 0 (hidden) and 1 (visible)
    $stmt = $pdo->prepare("UPDATE products SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ? AND is_active >= 0");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);

// ── DELETE PRODUCT ────────────────────────────────
} elseif ($action === 'delete') {
    $id = $input['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'method' => 'hard']);
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("UPDATE products SET is_active = -1 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'method' => 'soft']);
    }

// ── PLACE ORDER ───────────────────────────────────
} elseif ($action === 'order') {
    $member_id = (int)($input['member_id'] ?? 0);
    $items     = $input['items']     ?? [];

    if (!$member_id || empty($items)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order data']);
        exit;
    }

    // Verify member exists and is not admin
    $memChk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND is_admin = 0");
    $memChk->execute([$member_id]);
    if (!$memChk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized or member not found.']);
        exit;
    }

    $validated_items = [];
    foreach ($items as $item) {
        $item_id  = (int)($item['id']  ?? 0);
        $item_qty = (int)($item['qty'] ?? 0);

        if ($item_qty <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Quantity must be at least 1 for each item.']);
            exit;
        }

        $prod_stmt = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id = ? AND is_active = 1");
        $prod_stmt->execute([$item_id]);
        $product = $prod_stmt->fetch();

        if (!$product) {
            http_response_code(400);
            echo json_encode(['error' => 'Product not found or no longer available.']);
            exit;
        }

        if ($product['stock'] < $item_qty) {
            http_response_code(400);
            echo json_encode(['error' => "Not enough stock for \"{$product['name']}\". Only {$product['stock']} left."]);
            exit;
        }

        $validated_items[] = [
            'id'    => $product['id'],
            'qty'   => $item_qty,
            'price' => $product['price'],
        ];
    }

    $total = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $validated_items));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO orders (member_id, total_amount, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$member_id, $total]);
        $order_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        foreach ($validated_items as $item) {
            $stmt->execute([$order_id, $item['id'], $item['qty'], $item['price']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $order_id, 'total' => $total]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Order could not be placed. Please try again.']);
    }

// ── GET ORDER HISTORY FOR MEMBER ──────────────────
} elseif ($action === 'history') {
    $member_id = $_GET['member_id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT o.id, o.total_amount, o.status, o.created_at,
               GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS items_summary,
               SUM(oi.quantity) AS total_items
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p     ON oi.product_id = p.id
        WHERE o.member_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$member_id]);
    echo json_encode($stmt->fetchAll());

// ── GET ORDER DETAIL ──────────────────────────────
} elseif ($action === 'detail') {
    $order_id  = $_GET['order_id']  ?? 0;
    $member_id = $_GET['member_id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT oi.quantity, oi.price, p.name, p.emoji, p.category
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders   o ON oi.order_id   = o.id
        WHERE oi.order_id = ? AND o.member_id = ?
    ");
    $stmt->execute([$order_id, $member_id]);
    echo json_encode($stmt->fetchAll());

// ── ALL ORDERS (admin) ────────────────────────────
} elseif ($action === 'all_orders') {
    $admin_id = $_GET['admin_id'] ?? $input['admin_id'] ?? 0;
    if (!verifyShopAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $stmt = $pdo->query("
        SELECT o.id, o.total_amount, o.status, o.created_at,
               m.full_name AS member_name, m.username,
               SUM(oi.quantity) AS total_items
        FROM orders o
        JOIN members     m  ON o.member_id  = m.id
        JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    echo json_encode($stmt->fetchAll());

// ── PENDING ORDERS (admin) ────────────────────────
} elseif ($action === 'pending_orders') {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!verifyShopAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $filter = $_GET['filter'] ?? 'pending';
    if ($filter === 'all') {
        $stmt = $pdo->query("
            SELECT o.id, o.total_amount, o.status, o.created_at,
                   m.full_name AS member_name, m.username, m.membership_type,
                   GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS items_summary,
                   SUM(oi.quantity) AS total_items
            FROM orders o
            JOIN members     m  ON o.member_id  = m.id
            JOIN order_items oi ON o.id         = oi.order_id
            JOIN products    p  ON oi.product_id= p.id
            GROUP BY o.id
            ORDER BY FIELD(o.status,'pending','confirmed','cancelled'), o.created_at DESC
            LIMIT 100
        ");
    } else {
        $stmt = $pdo->query("
            SELECT o.id, o.total_amount, o.status, o.created_at,
                   m.full_name AS member_name, m.username, m.membership_type,
                   GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS items_summary,
                   SUM(oi.quantity) AS total_items
            FROM orders o
            JOIN members     m  ON o.member_id  = m.id
            JOIN order_items oi ON o.id         = oi.order_id
            JOIN products    p  ON oi.product_id= p.id
            WHERE o.status = 'pending'
            GROUP BY o.id
            ORDER BY o.created_at ASC
        ");
    }
    echo json_encode($stmt->fetchAll());

// ── APPROVE ORDER (admin) ─────────────────────────
} elseif ($action === 'approve_order') {
    $admin_id = $input['admin_id'] ?? 0;
    $order_id = (int)($input['order_id'] ?? 0);
    if (!verifyShopAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $rq = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
    $rq->execute([$order_id]);
    $order = $rq->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found or already processed.']);
        exit;
    }
    $items_stmt = $pdo->prepare("SELECT oi.product_id, oi.quantity FROM order_items oi WHERE oi.order_id = ?");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll();

    try {
        $pdo->beginTransaction();

        $stockCheck = $pdo->prepare("SELECT id, name, stock FROM products WHERE id = ? FOR UPDATE");
        foreach ($items as $item) {
            $stockCheck->execute([$item['product_id']]);
            $product = $stockCheck->fetch();
            if (!$product || $product['stock'] < $item['quantity']) {
                $pdo->rollBack();
                $available = $product ? $product['stock'] : 0;
                $name      = $product ? $product['name']  : 'Unknown product';
                http_response_code(409);
                echo json_encode(['error' => "Cannot approve: not enough stock for \"{$name}\". Only {$available} unit(s) remaining."]);
                exit;
            }
        }

        $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = ?")->execute([$order_id]);
        $deduct = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        foreach ($items as $item) {
            $deduct->execute([$item['quantity'], $item['product_id']]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $order_id]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Could not approve order. Please try again.']);
    }

// ── REJECT ORDER (admin) ──────────────────────────
} elseif ($action === 'reject_order') {
    $admin_id = $input['admin_id'] ?? 0;
    $order_id = (int)($input['order_id'] ?? 0);
    if (!verifyShopAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
    $stmt->execute([$order_id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found or already processed.']);
        exit;
    }
    echo json_encode(['success' => true]);

// ── CART ANALYTICS (admin) ────────────────────────
// Returns per-member pending cart totals: item count, product breakdown, total value.
// "Cart" = all orders with status = 'pending' (placed but not yet approved).
} elseif ($action === 'cart_analytics') {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!verifyShopAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    // One row per member who has at least one pending order.
    // items_detail: "Product A x2, Product B x1" style summary.
    $stmt = $pdo->query("
        SELECT
            m.id            AS member_id,
            m.full_name     AS member_name,
            m.username,
            m.membership_type,
            COUNT(DISTINCT o.id)                            AS pending_orders,
            SUM(oi.quantity)                                AS total_items,
            SUM(oi.quantity * oi.price)                     AS cart_value,
            GROUP_CONCAT(
                CONCAT(p.name, ' x', oi.quantity)
                ORDER BY p.name SEPARATOR ', '
            )                                               AS items_detail
        FROM orders o
        JOIN members     m  ON o.member_id    = m.id
        JOIN order_items oi ON o.id           = oi.order_id
        JOIN products    p  ON oi.product_id  = p.id
        WHERE o.status = 'pending'
          AND m.is_admin = 0
        GROUP BY m.id
        ORDER BY cart_value DESC
    ");
    echo json_encode($stmt->fetchAll());

// ── PURCHASE ANALYTICS (admin) ────────────────────
// Returns per-member confirmed spending: total spent, order count, items bought,
// most recent purchase date, and a product-level breakdown.
} elseif ($action === 'purchase_analytics') {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!verifyShopAdmin($pdo, $admin_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $stmt = $pdo->query("
        SELECT
            m.id            AS member_id,
            m.full_name     AS member_name,
            m.username,
            m.membership_type,
            COUNT(DISTINCT o.id)                            AS total_orders,
            SUM(oi.quantity)                                AS total_items_bought,
            SUM(oi.quantity * oi.price)                     AS total_spent,
            MAX(o.created_at)                               AS last_purchase,
            MIN(o.created_at)                               AS first_purchase,
            GROUP_CONCAT(
                CONCAT(p.name, ' x', oi.quantity, ' (₱', FORMAT(oi.price,2), ')')
                ORDER BY o.created_at DESC SEPARATOR ' | '
            )                                               AS purchase_detail
        FROM orders o
        JOIN members     m  ON o.member_id    = m.id
        JOIN order_items oi ON o.id           = oi.order_id
        JOIN products    p  ON oi.product_id  = p.id
        WHERE o.status = 'confirmed'
          AND m.is_admin = 0
        GROUP BY m.id
        ORDER BY total_spent DESC
    ");
    echo json_encode($stmt->fetchAll());
}
?>