<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

$validTypes = ['market', 'cooking', 'trash', 'cleaning'];

/** নির্দিষ্ট ডিউটি টাইপে গ্রাহকের অনুমতি আছে কিনা */
function userHasDutyPermission(PDO $db, int $userId, string $dutyType): bool
{
    $stmt = $db->prepare('SELECT permissions FROM users WHERE id = ? AND role = "customer"');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $perms = $row['permissions'] ? (json_decode($row['permissions'], true) ?: []) : [];
    return in_array($dutyType, $perms, true);
}

// ---- নির্দিষ্ট তারিখের সব ডিউটি দেখা (সবাই দেখতে পারবে) ----
if ($method === 'GET' && $action === 'by-date') {
    Auth::requireAuth();
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = $db->prepare(
        'SELECT d.*, u.name, u.user_code, u.room_no
         FROM duties d JOIN users u ON u.id = d.assigned_user_id
         WHERE d.duty_date = ? ORDER BY d.duty_type ASC'
    );
    $stmt->execute([$date]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- একজন ইউজারের ডিউটি তালিকা (নিজেরটা বা এডমিন/ম্যানেজার যেকোনো একজনেরটা) ----
if ($method === 'GET' && $action === 'by-user') {
    $user = Auth::requireAuth();
    $targetId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $user['id'];
    $isStaff = in_array($user['role'], ['admin', 'manager'], true);
    if (!$isStaff && $targetId !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'অনুমতি নেই']);
        exit();
    }
    $params = [$targetId];
    $typeFilter = '';
    if (!empty($_GET['duty_type']) && in_array($_GET['duty_type'], $validTypes, true)) {
        $typeFilter = ' AND duty_type = ?';
        $params[] = $_GET['duty_type'];
    }
    $stmt = $db->prepare("SELECT * FROM duties WHERE assigned_user_id = ?{$typeFilter} ORDER BY duty_date DESC");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- একটা নির্দিষ্ট ডিউটি টাইপের একটা মাসের পুরো শিডিউল (এডমিন/ম্যানেজারের শিডিউলার UI ও গ্রাহকের রোস্টার দেখার জন্য) ----
if ($method === 'GET' && $action === 'by-type-month') {
    $user = Auth::requireAuth();
    $type = $_GET['duty_type'] ?? '';
    $month = (int)($_GET['month'] ?? date('n'));
    $year = (int)($_GET['year'] ?? date('Y'));
    if (!in_array($type, $validTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'অবৈধ duty_type']);
        exit();
    }

    // গ্রাহক হলে যাচাই করা হয় তার এই ডিউটিতে অনুমতি (permission) আছে কিনা — না থাকলে দেখতে পারবে না
    if ($user['role'] === 'customer' && !in_array($type, $user['permissions'] ?? [], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'এই তালিকা দেখার অনুমতি নেই']);
        exit();
    }

    // এই ডিউটিতে অনুমতিপ্রাপ্ত গ্রাহকদের তালিকা (JSON_CONTAINS দিয়ে ফিল্টার)
    $stmt = $db->prepare(
        'SELECT id, user_code, name, room_no FROM users
         WHERE role = "customer" AND status = "active" AND JSON_CONTAINS(permissions, JSON_QUOTE(?))
         ORDER BY user_code ASC'
    );
    $stmt->execute([$type]);
    $eligibleUsers = $stmt->fetchAll();

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $db->prepare(
        'SELECT d.*, u.name, u.user_code FROM duties d
         JOIN users u ON u.id = d.assigned_user_id
         WHERE d.duty_type = ? AND d.duty_date BETWEEN ? AND ?
         ORDER BY d.duty_date ASC'
    );
    $stmt->execute([$type, $start, $end]);
    $assignments = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => ['eligible_users' => $eligibleUsers, 'assignments' => $assignments]]);
    exit();
}

