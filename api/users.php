<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

/** DB থেকে আসা permissions JSON স্ট্রিংকে অ্যারেতে রূপান্তর করে */
function decodePermissions(array $row): array
{
    $row['permissions'] = $row['permissions'] ? (json_decode($row['permissions'], true) ?: []) : [];
    return $row;
}

// ---- তালিকা দেখা (এডমিন/ম্যানেজার সবাইকে দেখে, গ্রাহক শুধু নিজেকে দেখে) ----
if ($method === 'GET' && !isset($_GET['id'])) {
    $user = Auth::requireAuth();
    if (in_array($user['role'], ['admin', 'manager'], true)) {
        $stmt = $db->query('SELECT id, user_code, name, phone, whatsapp_number, email, room_no, role, permissions, monthly_rent, join_date, status FROM users ORDER BY id ASC');
        $users = array_map('decodePermissions', $stmt->fetchAll());
    } else {
        $users = [$user];
    }
    echo json_encode(['success' => true, 'data' => $users]);
    exit();
}

// ---- নির্দিষ্ট ইউজার দেখা ----
if ($method === 'GET' && isset($_GET['id'])) {
    $user = Auth::requireAuth();
    $id = (int)$_GET['id'];
    if (!in_array($user['role'], ['admin', 'manager'], true) && (int)$user['id'] !== $id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'অনুমতি নেই']);
        exit();
    }
    $stmt = $db->prepare('SELECT id, user_code, name, phone, whatsapp_number, email, room_no, role, permissions, monthly_rent, join_date, status FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    echo json_encode(['success' => (bool)$row, 'data' => $row ? decodePermissions($row) : null]);
    exit();
}

// ---- নতুন ইউজার তৈরি (এডমিন যেকোনো রোল তৈরি করতে পারবে; ম্যানেজার শুধু গ্রাহক তৈরি করতে পারবে) ----
if ($method === 'POST') {
    $requester = Auth::requireAdminOrManager();

    $required = ['name', 'phone', 'whatsapp_number', 'password'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "$field আবশ্যক"]);
            exit();
        }
    }

    $role = in_array($input['role'] ?? 'customer', ['customer', 'manager'], true) ? $input['role'] : 'customer';
    // ম্যানেজার শুধু গ্রাহক তৈরি করতে পারবে, আরেকজন ম্যানেজার তৈরি করতে পারবে না
    if ($requester['role'] === 'manager' && $role !== 'customer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'ম্যানেজার শুধু গ্রাহক তৈরি করতে পারবেন, নতুন ম্যানেজার তৈরির অনুমতি নেই']);
        exit();
    }
    $validPerms = ['market', 'cooking', 'trash', 'cleaning', 'chat'];
    $permissions = $role === 'customer'
        ? array_values(array_intersect($input['permissions'] ?? $validPerms, $validPerms))
        : [];

    $userCode = !empty($input['user_code']) ? $input['user_code'] : Auth::generateNextUserCode('AH');

    $stmt = $db->prepare(
        'INSERT INTO users (user_code, name, phone, whatsapp_number, email, room_no, role, permissions, password_hash, monthly_rent, join_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([
            $userCode,
            $input['name'],
            $input['phone'],
            $input['whatsapp_number'],
            $input['email'] ?? null,
            $role === 'manager' ? null : ($input['room_no'] ?? null),
            $role,
            json_encode($permissions),
            password_hash($input['password'], PASSWORD_DEFAULT),
            $role === 'manager' ? 0 : ($input['monthly_rent'] ?? 0),
            $input['join_date'] ?? date('Y-m-d'),
            $input['status'] ?? 'active',
        ]);
    } catch (PDOException $e) {
        http_response_code(400);
        $isDuplicate = (int)($e->errorInfo[1] ?? 0) === 1062; // MySQL duplicate-entry এরর কোড
        echo json_encode([
            'success' => false,
            'message' => $isDuplicate ? 'এই ইউজার আইডি আগে থেকেই আছে' : ('ডাটাবেজ এরর: ' . (DEBUG_MODE ? $e->getMessage() : 'তথ্য সংরক্ষণ করা যায়নি')),
        ]);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'ইউজার তৈরি হয়েছে', 'data' => ['id' => $db->lastInsertId(), 'user_code' => $userCode, 'role' => $role]]);
    exit();
}

