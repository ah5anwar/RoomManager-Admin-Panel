<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $userCode = trim($input['user_code'] ?? '');
    $password = $input['password'] ?? '';

    if (!$userCode || !$password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ইউজার আইডি ও পাসওয়ার্ড দিন']);
        exit();
    }

    $result = Auth::login($userCode, $password);
    if (!$result) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'ইউজার আইডি বা পাসওয়ার্ড ভুল']);
        exit();
    }

    echo json_encode(['success' => true, 'data' => $result]);
    exit();
}

if ($method === 'GET' && $action === 'me') {
    $user = Auth::requireAuth();
    echo json_encode(['success' => true, 'data' => $user]);
    exit();
}

if ($method === 'POST' && $action === 'change-password') {
    $user = Auth::requireAuth();
    $newPassword = $input['new_password'] ?? '';
    $oldPassword = $input['old_password'] ?? '';

    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!password_verify($oldPassword, $row['password_hash'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'পুরাতন পাসওয়ার্ড ভুল']);
        exit();
    }
    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে']);
        exit();
    }

    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    echo json_encode(['success' => true, 'message' => 'পাসওয়ার্ড পরিবর্তন হয়েছে']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'অবৈধ রিকোয়েস্ট']);