// ---- এক বা একাধিক ডিউটি অ্যাসাইন করা (এডমিন/ম্যানেজার) - single অথবা bulk array দুটোই সাপোর্ট করে ----
if ($method === 'POST' && $action === 'assign') {
    $staff = Auth::requireAdminOrManager();

    $entries = isset($input['entries']) ? $input['entries'] : [$input];
    $created = [];
    $skipped = 0;

    $stmt = $db->prepare(
        'INSERT INTO duties (duty_type, assigned_user_id, duty_date, note, created_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE assigned_user_id = VALUES(assigned_user_id), note = VALUES(note), status = "pending"'
    );

    foreach ($entries as $entry) {
        if (!in_array($entry['duty_type'] ?? '', $validTypes, true)) { $skipped++; continue; }
        if (empty($entry['assigned_user_id']) || empty($entry['duty_date'])) { $skipped++; continue; }
        if (!userHasDutyPermission($db, (int)$entry['assigned_user_id'], $entry['duty_type'])) { $skipped++; continue; }

        $stmt->execute([
            $entry['duty_type'],
            $entry['assigned_user_id'],
            $entry['duty_date'],
            $entry['note'] ?? null,
            $staff['id'],
        ]);
        $created[] = $db->lastInsertId();
    }

    $msg = count($created) . 'টি ডিউটি সেট করা হয়েছে';
    if ($skipped) $msg .= " ({$skipped}টি বাদ দেওয়া হয়েছে — অনুমতি নেই বা তথ্য অসম্পূর্ণ)";
    echo json_encode(['success' => true, 'message' => $msg, 'data' => $created]);
    exit();
}

// ---- একটা পুরো মাসের শিডিউল একসাথে সংরক্ষণ (এডমিন/ম্যানেজার) ----
// body: { duty_type, month, year, assignments: { "1": user_id|null, "2": user_id|null, ... } } (কী = তারিখ)
if ($method === 'POST' && $action === 'assign-month') {
    $staff = Auth::requireAdminOrManager();

    $type = $input['duty_type'] ?? '';
    $month = (int)($input['month'] ?? 0);
    $year = (int)($input['year'] ?? 0);
    $assignments = $input['assignments'] ?? [];

    if (!in_array($type, $validTypes, true) || !$month || !$year) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'duty_type, month, year আবশ্যক']);
        exit();
    }

    $upsert = $db->prepare(
        'INSERT INTO duties (duty_type, assigned_user_id, duty_date, created_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE assigned_user_id = VALUES(assigned_user_id), status = "pending"'
    );
    $delete = $db->prepare('DELETE FROM duties WHERE duty_type = ? AND duty_date = ?');

    $changed = 0;
    $skipped = 0;
    foreach ($assignments as $day => $userId) {
        $day = (int)$day;
        if ($day < 1 || $day > 31) continue;
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if (!checkdate($month, $day, $year)) continue;

        if (empty($userId)) {
            $delete->execute([$type, $dateStr]);
            if ($delete->rowCount() > 0) $changed++;
            continue;
        }
        if (!userHasDutyPermission($db, (int)$userId, $type)) { $skipped++; continue; }

        $upsert->execute([$type, $userId, $dateStr, $staff['id']]);
        $changed++;
    }

    $msg = "মাসিক শিডিউল সংরক্ষণ হয়েছে ({$changed}টি পরিবর্তন)";
    if ($skipped) $msg .= " — {$skipped}টি বাদ দেওয়া হয়েছে (অনুমতি নেই)";
    echo json_encode(['success' => true, 'message' => $msg]);
    exit();
}

