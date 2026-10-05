<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/WhatsApp.php';

header('Content-Type: application/json; charset=utf-8');
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

// ---- একজন ইউজারের ভাড়ার হিস্ট্রি (নিজেরটা বা এডমিন যেকোনো একজনেরটা) ----
if ($method === 'GET' && $action === 'history') {
    $user = Auth::requireAuth();
    $targetId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $user['id'];
    $isStaff = in_array($user['role'], ['admin', 'manager'], true);
    if (!$isStaff && $targetId !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'অনুমতি নেই']);
        exit();
    }
    $stmt = $db->prepare('SELECT * FROM rent_payments WHERE user_id = ? ORDER BY year DESC, month DESC');
    $stmt->execute([$targetId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- নির্দিষ্ট মাসের সব ইউজারের ভাড়ার অবস্থা (এডমিন ড্যাশবোর্ডের জন্য) ----
if ($method === 'GET' && $action === 'monthly-summary') {
    Auth::requireAdminOrManager();
    $month = (int)($_GET['month'] ?? date('n'));
    $year = (int)($_GET['year'] ?? date('Y'));

    $stmt = $db->prepare(
        'SELECT u.id as user_id, u.user_code, u.name, u.room_no, u.monthly_rent,
                rp.id as payment_id, rp.amount_due, rp.amount_paid, rp.status, rp.payment_date
         FROM users u
         LEFT JOIN rent_payments rp ON rp.user_id = u.id AND rp.month = ? AND rp.year = ?
         WHERE u.role = "customer" AND u.status = "active"
         ORDER BY u.user_code ASC'
    );
    $stmt->execute([$month, $year]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- সকল বকেয়া ভাড়ার লিস্ট (সব মাস মিলিয়ে, কার কত বাকি) ----
if ($method === 'GET' && $action === 'due-list') {
    Auth::requireAdminOrManager();
    $stmt = $db->query(
        'SELECT u.id as user_id, u.user_code, u.name, u.room_no, u.whatsapp_number,
                rp.month, rp.year, (rp.amount_due - rp.amount_paid) as due_amount
         FROM rent_payments rp
         JOIN users u ON u.id = rp.user_id
         WHERE rp.status IN ("due","partial")
         ORDER BY u.user_code ASC, rp.year ASC, rp.month ASC'
    );
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- ভাড়া নির্ধারণ / আপডেট (এডমিন) - সাথে সাথে WhatsApp মেসেজ যাবে ----
if ($method === 'POST' && $action === 'update-payment') {
    $admin = Auth::requireAdminOrManager();

    $userId = (int)($input['user_id'] ?? 0);
    $month = (int)($input['month'] ?? 0);
    $year = (int)($input['year'] ?? 0);
    $amountDue = $input['amount_due'] ?? null;
    $amountPaid = (float)($input['amount_paid'] ?? 0);
    $note = $input['note'] ?? null;

    if (!$userId || !$month || !$year) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id, month, year আবশ্যক']);
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

    if ($amountDue === null) {
        $amountDue = $customer['monthly_rent'];
    }

    $status = $amountPaid >= $amountDue ? 'paid' : ($amountPaid > 0 ? 'partial' : 'due');
    $paymentDate = $status === 'paid' || $status === 'partial' ? date('Y-m-d') : null;

    $stmt = $db->prepare(
        'INSERT INTO rent_payments (user_id, month, year, amount_due, amount_paid, payment_date, status, note, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            amount_due = VALUES(amount_due),
            amount_paid = VALUES(amount_paid),
            payment_date = VALUES(payment_date),
            status = VALUES(status),
            note = VALUES(note),
            updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$userId, $month, $year, $amountDue, $amountPaid, $paymentDate, $status, $note, $admin['id']]);

    // ---- সাথে সাথে গ্রাহকের WhatsApp এ মেসেজ ----
    $monthNames = ['', 'জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
    $monthName = $monthNames[$month] ?? $month;
    $due = $amountDue - $amountPaid;

    if ($status === 'paid') {
        $message = "প্রিয় {$customer['name']},\n{$monthName} {$year} মাসের ভাড়া {$amountPaid} টাকা সম্পূর্ণ পরিশোধ হয়েছে। ধন্যবাদ।";
        $waOk = WhatsApp::sendTemplateAndLog(
            $customer['id'], $customer['whatsapp_number'], 'rent_paid_confirmation',
            [$customer['name'], "{$monthName} {$year}", (string)$amountPaid],
            'rent_paid', $message
        );
    } else {
        $message = "প্রিয় {$customer['name']},\n{$monthName} {$year} মাসের ভাড়া বাবদ {$amountPaid} টাকা জমা হয়েছে। বাকি আছে {$due} টাকা।";
        $waOk = WhatsApp::sendTemplateAndLog(
            $customer['id'], $customer['whatsapp_number'], 'rent_due_reminder',
            [$customer['name'], "{$monthName} {$year}", (string)$amountPaid, (string)$due],
            'rent_due', $message
        );
    }

    $msg = $waOk
        ? 'ভাড়ার হিসাব আপডেট হয়েছে ও গ্রাহককে WhatsApp এ জানানো হয়েছে'
        : 'ভাড়ার হিসাব আপডেট হয়েছে, কিন্তু WhatsApp মেসেজ পাঠানো যায়নি (সেটিংসে WhatsApp API কনফিগার করা আছে কিনা চেক করুন)';
    echo json_encode(['success' => true, 'message' => $msg, 'data' => ['whatsapp_sent' => $waOk]]);
    exit();
}

// ---- এই মাসের বকেয়া সবার জন্য এখনই জেনারেট করুন (ম্যানুয়াল ট্রিগার, ক্রন ছাড়াও ব্যবহার করা যাবে) ----
if ($method === 'POST' && $action === 'generate-monthly') {
    Auth::requireAdmin();
    require_once __DIR__ . '/../includes/RentGenerator.php';
    $month = (int)($input['month'] ?? date('n'));
    $year = (int)($input['year'] ?? date('Y'));
    $result = RentGenerator::generateForMonth($month, $year);
    echo json_encode([
        'success' => true,
        'message' => "{$result['created']} জন গ্রাহকের বকেয়া তৈরি হয়েছে, {$result['skipped']} জন আগে থেকেই ছিল/স্কিপ হয়েছে",
        'data' => $result,
    ]);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
