<?php
/**
 * এই স্ক্রিপ্টটি cPanel Cron Job এ প্রতি ৫ মিনিট পর পর চালাতে হবে:
 *   (মিনিট ফিল্ডে) 0,5,10,15,20,25,30,35,40,45,50,55  ->  php /home/cpaneluser/public_html/RoomManager/cron/send_daily_notifications.php
 *
 * এডমিন প্যানেলে যে ৩টা সময় (notification_time_1/2/3) সেট করা আছে,
 * বর্তমান সময় সেই সময়ের ৫ মিনিটের মধ্যে পড়লে নোটিফিকেশন পাঠানো হবে।
 * একই দিনে দুইবার না পাঠানোর জন্য duties.notified কলাম ব্যবহার হয়েছে।
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/WhatsApp.php';

$db = Database::getConnection(); // এই কলেই এডমিন প্যানেলে সেট করা টাইমজোন (Database::applyTimezone) চালু হয়ে যায়
$now = new DateTime();
$today = $now->format('Y-m-d');
$currentMinutes = ((int)$now->format('H')) * 60 + (int)$now->format('i');

$slots = [
    Database::getSetting('notification_time_1'),
    Database::getSetting('notification_time_2'),
    Database::getSetting('notification_time_3'),
];

$shouldRun = false;
foreach ($slots as $slot) {
    if (!$slot) continue;
    [$h, $m] = array_map('intval', explode(':', $slot));
    $slotMinutes = $h * 60 + $m;
    if (abs($currentMinutes - $slotMinutes) <= 5) {
        $shouldRun = true;
        break;
    }
}

if (!$shouldRun) {
    echo "এখন নোটিফিকেশন পাঠানোর সময় না।\n";
    exit();
}

$dutyLabels = [
    'market' => 'বাজার করার',
    'cooking' => 'রান্না করার',
    'trash' => 'ময়লা ফেলার',
    'cleaning' => 'রুম ক্লিন করার',
];
// টেমপ্লেটের {{3}} এ বসানোর জন্য (টেমপ্লেটের নিজস্ব বাক্যে ইতিমধ্যে "করার দায়িত্ব" আছে,
// তাই এখানে শুধু কাজের নামটুকু — ডুপ্লিকেট এড়াতে $dutyLabels থেকে আলাদা)
$dutyActivityNames = [
    'market' => 'বাজার',
    'cooking' => 'রান্না',
    'trash' => 'ময়লা ফেলা',
    'cleaning' => 'রুম ক্লিন',
];

// ---- ১. আজকের যেসব ডিউটি এখনো নোটিফাই করা হয়নি, তাদের পাঠানো ----
$stmt = $db->prepare(
    'SELECT d.*, u.name, u.whatsapp_number FROM duties d
     JOIN users u ON u.id = d.assigned_user_id
     WHERE d.duty_date = ? AND d.notified = 0 AND u.status = "active"'
);
$stmt->execute([$today]);
$duties = $stmt->fetchAll();

$dutyNotified = 0;
foreach ($duties as $duty) {
    $label = $dutyLabels[$duty['duty_type']] ?? $duty['duty_type'];
    $activity = $dutyActivityNames[$duty['duty_type']] ?? $duty['duty_type'];
    $message = "প্রিয় {$duty['name']},\nআজ ({$today}) আপনার উপর {$label} দায়িত্ব রয়েছে। অনুগ্রহ করে সময়মতো সম্পন্ন করুন।";

    $ok = WhatsApp::sendTemplateAndLog(
        $duty['assigned_user_id'], $duty['whatsapp_number'], 'duty_reminder',
        [$duty['name'], $today, $activity], 'duty_reminder', $message
    );
    if ($ok) {
        $upd = $db->prepare('UPDATE duties SET notified = 1, notified_at = NOW() WHERE id = ?');
        $upd->execute([$duty['id']]);
        $dutyNotified++;
    }
}

// ---- ২. যাদের ভাড়া বকেয়া আছে, তাদের রিমাইন্ডার (দিনে একবারই, তাই আজকের লগ চেক করা হয়) ----
$monthNames = ['', 'জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
$stmt = $db->query(
    'SELECT DISTINCT u.id, u.name, u.whatsapp_number,
        (SELECT SUM(amount_due - amount_paid) FROM rent_payments WHERE user_id = u.id AND status IN ("due","partial")) as total_due
     FROM users u
     JOIN rent_payments rp ON rp.user_id = u.id
     WHERE rp.status IN ("due","partial") AND u.status = "active"'
);
$dueUsers = $stmt->fetchAll();

$rentNotified = 0;
foreach ($dueUsers as $du) {
    if (!$du['total_due'] || $du['total_due'] <= 0) continue;

    // আজকে ইতিমধ্যে rent_due নোটিফিকেশন পাঠানো হয়েছে কিনা চেক
    $check = $db->prepare(
        'SELECT id FROM notification_logs WHERE user_id = ? AND category = "rent_due" AND DATE(created_at) = ? LIMIT 1'
    );
    $check->execute([$du['id'], $today]);
    if ($check->fetch()) continue; // আজ আগেই পাঠানো হয়েছে

    // কোন কোন মাসের ভাড়া বকেয়া তার তালিকা (টেমপ্লেটের {{2}} এ বসবে)
    $monthStmt = $db->prepare(
        'SELECT month, year, (amount_due - amount_paid) as due FROM rent_payments
         WHERE user_id = ? AND status IN ("due","partial") ORDER BY year ASC, month ASC'
    );
    $monthStmt->execute([$du['id']]);
    $monthRows = $monthStmt->fetchAll();
    $listItems = array_map(fn($r) => "{$monthNames[$r['month']]} {$r['year']}: {$r['due']} টাকা", $monthRows);
    $listText = implode(', ', $listItems) . "। সর্বমোট: {$du['total_due']} টাকা।";

    $message = "প্রিয় {$du['name']},\nআপনার মোট বকেয়া ভাড়া: {$du['total_due']} টাকা। অনুগ্রহ করে দ্রুত পরিশোধ করুন।";
    $ok = WhatsApp::sendTemplateAndLog(
        $du['id'], $du['whatsapp_number'], 'rent_overdue_list',
        [$du['name'], $listText], 'rent_due', $message
    );
    if ($ok) $rentNotified++;
}

echo "ডিউটি নোটিফিকেশন পাঠানো হয়েছে: {$dutyNotified}, ভাড়া বকেয়া রিমাইন্ডার: {$rentNotified}\n";
