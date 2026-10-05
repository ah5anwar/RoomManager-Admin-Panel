<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/WhatsApp.php';

header('Content-Type: application/json; charset=utf-8');
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

// ---- কাস্টম মেসেজ পাঠানো: নির্বাচিত ইউজার অথবা সবাইকে (এডমিন ও ম্যানেজার) ----
if ($method === 'POST' && $action === 'send-custom') {
    Auth::requireAdminOrManager();

    $message = trim($input['message'] ?? '');
    $userIds = $input['user_ids'] ?? []; // খালি রাখলে সবাইকে যাবে

    if (!$message) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'মেসেজ লিখুন']);
        exit();
    }

    if (empty($userIds)) {
        $stmt = $db->query('SELECT id, name, whatsapp_number FROM users WHERE role = "customer" AND status = "active"');
        $recipients = $stmt->fetchAll();
    } else {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT id, name, whatsapp_number FROM users WHERE id IN ($placeholders) AND status = 'active'");
        $stmt->execute($userIds);
        $recipients = $stmt->fetchAll();
    }

    $sent = 0;
    $failed = 0;
    foreach ($recipients as $r) {
        $ok = WhatsApp::sendTemplateAndLog(
            $r['id'], $r['whatsapp_number'], 'custom_message',
            [$r['name'], $message], 'custom', $message
        );
        $ok ? $sent++ : $failed++;
    }

    // WhatsApp API কনফিগার করা না থাকলে বা সব মেসেজ ব্যর্থ হলে সেটা স্পষ্টভাবে জানানো হয়,
    // যাতে অ্যাপে ভুলভাবে "সফল" না দেখায়
    $allFailed = $sent === 0 && $failed > 0;
    echo json_encode([
        'success' => !$allFailed,
        'message' => "পাঠানো হয়েছে: {$sent} জনকে, ব্যর্থ: {$failed} জনের ক্ষেত্রে" . ($allFailed ? ' (WhatsApp API ঠিকমতো কনফিগার করা আছে কিনা সেটিংসে চেক করুন)' : ''),
    ]);
    exit();
}

