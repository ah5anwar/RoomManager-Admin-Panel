<?php
require_once __DIR__ . '/Database.php';

class Auth
{
    /** লগইন করে টোকেন জেনারেট করে */
    public static function login(string $userCode, string $password): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE user_code = ? AND status = "active"');
        $stmt->execute([$userCode]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);

        $stmt = $db->prepare(
            'INSERT INTO auth_tokens (user_id, token, device_info, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'],
            $token,
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $expiresAt,
        ]);

        unset($user['password_hash']);
        $user = self::decodePermissions($user);
        return ['user' => $user, 'token' => $token, 'expires_at' => $expiresAt];
    }

    /** permissions কলামের JSON স্ট্রিংকে ফ্রন্টএন্ডের জন্য অ্যারেতে রূপান্তর করে */
    private static function decodePermissions(array $user): array
    {
        $user['permissions'] = !empty($user['permissions']) ? (json_decode($user['permissions'], true) ?: []) : [];
        return $user;
    }

    /**
     * Authorization হেডার নির্ভরযোগ্যভাবে খুঁজে বের করে।
     * অনেক Apache/cPanel/CGI হোস্টিং-এ getallheaders() দিয়ে Authorization হেডার পাওয়া যায় না
     * (নিরাপত্তার কারণে Apache এটা ডিফল্টভাবে PHP পর্যন্ত পাঠায় না), তাই একাধিক উৎস চেক করা হয়।
     */
    private static function getAuthorizationHeader(): string
    {
        // ১. স্বাভাবিক getallheaders() (নিজস্ব PHP সার্ভার/অনেক হোস্টিং এ কাজ করে)
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            foreach ($all as $key => $value) {
                if (strtolower($key) === 'authorization' && $value !== '') {
                    return $value;
                }
            }
        }
        // ২. Apache/CGI এ সরাসরি $_SERVER থেকে (mod_php/FastCGI তে এভাবে আসে)
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        // ৩. .htaccess এর RewriteRule দিয়ে ফরওয়ার্ড করা হেডার (CGI/suPHP তে দরকার হয়)
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return '';
    }

    /** Authorization হেডার থেকে টোকেন যাচাই করে ইউজার রিটার্ন করে */
    public static function verifyToken(): ?array
    {
        $authHeader = self::getAuthorizationHeader();

        if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return null;
        }
        $token = $matches[1];

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT u.* FROM auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ? AND t.expires_at > NOW() AND u.status = "active"'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) return null;
        unset($user['password_hash']);
        return self::decodePermissions($user);
    }

    /** রিকোয়েস্ট আটকায় যদি লগইন করা না থাকে; ইউজার রিটার্ন করে */
    public static function requireAuth(): array
    {
        $user = self::verifyToken();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'অনুগ্রহ করে লগইন করুন']);
            exit();
        }
        return $user;
    }

    /** শুধু এডমিনের জন্য - অন্য কেউ হলে 403 */
    public static function requireAdmin(): array
    {
        $user = self::requireAuth();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'এই কাজের অনুমতি শুধু এডমিনের আছে']);
            exit();
        }
        return $user;
    }

    /**
     * এডমিন অথবা ম্যানেজার - ম্যানেজার শুধু ভাড়া আদায় ও ডিউটি (বাজার/রান্না/ময়লা/ক্লিন) ম্যানেজ করতে পারবে,
     * কিন্তু ইউজার তৈরি, নোটিফিকেশন ব্রডকাস্ট, চ্যাট, সেটিংস — এগুলো শুধু এডমিনের জন্য সংরক্ষিত।
     */
    public static function requireAdminOrManager(): array
    {
        $user = self::requireAuth();
        if (!in_array($user['role'], ['admin', 'manager'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'এই কাজের অনুমতি শুধু এডমিন বা ম্যানেজারের আছে']);
            exit();
        }
        return $user;
    }

    /** পরবর্তী ইউজার কোড জেনারেট করে, যেমন AH-001, AH-002 */
    public static function generateNextUserCode(string $prefix = 'AH'): string
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT user_code FROM users WHERE user_code LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '-%']);
        $last = $stmt->fetch();

        if (!$last) {
            $next = 1;
        } else {
            $parts = explode('-', $last['user_code']);
            $next = (int)end($parts) + 1;
        }
        return $prefix . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }
}
