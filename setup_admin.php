<?php
/**
 * প্রথমবার cPanel এ deploy করার পর একবার ব্রাউজারে চালান:
 *   https://yourdomain.com/RoomManager/setup_admin.php?user_code=AH-000&name=Admin&phone=017XXXXXXXX&whatsapp=8801XXXXXXXXX&password=YourStrongPass
 *
 * কাজ শেষে এই ফাইলটি সার্ভার থেকে ডিলিট করে দিন — নিরাপত্তার জন্য জরুরি।
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();

$userCode = $_GET['user_code'] ?? 'AH-000';
$name = $_GET['name'] ?? 'Admin';
$phone = $_GET['phone'] ?? '';
$whatsapp = $_GET['whatsapp'] ?? $phone;
$password = $_GET['password'] ?? '';

if (!$password || strlen($password) < 6) {
    die('URL এ ?password=... প্যারামিটার দিন (কমপক্ষে ৬ অক্ষর)। উদাহরণ: setup_admin.php?password=MyPass123&phone=017...&whatsapp=8801...');
}

$hash = password_hash($password, PASSWORD_DEFAULT);

// আগে থেকে এই user_code থাকলে পাসওয়ার্ড আপডেট করে দেবে, না থাকলে নতুন তৈরি করবে
$stmt = $db->prepare('SELECT id FROM users WHERE user_code = ?');
$stmt->execute([$userCode]);

if ($stmt->fetch()) {
    $upd = $db->prepare('UPDATE users SET password_hash = ?, name = ?, phone = ?, whatsapp_number = ?, role = "admin", status = "active" WHERE user_code = ?');
    $upd->execute([$hash, $name, $phone, $whatsapp, $userCode]);
    echo "এডমিন আপডেট হয়েছে। ইউজার আইডি: {$userCode}। এখন লগইন করুন এবং এই ফাইলটি ডিলিট করে দিন।";
} else {
    $ins = $db->prepare(
        'INSERT INTO users (user_code, name, phone, whatsapp_number, role, password_hash, join_date, status)
         VALUES (?, ?, ?, ?, "admin", ?, CURDATE(), "active")'
    );
    $ins->execute([$userCode, $name, $phone, $whatsapp, $hash]);
    echo "নতুন এডমিন তৈরি হয়েছে। ইউজার আইডি: {$userCode}। এখন লগইন করুন এবং এই ফাইলটি ডিলিট করে দিন।";
}
