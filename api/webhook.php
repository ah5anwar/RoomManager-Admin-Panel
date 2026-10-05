<?php
/**
 * Meta WhatsApp Webhook এন্ডপয়েন্ট
 * ==========================================
 * Meta App Dashboard → WhatsApp → Configuration → Webhook এ গিয়ে:
 *   Callback URL:   https://yourdomain.com/RoomManager/api/webhook.php
 *   Verify Token:   এডমিন প্যানেলের সেটিংসে যা সেট করা আছে (ডিফল্ট: room_manager_verify_2026)
 * তারপর "Verify and Save" চাপুন, এবং "messages" ফিল্ডে Subscribe করুন।
 *
 * এই এন্ডপয়েন্ট দুই ধরনের রিকোয়েস্ট হ্যান্ডেল করে:
 *   GET  → Meta প্রথমবার Callback URL ভেরিফাই করার জন্য কল করে
 *   POST → পরবর্তীতে মেসেজ ডেলিভারি স্ট্যাটাস (sent/delivered/read/failed) ও
 *          গ্রাহকের পাঠানো ইনকামিং মেসেজ এখানে আসে
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/WhatsApp.php';

$db = Database::getConnection();

// ---- ১. GET: Meta এর Callback URL ভেরিফিকেশন ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    $expectedToken = Database::getSetting('whatsapp_webhook_verify_token') ?? 'room_manager_verify_2026';

    if ($mode === 'subscribe' && hash_equals($expectedToken, (string)$token)) {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $challenge; // Meta এর নিয়ম অনুযায়ী hub_challenge টা হুবহু ফেরত পাঠাতে হয়
        exit();
    }

    http_response_code(403);
    echo 'Verification token mismatch';
    exit();
}

// ---- ২. POST: ডেলিভারি স্ট্যাটাস আপডেট ও ইনকামিং মেসেজ লগ ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);

    // Meta সবসময় ২০০ রেসপন্স আশা করে, নাহলে বারবার একই ইভেন্ট পাঠাতে থাকবে
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);

    // রেসপন্স আগেই পাঠিয়ে দেওয়া হয়েছে যাতে Meta দ্রুত 200 পায়;
    // এখন ব্যাকগ্রাউন্ডে বাকি কাজ (ডাটাবেজ আপডেট) করা হচ্ছে
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    if (!$payload || empty($payload['entry'])) {
        exit();
    }

    try {
        foreach ($payload['entry'] as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                // ---- মেসেজ ডেলিভারি স্ট্যাটাস (sent/delivered/read/failed) ----
                foreach (($value['statuses'] ?? []) as $statusEvent) {
                    $waMessageId = $statusEvent['id'] ?? null;
                    $newStatus = $statusEvent['status'] ?? null; // sent | delivered | read | failed
                    if (!$waMessageId || !in_array($newStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $errorMsg = null;
                    if ($newStatus === 'failed' && !empty($statusEvent['errors'][0]['title'])) {
                        $errorMsg = WhatsApp::friendlyError($statusEvent['errors'][0]['title']);
                    }
                    $stmt = $db->prepare(
                        'UPDATE notification_logs SET status = ?, error_message = COALESCE(?, error_message)
                         WHERE whatsapp_message_id = ?'
                    );
                    $stmt->execute([$newStatus, $errorMsg, $waMessageId]);
                }

                // ---- গ্রাহকের পাঠানো ইনকামিং মেসেজ (ভবিষ্যতে চ্যাটে দেখাতে চাইলে এখান থেকে বাড়ানো যাবে) ----
                foreach (($value['messages'] ?? []) as $incoming) {
                    $fromNumber = $incoming['from'] ?? '';
                    $text = $incoming['text']['body'] ?? '[non-text message]';
                    error_log("WhatsApp থেকে ইনকামিং মেসেজ — {$fromNumber}: {$text}");
                    // চাইলে এখানে matching user খুঁজে chat_messages টেবিলে যোগ করার লজিক বসানো যাবে
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Webhook processing error: ' . $e->getMessage());
    }
    exit();
}

http_response_code(405);
echo 'Method not allowed';