// ---- ইউজার আপডেট (এডমিন যেকোনো ইউজার বদলাতে পারবে; ম্যানেজার শুধু গ্রাহকদের বদলাতে পারবে) ----
if ($method === 'PUT') {
    $requester = Auth::requireAdminOrManager();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id প্রয়োজন']);
        exit();
    }

    // ম্যানেজার শুধু গ্রাহকদের এডিট করতে পারবে — এডমিন বা অন্য ম্যানেজারের একাউন্টে হাত দিতে পারবে না
    if ($requester['role'] === 'manager') {
        $targetCheck = $db->prepare('SELECT role FROM users WHERE id = ?');
        $targetCheck->execute([$id]);
        $targetRow = $targetCheck->fetch();
        if (!$targetRow || $targetRow['role'] !== 'customer') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'ম্যানেজার শুধু গ্রাহকদের তথ্য পরিবর্তন করতে পারবেন']);
            exit();
        }
    }

    $fields = [];
    $values = [];
    $newRole = (isset($input['role']) && in_array($input['role'], ['customer', 'manager'], true)) ? $input['role'] : null;
    // ম্যানেজার কাউকে ম্যানেজারে রূপান্তর করতে পারবে না
    if ($requester['role'] === 'manager' && $newRole === 'manager') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'ম্যানেজার কাউকে ম্যানেজার বানাতে পারবেন না']);
        exit();
    }

    $allowed = ['name', 'phone', 'whatsapp_number', 'email', 'room_no', 'monthly_rent', 'status'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $input)) {
            $val = $input[$f];
            // ম্যানেজারের জন্য রুম/ভাড়া প্রযোজ্য না, বদলে দিলে খালি/শূন্য করে দেওয়া হয়
            if ($newRole === 'manager' && $f === 'room_no') $val = null;
            if ($newRole === 'manager' && $f === 'monthly_rent') $val = 0;
            $fields[] = "$f = ?";
            $values[] = $val;
        }
    }
    // রোল ম্যানেজারে বদলালে room_no/monthly_rent রিকোয়েস্টে না থাকলেও জোরপূর্বক খালি/শূন্য করে দেওয়া হয়
    if ($newRole === 'manager') {
        if (!array_key_exists('room_no', $input)) { $fields[] = 'room_no = ?'; $values[] = null; }
        if (!array_key_exists('monthly_rent', $input)) { $fields[] = 'monthly_rent = ?'; $values[] = 0; }
    }
    // ভূমিকা শুধু customer/manager এর মধ্যে বদলানো যাবে (এডমিন ভূমিকা এখান থেকে সেট করা যাবে না, নিরাপত্তার জন্য)
    if ($newRole !== null) {
        $fields[] = 'role = ?';
        $values[] = $newRole;
    }
    // পারমিশন শুধু গ্রাহকের জন্য প্রযোজ্য; ম্যানেজারে রূপান্তরিত হলে সবসময় খালি করে দেওয়া হয় (permissions ফিল্ড না পাঠালেও)
    if (array_key_exists('permissions', $input) || $newRole === 'manager') {
        $validPerms = ['market', 'cooking', 'trash', 'cleaning', 'chat'];
        $perms = $newRole === 'manager' ? [] : array_values(array_intersect((array)($input['permissions'] ?? []), $validPerms));
        $fields[] = 'permissions = ?';
        $values[] = json_encode($perms);
    }
    if (!empty($input['password'])) {
        $fields[] = 'password_hash = ?';
        $values[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }
    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => 'আপডেট করার কিছু নেই']);
        exit();
    }
    $values[] = $id;
    $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($values);
    echo json_encode(['success' => true, 'message' => 'ইউজার আপডেট হয়েছে']);
    exit();
}

// ---- ইউজার মুছে ফেলা (এডমিন যেকোনো ইউজার; ম্যানেজার শুধু গ্রাহক) ----
if ($method === 'DELETE') {
    $requester = Auth::requireAdminOrManager();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id প্রয়োজন']);
        exit();
    }
    if ($requester['role'] === 'manager') {
        $targetCheck = $db->prepare('SELECT role FROM users WHERE id = ?');
        $targetCheck->execute([$id]);
        $targetRow = $targetCheck->fetch();
        if (!$targetRow || $targetRow['role'] !== 'customer') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'ম্যানেজার শুধু গ্রাহকদের মুছে ফেলতে পারবেন']);
            exit();
        }
    }
    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'ইউজার মুছে ফেলা হয়েছে']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
