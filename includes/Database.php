<?php
require_once __DIR__ . '/../config/config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                error_log('DB Connection Error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'ডাটাবেজ কানেকশন ব্যর্থ হয়েছে']);
                exit();
            }
            self::applyTimezone();
        }
        return self::$instance;
    }

    /**
     * এডমিন প্যানেলে সেট করা টাইমজোন অনুযায়ী পুরো রিকোয়েস্টের PHP টাইমজোন সেট করে।
     * এটার উপর ভিত্তি করেই cron নোটিফিকেশনের সময় মিলানো হয়, তারিখ/সময় দেখানো হয় ইত্যাদি।
     */
    public static function applyTimezone(): void
    {
        $tz = 'Asia/Dhaka'; // ফলব্যাক, সেটিংস না পাওয়া গেলে
        try {
            $stmt = self::$instance->prepare('SELECT setting_value FROM settings WHERE setting_key = "timezone"');
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value']) && in_array($row['setting_value'], timezone_identifiers_list(), true)) {
                $tz = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // সেটিংস টেবিল এখনো তৈরি না হলে ফলব্যাক টাইমজোন ব্যবহার হবে
        }
        date_default_timezone_set($tz);
    }

    /** সেটিংস টেবিল থেকে একটি ভ্যালু পড়ে (WhatsApp টোকেন, নোটিফিকেশন সময় ইত্যাদি) */
    public static function getSetting(string $key): ?string
    {
        $db = self::getConnection();
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : null;
    }

    /** এডমিন প্যানেল থেকে সেটিংস আপডেট করার জন্য */
    public static function setSetting(string $key, string $value): void
    {
        $db = self::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);

        if ($key === 'timezone') {
            self::applyTimezone();
        }
    }
}