// ---- ডিউটির স্ট্যাটাস পরিবর্তন (done/missed) — গ্রাহক নিজেরটা "সম্পন্ন হয়েছে" জানাতে পারবে ----
if ($method === 'PUT' && $action === 'status') {
    $user = Auth::requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    $status = $input['status'] ?? '';

    if (!in_array($status, ['pending', 'done', 'missed'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'অবৈধ স্ট্যাটাস']);
        exit();
    }

    $check = $db->prepare('SELECT assigned_user_id, duty_type, amount FROM duties WHERE id = ?');
    $check->execute([$id]);
    $duty = $check->fetch();
    if (!$duty) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ডিউটি পাওয়া যায়নি']);
        exit();
    }

    $isStaff = in_array($user['role'], ['admin', 'manager'], true);
    $isOwner = (int)$duty['assigned_user_id'] === (int)$user['id'];
    if (!$isStaff && !$isOwner) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'অনুমতি নেই']);
        exit();
    }

    // ---- বাজার (market) ডিউটির খরচের টাকা ----
    // এডমিন/ম্যানেজার সবসময় বদলাতে পারবে। গ্রাহক শুধু একবারই বসাতে পারবে (আগে থেকে সেট না থাকলে) —
    // একবার বসানোর পর গ্রাহক আবার চেষ্টা করলে চুপচাপ পুরনো মানই থেকে যাবে (এরর দেখানো হয় না, যাতে
    // স্ট্যাটাস আপডেট আটকে না যায়)।
    $newAmount = $duty['amount'];
    if ($duty['duty_type'] === 'market' && array_key_exists('amount', $input) && $input['amount'] !== null && $input['amount'] !== '') {
        if ($isStaff) {
            $newAmount = (float)$input['amount'];
        } elseif ($isOwner && $duty['amount'] === null) {
            $newAmount = (float)$input['amount'];
        }
    }

    $stmt = $db->prepare('UPDATE duties SET status = ?, amount = ? WHERE id = ?');
    $stmt->execute([$status, $newAmount, $id]);
    echo json_encode(['success' => true, 'message' => $status === 'done' ? 'দায়িত্ব সম্পন্ন হিসেবে চিহ্নিত হলো' : 'স্ট্যাটাস আপডেট হয়েছে', 'data' => ['amount' => $newAmount !== null ? (float)$newAmount : null]]);
    exit();
}

// ---- বাজারের মাসিক খরচের সারাংশ (কে কবে কত খরচ করেছে + মোট + ব্যক্তিভিত্তিক মোট) —
//      এডমিন/ম্যানেজার সবার দেখে, গ্রাহক শুধু market পারমিশন থাকলে দেখতে পারবে ----
if ($method === 'GET' && $action === 'market-summary') {
    $user = Auth::requireAuth();
    if ($user['role'] === 'customer' && !in_array('market', $user['permissions'] ?? [], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'এই তথ্য দেখার অনুমতি নেই']);
        exit();
    }
    $month = (int)($_GET['month'] ?? date('n'));
    $year = (int)($_GET['year'] ?? date('Y'));
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $stmt = $db->prepare(
        'SELECT d.id, d.duty_date, d.assigned_user_id, d.amount, d.status, u.name, u.user_code
         FROM duties d JOIN users u ON u.id = d.assigned_user_id
         WHERE d.duty_type = "market" AND d.duty_date BETWEEN ? AND ? AND d.amount IS NOT NULL
         ORDER BY d.duty_date ASC'
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $total = 0;
    $byUser = []; // user_id => ['name'=>, 'user_code'=>, 'total'=>]
    foreach ($rows as $r) {
        $total += (float)$r['amount'];
        $uid = $r['assigned_user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = ['user_id' => $uid, 'name' => $r['name'], 'user_code' => $r['user_code'], 'total' => 0];
        }
        $byUser[$uid]['total'] += (float)$r['amount'];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'entries' => $rows,
            'total' => $total,
            'by_user' => array_values($byUser),
        ],
    ]);
    exit();
}

// ---- ডিউটি মুছে ফেলা (এডমিন/ম্যানেজার) ----
if ($method === 'DELETE') {
    Auth::requireAdminOrManager();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM duties WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'ডিউটি মুছে ফেলা হয়েছে']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
