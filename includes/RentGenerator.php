<?php
require_once __DIR__ . '/Database.php';

/**
 * প্রতি মাসের ১ তারিখে (বা ম্যানুয়ালি) সব সক্রিয় গ্রাহকের জন্য সেই মাসের ভাড়ার
 * বকেয়া এন্ট্রি স্বয়ংক্রিয়ভাবে তৈরি করে (idempotent — একবার তৈরি হয়ে গেলে আবার
 * চালালেও ডুপ্লিকেট হবে না বা বিদ্যমান পেমেন্ট নষ্ট হবে না)।
 */
class RentGenerator
{
    /** @return array{created:int, skipped:int} */
    public static function generateForMonth(int $month, int $year): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT id, monthly_rent FROM users WHERE role = 'customer' AND status = 'active'"
        );
        $stmt->execute();
        $customers = $stmt->fetchAll();

        $created = 0;
        $skipped = 0;

        $insertStmt = $db->prepare(
            'INSERT INTO rent_payments (user_id, month, year, amount_due, amount_paid, status)
             VALUES (?, ?, ?, ?, 0, "due")
             ON DUPLICATE KEY UPDATE user_id = user_id' // বিদ্যমান থাকলে কিছুই পরিবর্তন হবে না
        );

        foreach ($customers as $c) {
            // মাসিক ভাড়া ০ বা খালি হলে বকেয়া তৈরির দরকার নেই
            if ((float)$c['monthly_rent'] <= 0) {
                $skipped++;
                continue;
            }
            // আগে থেকে এই মাসের এন্ট্রি আছে কিনা যাচাই (created কাউন্ট সঠিক রাখতে)
            $checkStmt = $db->prepare('SELECT id FROM rent_payments WHERE user_id = ? AND month = ? AND year = ?');
            $checkStmt->execute([$c['id'], $month, $year]);
            if ($checkStmt->fetch()) {
                $skipped++;
                continue;
            }
            $insertStmt->execute([$c['id'], $month, $year, $c['monthly_rent']]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
