<?php
/**
 * মূল কনফিগারেশন ফাইল
 * cPanel এ deploy করার সময় এই তথ্যগুলো আপডেট করুন
 */

// ডাটাবেজ কনফিগারেশন
define('DB_HOST', 'localhost');
define('DB_NAME', 'room_manager');
define('DB_USER', 'your_cpanel_db_user');
define('DB_PASS', 'your_cpanel_db_password');

// অ্যাপ কনফিগারেশন
define('APP_URL', 'https://yourdomain.com');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');

// টোকেন মেয়াদ (সেকেন্ডে) - ৩০ দিন
define('TOKEN_EXPIRY', 30 * 24 * 60 * 60);

// ডিবাগ মোড: true থাকলে API রেসপন্সে আসল PHP এরর মেসেজ দেখাবে (সেটআপ/সমস্যা সমাধানের সময় সুবিধাজনক)।
// সাইট পুরোপুরি লাইভ ও স্ট্যাবল হয়ে গেলে নিরাপত্তার জন্য এটা false করে দিন।
define('DEBUG_MODE', true);

// টাইমজোন (ফলব্যাক — এডমিন প্যানেলের সেটিংস থেকে সেট করা টাইমজোন Database::applyTimezone() পরে এটা ওভাররাইড করে দেয়)
date_default_timezone_set('Asia/Dhaka');

// এরর রিপোর্টিং
error_reporting(E_ALL);
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

/**
 * যেকোনো অ্যানহ্যান্ডলড এক্সসেপশন বা ফেটাল এরর যেন সাদা পেজ/HTML এর বদলে
 * JSON আকারে ফেরত যায় — নাহলে ফ্রন্টএন্ডে শুধু "সার্ভার এরর" দেখায়, আসল কারণ বোঝা যায় না।
 */
function rm_send_error_json(string $message, ?array $debugInfo = null): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => DEBUG_MODE ? $message : 'সার্ভারে একটি সমস্যা হয়েছে, একটু পরে আবার চেষ্টা করুন',
        'debug' => DEBUG_MODE ? $debugInfo : null,
    ]);
    exit();
}

set_exception_handler(function (Throwable $e) {
    error_log('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    rm_send_error_json(
        'সার্ভার এরর: ' . $e->getMessage(),
        ['type' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]
    );
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        rm_send_error_json(
            'ফেটাল এরর: ' . $error['message'],
            ['file' => $error['file'], 'line' => $error['line']]
        );
    }
});

// CORS হেডার (যদি আলাদা ফ্রন্টএন্ড ডোমেইন থেকে কল হয়)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
