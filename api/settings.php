<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

// ---- সরাসরি টেস্ট মেসেজ পাঠিয়ে Meta এর আসল রেসপন্স/এরর দেখা (ডিবাগিং এর জন্য, শুধু এডমিন) ----
if ($method === 'POST' && $action === 'test-whatsapp') {
    $admin = Auth::requireAdmin();
    require_once __DIR__ . '/../includes/WhatsApp.php';
    $phone = trim($input['phone'] ?? '');
    if (!$phone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ফোন নম্বর দিন (যেমন 01712345678)']);
        exit();
    }
    $wa = new WhatsApp();
    if (!$wa->isConfigured()) {
        echo json_encode(['success' => false, 'message' => 'Phone Number ID বা Access Token সেট করা নেই — উপরে সংরক্ষণ করেছেন কিনা যাচাই করুন']);
        exit();
    }
    $testBody = 'এটি Room Manager থেকে একটি টেস্ট মেসেজ। এই মেসেজ পেলে বুঝবেন WhatsApp API ঠিকমতো কাজ করছে।';
    // টেমপ্লেট দিয়ে পাঠানো হচ্ছে (custom_message) — যাতে ২৪ ঘণ্টা উইন্ডোর বাইরেও পৌঁছায়,
    // ঠিক যেভাবে সিস্টেমের আসল নোটিফিকেশনগুলো পাঠানো হয়।
    $result = $wa->sendTemplate($phone, 'custom_message', ['টেস্ট', $testBody]);

    // ওয়েবহুক পরবর্তীতে ডেলিভারি স্ট্যাটাস (পৌঁছেছে/পড়েছে/ব্যর্থ) আপডেট করতে পারে যাতে,
    // তার জন্য এটাও notification_logs এ লগ করা হচ্ছে (এডমিনের নিজের একাউন্টে) — নতুন করে
    // আবার মেসেজ না পাঠিয়ে, উপরের $result টাই সরাসরি লগ করা হচ্ছে
    $stmt = $db->prepare(
        'INSERT INTO notification_logs (user_id, category, message, whatsapp_message_id, status, error_message, sent_at)
         VALUES (?, "custom", ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $admin['id'],
        "[টেস্ট → {$phone}] প্রিয় টেস্ট,\n{$testBody}",
        $result['message_id'] ?? null,
        $result['success'] ? 'sent' : 'failed',
        WhatsApp::friendlyError($result['error'] ?? null),
        $result['success'] ? date('Y-m-d H:i:s') : null,
    ]);

    echo json_encode([
        'success' => $result['success'],
        'message' => $result['success']
            ? 'Meta মেসেজটা গ্রহণ করেছে (message id: ' . ($result['message_id'] ?? '') . ')। কিন্তু ফোনে না পৌঁছালে নিচের নোট পড়ুন — "নোটিফিকেশন" ট্যাবে কিছুক্ষণ পর রিফ্রেশ করে দেখুন ডেলিভারি স্ট্যাটাস কী দেখায়।'
            : 'ব্যর্থ: ' . (WhatsApp::friendlyError($result['error'] ?? null) ?? 'অজানা এরর'),
        'data' => ['raw' => $result['raw'] ?? null],
    ]);
    exit();
}

// ---- সার্ভারের PHP ক্যাশ (OPcache) ক্লিয়ার করা (শুধু এডমিন) — কোড আপডেটের পরও পুরনো
//      কোড সার্ভারে "আটকে" থাকলে এটা কাজে লাগবে ----
if ($method === 'POST' && $action === 'clear-cache') {
    Auth::requireAdmin();
    $cleared = [];
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $cleared[] = 'PHP OPcache';
    }
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
        $cleared[] = 'APCu';
    }
    clearstatcache();
    echo json_encode([
        'success' => true,
        'message' => $cleared ? ('সার্ভার ক্যাশ ক্লিয়ার হয়েছে (' . implode(', ', $cleared) . ')') : 'এই সার্ভারে ক্লিয়ার করার মতো কোনো PHP ক্যাশ সক্রিয় নেই',
    ]);
    exit();
}

// ---- সব ইউজারের জন্য নিরাপদ, সীমিত সেটিংস (মাস/সাল ড্রপডাউনের সীমা ঠিক করতে গ্রাহকেরও এটা দরকার) ----
if ($method === 'GET' && $action === 'public') {
    Auth::requireAuth();
    $keys = ['system_start_year', 'system_start_month', 'timezone'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    $settings = [];
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
    echo json_encode(['success' => true, 'data' => $settings]);
    exit();
}

// ---- সব সেটিংস দেখা (এডমিন) ----
if ($method === 'GET') {
    Auth::requireAdmin();
    $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $r) {
        // নিরাপত্তার জন্য টোকেন সম্পূর্ণ না দেখিয়ে মাস্ক করে দেখানো
        if (in_array($r['setting_key'], ['whatsapp_access_token']) && $r['setting_value']) {
            $settings[$r['setting_key']] = substr($r['setting_value'], 0, 6) . '••••••••';
        } else {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    }
    echo json_encode(['success' => true, 'data' => $settings]);
    exit();
}

// ---- সেটিংস আপডেট (এডমিন) - এখান থেকেই পুরো WhatsApp API ও টাইমজোন কনফিগার হয় ----
if ($method === 'POST' || $method === 'PUT') {
    Auth::requireAdmin();

    if (isset($input['timezone']) && !in_array($input['timezone'], timezone_identifiers_list(), true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'অবৈধ টাইমজোন']);
        exit();
    }

    foreach ($input as $key => $value) {
        Database::setSetting($key, (string)$value);
    }
    echo json_encode(['success' => true, 'message' => 'সেটিংস সংরক্ষণ করা হয়েছে']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
