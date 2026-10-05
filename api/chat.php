<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ---- গ্রুপ চ্যাটের মেসেজ লোড করা (সবাই দেখতে পারবে) ----
if ($method === 'GET' && $action === 'group') {
    Auth::requireAuth();
    $afterId = (int)($_GET['after_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT c.*, u.name, u.user_code, u.profile_photo FROM chat_messages c
         JOIN users u ON u.id = c.sender_id
         WHERE c.is_group = 1 AND c.id > ?
         ORDER BY c.id ASC LIMIT 100'
    );
    $stmt->execute([$afterId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- প্রাইভেট চ্যাট হিস্ট্রি (এডমিন যেকোনো গ্রাহকের সাথে, গ্রাহক শুধু নিজের ও এডমিনের মধ্যেরটা দেখবে) ----
if ($method === 'GET' && $action === 'private') {
    $user = Auth::requireAuth();
    $otherId = (int)($_GET['with'] ?? 0);
    $selfId = (int)$user['id'];

    // গ্রাহক/ম্যানেজার নিজে ছাড়া অন্য কারো আইডি দিয়ে কল করলেও কোয়েরি এমনিতেই selfId
    // ব্যবহার করে বলে ডেটা লিক হয় না, তাই এখানে অতিরিক্ত কড়াকড়ি করার দরকার নেই — সবাই
    // নিজের সাথে সম্পর্কিত থ্রেডটাই দেখবে।
    $stmt = $db->prepare(
        'SELECT c.*, u.name, u.user_code FROM chat_messages c
         JOIN users u ON u.id = c.sender_id
         WHERE c.is_group = 0 AND (
            (c.sender_id = ? AND c.receiver_id = ?) OR
            (c.sender_id = ? AND c.receiver_id = ?)
         )
         ORDER BY c.id ASC LIMIT 200'
    );
    $stmt->execute([$selfId, $otherId, $otherId, $selfId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

// ---- গ্রাহক/ম্যানেজার নিজের প্রাইভেট চ্যাটের জন্য এডমিনের আইডি/নাম জানার এন্ডপয়েন্ট ----
if ($method === 'GET' && $action === 'admin-info') {
    Auth::requireAuth();
    $stmt = $db->query('SELECT id, name, user_code, role FROM users WHERE role = "admin" AND status = "active" ORDER BY id ASC LIMIT 1');
    $admin = $stmt->fetch();
    echo json_encode(['success' => (bool)$admin, 'data' => $admin ?: null]);
    exit();
}

// ---- টেক্সট মেসেজ পাঠানো ----
if ($method === 'POST' && $action === 'send') {
    $user = Auth::requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $receiverId = isset($input['receiver_id']) ? (int)$input['receiver_id'] : null;
    $isGroup = $receiverId === null ? 1 : 0;
    $content = trim($input['content'] ?? '');

    // প্রাইভেট মেসেজ শুরু করতে পারবেন শুধু এডমিন — তবে গ্রাহক/ম্যানেজার নিজের প্রাইভেট থ্রেডে
    // এডমিনকে রিপ্লাই দিতে পারবেন (অন্য কোনো গ্রাহককে প্রাইভেট মেসেজ পাঠাতে পারবেন না)
    if (!$isGroup && $user['role'] !== 'admin') {
        $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$receiverId]);
        $receiver = $stmt->fetch();
        if (!$receiver || $receiver['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'শুধু এডমিনের সাথে প্রাইভেট মেসেজ আদান-প্রদান করা যাবে']);
            exit();
        }
    }
    if (!$content) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'মেসেজ খালি রাখা যাবে না']);
        exit();
    }

    $stmt = $db->prepare(
        'INSERT INTO chat_messages (sender_id, receiver_id, is_group, message_type, content) VALUES (?, ?, ?, "text", ?)'
    );
    $stmt->execute([$user['id'], $receiverId, $isGroup, $content]);
    echo json_encode(['success' => true, 'data' => ['id' => $db->lastInsertId()]]);
    exit();
}

// ---- ছবি / ভয়েস আপলোড করে মেসেজ পাঠানো (multipart/form-data) ----
if ($method === 'POST' && $action === 'send-media') {
    $user = Auth::requireAuth();

    $receiverId = isset($_POST['receiver_id']) && $_POST['receiver_id'] !== '' ? (int)$_POST['receiver_id'] : null;
    $isGroup = $receiverId === null ? 1 : 0;
    $type = $_POST['type'] ?? 'image'; // image অথবা voice

    if (!$isGroup && $user['role'] !== 'admin') {
        $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$receiverId]);
        $receiver = $stmt->fetch();
        if (!$receiver || $receiver['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'শুধু এডমিনের সাথে প্রাইভেট মেসেজ আদান-প্রদান করা যাবে']);
            exit();
        }
    }
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ফাইল পাওয়া যায়নি']);
        exit();
    }

    $allowedImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedVoice = ['mp3', 'ogg', 'wav', 'm4a', 'oga', 'webm']; // webm = ব্রাউজারের MediaRecorder API যা প্রায়ই তৈরি করে
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

    $subDir = $type === 'voice' ? 'chat_voice' : 'chat_images';
    $allowed = $type === 'voice' ? $allowedVoice : $allowedImage;

    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'অসমর্থিত ফাইল ফরম্যাট']);
        exit();
    }

    $filename = uniqid('chat_', true) . '.' . $ext;
    $destPath = UPLOAD_DIR . $subDir . '/' . $filename;
    $relativePath = 'uploads/' . $subDir . '/' . $filename;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ফাইল আপলোড ব্যর্থ হয়েছে']);
        exit();
    }

    $stmt = $db->prepare(
        'INSERT INTO chat_messages (sender_id, receiver_id, is_group, message_type, file_path) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['id'], $receiverId, $isGroup, $type, $relativePath]);

    echo json_encode(['success' => true, 'data' => ['id' => $db->lastInsertId(), 'file_path' => $relativePath]]);
    exit();
}

// ---- চ্যাট মেসেজ ডিলিট করা (এডমিন ও ম্যানেজার) ----
if ($method === 'DELETE' && $action === 'delete') {
    Auth::requireAdminOrManager();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'অবৈধ মেসেজ আইডি']);
        exit();
    }
    // ফাইল থাকলে (ছবি/ভয়েস) সার্ভার থেকেও মুছে ফেলা হয়
    $stmt = $db->prepare('SELECT file_path FROM chat_messages WHERE id = ?');
    $stmt->execute([$id]);
    $msg = $stmt->fetch();
    if ($msg && !empty($msg['file_path'])) {
        $fullPath = __DIR__ . '/../' . $msg['file_path'];
        if (is_file($fullPath)) @unlink($fullPath);
    }
    $stmt = $db->prepare('DELETE FROM chat_messages WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'মেসেজ ডিলিট করা হয়েছে']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
