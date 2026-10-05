<?php
require_once __DIR__ . '/Database.php';

/**
 * Meta WhatsApp Business Cloud API wrapper
 * এডমিন প্যানেলের সেটিংস থেকে phone_number_id ও access_token আসে,
 * তাই কোনো হার্ডকোডেড কী লাগে না — এডমিন প্যানেল থেকেই পুরো API কনফিগার হয়।
 */
class WhatsApp
{
    private string $phoneNumberId;
    private string $accessToken;
    private string $apiVersion = 'v20.0';

    public function __construct()
    {
        $this->phoneNumberId = Database::getSetting('whatsapp_phone_number_id') ?? '';
        $this->accessToken   = Database::getSetting('whatsapp_access_token') ?? '';
    }

    public function isConfigured(): bool
    {
        return !empty($this->phoneNumberId) && !empty($this->accessToken);
    }

    /**
     * সাধারণ টেক্সট মেসেজ পাঠায়।
     * নোট: মেটার নিয়ম অনুযায়ী কাস্টমারকে প্রথমবার বা ২৪ ঘন্টা উইন্ডোর বাইরে
     * মেসেজ পাঠাতে হলে Approved Template ব্যবহার করতে হয় (sendTemplate() দেখুন)।
     */
    public function sendText(string $toNumber, string $message): array
    {
        return $this->callApi([
            'messaging_product' => 'whatsapp',
            'to' => $this->formatNumber($toNumber),
            'type' => 'text',
            'text' => ['body' => $message],
        ]);
    }

    /**
     * টেমপ্লেট মেসেজ পাঠায় (২৪ ঘন্টা উইন্ডোর বাইরে বাধ্যতামূলক)
     * টেমপ্লেটগুলো Meta Business Manager এ আগে থেকে approve করা থাকতে হবে।
     */
    public function sendTemplate(string $toNumber, string $templateName, array $params = [], string $lang = 'bn'): array
    {
        $components = [];
        if (!empty($params)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => (string)$p], $params),
            ];
        }

        return $this->callApi([
            'messaging_product' => 'whatsapp',
            'to' => $this->formatNumber($toNumber),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $lang],
                'components' => $components,
            ],
        ]);
    }

    /** ছবি পাঠানোর জন্য (public URL প্রয়োজন) */
    public function sendImage(string $toNumber, string $imageUrl, string $caption = ''): array
    {
        return $this->callApi([
            'messaging_product' => 'whatsapp',
            'to' => $this->formatNumber($toNumber),
            'type' => 'image',
            'image' => ['link' => $imageUrl, 'caption' => $caption],
        ]);
    }

    private function formatNumber(string $number): string
    {
        // শুধু ডিজিট রাখে, দেশের কোড সহ (যেমন 8801XXXXXXXXX)
        $clean = preg_replace('/[^0-9]/', '', $number);
        if (substr($clean, 0, 2) !== '88' && strlen($clean) === 11) {
            $clean = '88' . $clean; // 01XXXXXXXXX -> 8801XXXXXXXXX
        }
        return $clean;
    }

    private function callApi(array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'WhatsApp API কনফিগার করা হয়নি। এডমিন প্যানেল থেকে সেটিংস দিন।'];
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => $curlError];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($data['messages'][0]['id'])) {
            return ['success' => true, 'message_id' => $data['messages'][0]['id'], 'raw' => $data];
        }

        return ['success' => false, 'error' => $data['error']['message'] ?? 'অজানা এরর', 'raw' => $data];
    }

    /**
     * Meta-র কিছু সাধারণ ইংরেজি/টেকনিক্যাল এরর মেসেজকে সহজ বাংলায় বুঝিয়ে দেয়,
     * যাতে নোটিফিকেশন হিস্ট্রিতে (ওয়েবহুক থেকে আসা এরর সহ) সহজে বোঝা যায় করণীয় কী।
     */
    public static function friendlyError(?string $rawError): ?string
    {
        if (!$rawError) return $rawError;
        $lower = mb_strtolower($rawError);

        if (str_contains($lower, 'eligibility payment') || str_contains($lower, 'business eligibility')) {
            return "$rawError — এর মানে Meta Business Manager-এ পেমেন্ট মেথড (কার্ড) যোগ করা নেই। Business Settings → Payments এ গিয়ে একটা কার্ড যোগ করুন।";
        }
        if (str_contains($lower, 'access token') || str_contains($lower, 'oauth')) {
            return "$rawError — এর মানে Access Token ভুল বা মেয়াদ শেষ। সেটিংসে গিয়ে নতুন (Permanent/System User) টোকেন বসান।";
        }
        if (str_contains($lower, 're-engagement') || str_contains($lower, '24 hour') || str_contains($lower, '24-hour')) {
            return "$rawError — এর মানে গ্রাহক ২৪ ঘণ্টার মধ্যে মেসেজ দেননি, তাই সাধারণ টেক্সট যায়নি। অনুমোদিত টেমপ্লেট ব্যবহার করতে হবে।";
        }
        if (str_contains($lower, 'not a whatsapp') || str_contains($lower, 'not registered')) {
            return "$rawError — এর মানে এই নম্বরে WhatsApp সচল নেই।";
        }
        if (str_contains($lower, 'template') && (str_contains($lower, 'not found') || str_contains($lower, 'does not exist'))) {
            return "$rawError — এর মানে এই নামের টেমপ্লেট Meta-তে পাওয়া যায়নি বা এখনো অনুমোদিত হয়নি।";
        }
        return $rawError;
    }

    /**
     * সাধারণ টেক্সট মেসেজ পাঠায় এবং notification_logs টেবিলে লগ করে
     */
    public static function sendAndLog(int $userId, string $whatsappNumber, string $message, string $category): bool
    {
        $wa = new self();
        $result = $wa->sendText($whatsappNumber, $message);
        return self::logResult($userId, $message, $result, $category);
    }

    /**
     * Meta-approved টেমপ্লেট দিয়ে মেসেজ পাঠায় এবং notification_logs এ লগ করে।
     * ২৪ ঘণ্টা কাস্টমার-সার্ভিস উইন্ডোর বাইরেও (অর্থাৎ সবসময়) নির্ভরযোগ্যভাবে ডেলিভার হয়,
     * কারণ Meta আগে থেকেই টেমপ্লেটের কনটেন্ট রিভিউ করে অনুমোদন দিয়ে রাখে।
     *
     * @param array $params টেমপ্লেটের {{1}}, {{2}}... এর জায়গায় যা বসবে, ক্রম অনুযায়ী
     * @param string $displayMessage আমাদের নিজের notification_logs হিস্ট্রিতে (বাংলায়, সম্পূর্ণ) যা দেখানো হবে
     */
    public static function sendTemplateAndLog(
        int $userId,
        string $whatsappNumber,
        string $templateName,
        array $params,
        string $category,
        string $displayMessage
    ): bool {
        $wa = new self();
        $result = $wa->sendTemplate($whatsappNumber, $templateName, $params);
        return self::logResult($userId, $displayMessage, $result, $category);
    }

    private static function logResult(int $userId, string $displayMessage, array $result, string $category): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO notification_logs (user_id, category, message, whatsapp_message_id, status, error_message, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $category,
            $displayMessage,
            $result['message_id'] ?? null,
            $result['success'] ? 'sent' : 'failed',
            self::friendlyError($result['error'] ?? null),
            $result['success'] ? date('Y-m-d H:i:s') : null,
        ]);

        return $result['success'];
    }
}