// ---- বাজার খরচের মাসিক সারাংশ সব গ্রাহককে (যাদের market পারমিশন আছে) WhatsApp এ পাঠানো (এডমিন ও ম্যানেজার) ----
if ($method === 'POST' && $action === 'send-market-summary') {
    Auth::requireAdminOrManager();
    $month = (int)($input['month'] ?? date('n'));
    $year = (int)($input['year'] ?? date('Y'));
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $stmt = $db->prepare(
        'SELECT d.assigned_user_id, d.amount, u.name FROM duties d JOIN users u ON u.id = d.assigned_user_id
         WHERE d.duty_type = "market" AND d.duty_date BETWEEN ? AND ? AND d.amount IS NOT NULL'
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo json_encode(['success' => true, 'message' => 'এই মাসে কোনো বাজার খরচ যোগ হয়নি, মেসেজ পাঠানো হয়নি']);
        exit();
    }

    $total = 0;
    $byUser = [];
    foreach ($rows as $r) {
        $total += (float)$r['amount'];
        $uid = $r['assigned_user_id'];
        if (!isset($byUser[$uid])) $byUser[$uid] = ['name' => $r['name'], 'total' => 0];
        $byUser[$uid]['total'] += (float)$r['amount'];
    }
    $monthNames = ['', 'জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
    $monthYear = "{$monthNames[$month]} {$year}";
    $breakdownText = implode(', ', array_map(fn($u) => "{$u['name']}: {$u['total']} টাকা", $byUser));
    $displayMessage = "প্রিয় সবাই,\n{$monthYear} মাসের বাজার খরচের সারাংশ:\n{$breakdownText}\nসর্বমোট: {$total} টাকা।";

    // যাদের market পারমিশন আছে এমন সব সক্রিয় গ্রাহককে পাঠানো হয়
    $recStmt = $db->query('SELECT id, name, whatsapp_number, permissions FROM users WHERE role = "customer" AND status = "active"');
    $recipients = array_filter($recStmt->fetchAll(), function ($u) {
        $perms = $u['permissions'] ? (json_decode($u['permissions'], true) ?: []) : [];
        return in_array('market', $perms, true);
    });

    $sent = 0;
    $failed = 0;
    foreach ($recipients as $r) {
        $ok = WhatsApp::sendTemplateAndLog(
            $r['id'], $r['whatsapp_number'], 'market_summary',
            [$monthYear, $breakdownText, (string)$total], 'market_summary', $displayMessage
        );
        $ok ? $sent++ : $failed++;
    }

    $allFailed = $sent === 0 && $failed > 0;
    echo json_encode([
        'success' => !$allFailed,
        'message' => "পাঠানো হয়েছে: {$sent} জনকে, ব্যর্থ: {$failed} জনের ক্ষেত্রে" . ($allFailed ? ' (WhatsApp সেটিংস চেক করুন)' : ''),
    ]);
    exit();
}

// ---- বকেয়া ভাড়ার লিস্ট নির্দিষ্ট গ্রাহককে WhatsApp এ পাঠানো (গ্রাহক নিজেরটাও চাইতে পারবে) ----
if ($method === 'POST' && $action === 'send-due-list') {
    $requester = Auth::requireAuth();
    $userId = (int)($input['user_id'] ?? 0);

    // গ্রাহক হলে শুধু নিজের জন্যই এটা কল করতে পারবে, অন্য কারো জন্য না
    if ($requester['role'] === 'customer') {
        $userId = (int)$requester['id'];
    } elseif (!in_array($requester['role'], ['admin', 'manager'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'অনুমতি নেই']);
        exit();
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ইউজার পাওয়া যায়নি']);
        exit();
    }

    $stmt = $db->prepare(
        'SELECT month, year, (amount_due - amount_paid) as due FROM rent_payments
         WHERE user_id = ? AND status IN ("due","partial") ORDER BY year ASC, month ASC'
    );
    $stmt->execute([$userId]);
    $dues = $stmt->fetchAll();

    if (empty($dues)) {
        echo json_encode(['success' => true, 'message' => 'কোনো বকেয়া নেই, মেসেজ পাঠানো হয়নি']);
        exit();
    }

    $monthNames = ['', 'জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
    $lines = "প্রিয় {$customer['name']}, আপনার বকেয়া ভাড়ার তালিকা:\n";
    $listItems = [];
    $total = 0;
    foreach ($dues as $d) {
        $lines .= "- {$monthNames[$d['month']]} {$d['year']}: {$d['due']} টাকা\n";
        $listItems[] = "{$monthNames[$d['month']]} {$d['year']}: {$d['due']} টাকা";
        $total += $d['due'];
    }
    $lines .= "মোট বকেয়া: {$total} টাকা";
    $listText = implode(', ', $listItems) . "। সর্বমোট: {$total} টাকা।";

    $ok = WhatsApp::sendTemplateAndLog(
        $customer['id'], $customer['whatsapp_number'], 'rent_overdue_list',
        [$customer['name'], $listText], 'rent_overdue_list', $lines
    );
    echo json_encode(['success' => $ok, 'message' => $ok ? 'বকেয়ার তালিকা পাঠানো হয়েছে' : 'মেসেজ পাঠাতে ব্যর্থ হয়েছে']);
    exit();
}

// ---- নোটিফিকেশন হিস্ট্রি দেখা (এডমিন সবার দেখে, গ্রাহক/ম্যানেজার শুধু নিজেরটা — অ্যাপের ভেতরের নোটিফিকেশনের জন্য) ----
if ($method === 'GET' && $action === 'logs') {
    $requester = Auth::requireAuth();
    $limit = min((int)($_GET['limit'] ?? 50), 200);

    if ($requester['role'] === 'admin') {
        $stmt = $db->prepare(
            'SELECT n.*, u.name, u.user_code FROM notification_logs n
             JOIN users u ON u.id = n.user_id ORDER BY n.created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            'SELECT n.*, u.name, u.user_code FROM notification_logs n
             JOIN users u ON u.id = n.user_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, (int)$requester['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
    }
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- নোটিফিকেশন হিস্ট্রি সম্পূর্ণ মুছে ফেলা (শুধু এডমিন) ----
if ($method === 'DELETE' && $action === 'clear-history') {
    Auth::requireAdmin();
    $db->exec('DELETE FROM notification_logs');
    echo json_encode(['success' => true, 'message' => 'নোটিফিকেশন হিস্ট্রি সম্পূর্ণ মুছে ফেলা হয়েছে']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
