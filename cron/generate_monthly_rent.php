<?php
/**
 * প্রতি মাসের ১ তারিখে চালানোর ক্রন স্ক্রিপ্ট — এই মাসের জন্য সব সক্রিয় গ্রাহকের
 * ভাড়ার বকেয়া এন্ট্রি স্বয়ংক্রিয়ভাবে তৈরি করে।
 *
 * cPanel Cron কমান্ড (প্রতিদিন একবার রান হবে, কিন্তু ১ তারিখ ছাড়া কিছু করবে না):
 *   0 1 1 * * php /home/creativesituser/public_html/RoomManager/cron/generate_monthly_rent.php
 * (প্রতি মাসের ১ তারিখ রাত ১টায় একবারই চলবে)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/RentGenerator.php';

$month = (int)date('n');
$year = (int)date('Y');

$result = RentGenerator::generateForMonth($month, $year);

echo "[" . date('Y-m-d H:i:s') . "] {$month}/{$year} — তৈরি হয়েছে: {$result['created']}, স্কিপ হয়েছে: {$result['skipped']}\n";
