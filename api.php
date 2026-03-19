<?php
// ============================================================
// Presh Pets — API Backend (MySQL / phpMyAdmin)
// Place this file in the same folder as your HTML files
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── DATABASE CONFIG ─────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // your phpMyAdmin username
define('DB_PASS', '');            // your phpMyAdmin password
define('DB_NAME', 'preshpets');   // your database name

function db() {
    static $conn = null;
    if (!$conn) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            http_response_code(500);
            echo json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]);
            exit;
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function ok($data = [])  { echo json_encode(array_merge(['ok' => true], $data)); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $body['action'] ?? '';

// ── ROUTING ─────────────────────────────────────────────────
switch ($action) {

    // ── AUTH ────────────────────────────────────────────────
    case 'signup':
        $name  = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $phone = trim($body['phone'] ?? '');
        $pass  = $body['password'] ?? '';
        if (!$name || !$email || !$pass) fail('Please fill all required fields.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
        if (strlen($pass) < 6) fail('Password must be at least 6 characters.');
        $db = db();
        $st = $db->prepare('SELECT id FROM users WHERE email=?');
        $st->bind_param('s', $email); $st->execute();
        if ($st->get_result()->num_rows > 0) fail('Email already registered.');
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $id   = uniqid('U', true);
        $st2  = $db->prepare('INSERT INTO users (id,name,email,phone,address,password,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $addr = '';
        $st2->bind_param('ssssss', $id, $name, $email, $phone, $addr, $hash);
        $st2->execute();
        ok(['user' => ['id'=>$id,'name'=>$name,'email'=>$email,'phone'=>$phone,'address'=>'']]);

    case 'login':
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['password'] ?? '';
        if (!$email || !$pass) fail('Please enter email and password.');
        $db = db();
        $st = $db->prepare('SELECT * FROM users WHERE email=?');
        $st->bind_param('s', $email); $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row || !password_verify($pass, $row['password'])) fail('Incorrect email or password.');
        unset($row['password']);
        ok(['user' => $row]);

    case 'get_profile':
        $uid = trim($body['user_id'] ?? '');
        if (!$uid) fail('Missing user_id.');
        $db = db();
        $st = $db->prepare('SELECT id,name,email,phone,address FROM users WHERE id=?');
        $st->bind_param('s', $uid); $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) fail('User not found.', 404);
        ok(['profile' => $row]);

    case 'save_profile':
        $uid   = trim($body['user_id'] ?? '');
        $name  = trim($body['name'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $addr  = trim($body['address'] ?? '');
        if (!$uid) fail('Missing user_id.');
        $db = db();
        $st = $db->prepare('UPDATE users SET name=?,phone=?,address=? WHERE id=?');
        $st->bind_param('ssss', $name, $phone, $addr, $uid);
        $st->execute();
        ok(['profile' => ['name'=>$name,'phone'=>$phone,'address'=>$addr]]);

    // ── ORDERS ──────────────────────────────────────────────
    case 'place_order':
        $uid     = trim($body['user_id'] ?? '');
        $name    = trim($body['customer_name'] ?? '');
        $phone   = trim($body['phone'] ?? '');
        $addr    = trim($body['address'] ?? '');
        $items   = $body['items'] ?? [];
        $total   = floatval($body['total'] ?? 0);
        $fee     = floatval($body['delivery_fee'] ?? 0);
        $fulfil  = $body['fulfillment'] ?? 'delivery';
        $ref     = trim($body['paystack_ref'] ?? '');
        $status  = $fulfil === 'pickup' ? 'Preparing for Pickup' : 'Order Received';
        $history = json_encode([['status'=>$status,'time'=>date('d/m/Y, H:i')]]);
        $oid     = 'ORD-' . time() . rand(100,999);
        $db = db();
        $itemsJson = json_encode($items);
        $st = $db->prepare('INSERT INTO orders (id,user_id,customer_name,phone,address,items,total,delivery_fee,fulfillment,status,status_history,paystack_ref,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $st->bind_param('ssssssddssss', $oid,$uid,$name,$phone,$addr,$itemsJson,$total,$fee,$fulfil,$status,$history,$ref);
        $st->execute();
        // save address to user profile if blank
        if ($uid && $fulfil === 'delivery' && $addr) {
            $db->prepare('UPDATE users SET address=IF(address="",?,address), phone=IF(phone="",?,phone) WHERE id=?')
               ->bind_param('sss', $addr, $phone, $uid) || null;
            $st2 = $db->prepare('UPDATE users SET address=IF(address="",?,address), phone=IF(phone="",?,phone) WHERE id=?');
            $st2->bind_param('sss', $addr, $phone, $uid);
            $st2->execute();
        }
        ok(['order_id' => $oid]);

    case 'get_orders':
        $uid = trim($body['user_id'] ?? '');
        if (!$uid) fail('Missing user_id.');
        $db = db();
        $st = $db->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC');
        $st->bind_param('s', $uid); $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['items']          = json_decode($r['items'], true) ?? [];
            $r['status_history'] = json_decode($r['status_history'], true) ?? [];
        }
        ok(['orders' => $rows]);

    case 'get_order':
        $oid = trim($body['order_id'] ?? '');
        if (!$oid) fail('Missing order_id.');
        $db = db();
        $st = $db->prepare('SELECT * FROM orders WHERE id=?');
        $st->bind_param('s', $oid); $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) fail('Order not found.', 404);
        $row['items']          = json_decode($row['items'], true) ?? [];
        $row['status_history'] = json_decode($row['status_history'], true) ?? [];
        ok(['order' => $row]);

    // ── ADMIN ───────────────────────────────────────────────
    case 'admin_get_orders':
        $db   = db();
        $rows = $db->query('SELECT * FROM orders ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['items']          = json_decode($r['items'], true) ?? [];
            $r['status_history'] = json_decode($r['status_history'], true) ?? [];
        }
        ok(['orders' => $rows]);

    case 'admin_update_status':
        $oid     = trim($body['order_id'] ?? '');
        $status  = trim($body['status'] ?? '');
        $history = $body['status_history'] ?? [];
        if (!$oid || !$status) fail('Missing order_id or status.');
        $db = db();
        $hJson = json_encode($history);
        $st = $db->prepare('UPDATE orders SET status=?, status_history=? WHERE id=?');
        $st->bind_param('sss', $status, $hJson, $oid);
        $st->execute();
        ok();

    case 'admin_get_customers':
        $db   = db();
        $rows = $db->query('SELECT u.id, u.name, u.email, u.phone, u.created_at, COUNT(o.id) as orders, COALESCE(SUM(o.total),0) as spent FROM users u LEFT JOIN orders o ON o.user_id=u.id GROUP BY u.id ORDER BY u.created_at DESC')->fetch_all(MYSQLI_ASSOC);
        ok(['customers' => $rows]);

    // ── PRODUCTS ────────────────────────────────────────────
    case 'get_products':
        $db   = db();
        $rows = $db->query('SELECT * FROM products ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);
        ok(['products' => $rows]);

    case 'add_product':
        $name  = trim($body['name'] ?? '');
        $cat   = trim($body['category'] ?? 'Accessories');
        $price = floatval($body['price'] ?? 0);
        $stock = intval($body['stock'] ?? 0);
        $desc  = trim($body['description'] ?? '');
        $img   = $body['image'] ?? '';
        if (!$name) fail('Product name is required.');
        $db = db();
        $st = $db->prepare('INSERT INTO products (name,category,price,stock,description,image) VALUES (?,?,?,?,?,?)');
        $st->bind_param('ssdiss', $name, $cat, $price, $stock, $desc, $img);
        $st->execute();
        ok(['id' => $db->insert_id]);

    case 'update_stock':
        $id    = intval($body['id'] ?? 0);
        $stock = intval($body['stock'] ?? 0);
        if (!$id) fail('Missing product id.');
        $db = db();
        $st = $db->prepare('UPDATE products SET stock=? WHERE id=?');
        $st->bind_param('ii', $stock, $id);
        $st->execute();
        ok();

    case 'delete_product':
        $id = intval($body['id'] ?? 0);
        if (!$id) fail('Missing product id.');
        $db = db();
        $st = $db->prepare('DELETE FROM products WHERE id=?');
        $st->bind_param('i', $id);
        $st->execute();
        ok();

    default:
        fail('Unknown action: ' . $action, 404);
}
?>