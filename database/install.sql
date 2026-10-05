-- ============================================
-- Room Manager — সম্পূর্ণ ডাটাবেজ স্কিমা (একটি ফাইলে)
-- ============================================
-- এই ফাইলটি নতুন ইনস্টল এবং আগে থেকে থাকা পুরনো ডাটাবেজ — দুই ক্ষেত্রেই নিরাপদে চালানো যায়।
-- বারবার রান করলেও সমস্যা হবে না (idempotent) — টেবিল/কলাম আগে থেকে থাকলে সেগুলো এড়িয়ে যাবে,
-- না থাকলে তৈরি করবে।
--
-- ============================================
-- ⚠️  cPanel-এ ব্যবহারের নিয়ম (গুরুত্বপূর্ণ)
-- ============================================
-- cPanel-এর সাধারণ ইউজারের নিজে থেকে নতুন ডাটাবেজ তৈরি করার (CREATE DATABASE) পারমিশন
-- থাকে না, তাই এই ফাইলে ইচ্ছাকৃতভাবে "CREATE DATABASE" বা "USE" কোনো লাইন রাখা হয়নি।
-- ধাপগুলো এভাবে করুন:
--   ১. cPanel → MySQL Databases এ গিয়ে একটা ডাটাবেজ তৈরি করুন
--      (যেমন creativesit_room_manager — cPanel নিজে থেকেই আপনার ইউজারনেম prefix হিসেবে বসিয়ে দেবে)
--   ২. ওই একই পেজ থেকে একটা ডাটাবেজ ইউজার তৈরি করে ডাটাবেজে "All Privileges" সহ যুক্ত করুন
--   ৩. phpMyAdmin খুলুন, বাম পাশের তালিকা থেকে ঠিক এই ডাটাবেজটা (creativesit_room_manager)
--      ক্লিক করে সিলেক্ট করুন — তারপর উপরের "SQL" ট্যাবে গিয়ে এই পুরো ফাইলের কনটেন্ট পেস্ট করে Go চাপুন।
--      (ডাটাবেজ সিলেক্ট করা থাকলে phpMyAdmin এমনিতেই সেই ডাটাবেজের ভেতরে কমান্ডগুলো চালাবে)
--   ৪. `config/config.php` ফাইলে DB_NAME হিসেবে ঠিক এই একই নাম (creativesit_room_manager) বসান
-- ============================================

-- ---------------------------------------------
-- ১. মূল টেবিলগুলো (নতুন ইনস্টলে সরাসরি চূড়ান্ত স্ট্রাকচারে তৈরি হয়)
-- ---------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_code VARCHAR(20) NOT NULL UNIQUE,      -- যেমন: AH-001
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    whatsapp_number VARCHAR(20) NOT NULL,        -- E.164 ফরম্যাটে, যেমন 8801XXXXXXXXX
    email VARCHAR(150) DEFAULT NULL,
    room_no VARCHAR(20) DEFAULT NULL,
    role ENUM('admin','manager','customer') NOT NULL DEFAULT 'customer',
    -- গ্রাহক কোন কোন ফিচার (market/cooking/trash/cleaning/chat) দেখতে পারবে, JSON array হিসেবে সংরক্ষিত
    permissions JSON DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    monthly_rent DECIMAL(10,2) DEFAULT 0.00,
    join_date DATE NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rent_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    month TINYINT NOT NULL,
    year SMALLINT NOT NULL,
    amount_due DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_date DATE DEFAULT NULL,
    status ENUM('paid','due','partial') NOT NULL DEFAULT 'due',
    note VARCHAR(255) DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_month (user_id, month, year),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS duties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    duty_type ENUM('market','cooking','trash','cleaning') NOT NULL,
    assigned_user_id INT NOT NULL,
    duty_date DATE NOT NULL,
    status ENUM('pending','done','missed') NOT NULL DEFAULT 'pending',
    amount DECIMAL(10,2) DEFAULT NULL, -- শুধু market ডিউটির খরচ (টাকা) — একবার গ্রাহক বসালে আর নিজে বদলাতে পারবে না
    notified TINYINT(1) NOT NULL DEFAULT 0,
    notified_at TIMESTAMP NULL DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_duty_slot (duty_type, duty_date), -- একদিনে একটা দায়িত্বে একজনই থাকবে (মাসিক শিডিউল upsert এর জন্য)
    INDEX idx_duty_date (duty_date, duty_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category ENUM('duty_reminder','rent_due','rent_paid','custom','rent_overdue_list','market_summary') NOT NULL,
    message TEXT NOT NULL,
    whatsapp_message_id VARCHAR(150) DEFAULT NULL,
    status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
    error_message VARCHAR(500) DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT DEFAULT NULL,        -- NULL মানে গ্রুপ চ্যাট
    is_group TINYINT(1) NOT NULL DEFAULT 1,
    message_type ENUM('text','image','voice') NOT NULL DEFAULT 'text',
    content TEXT DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_chat_group (is_group, created_at),
    INDEX idx_chat_private (sender_id, receiver_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    device_info VARCHAR(255) DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------
-- ২. মাইগ্রেশন — আগে থেকে (v1) থাকা ডাটাবেজে টেবিল তৈরি হয়েই গেছে, কিন্তু নতুন
--    কলাম/কনস্ট্রেইন্ট (manager রোল, permissions, ডিউটির ইউনিক কী) না থাকতে পারে।
--    নিচের কমান্ডগুলো MariaDB/MySQL এর নেটিভ "IF NOT EXISTS" সাপোর্ট ব্যবহার করে —
--    স্টোরড প্রসিডিউর লাগে না, তাই শেয়ার্ড হোস্টিং-এ যেখানে CREATE ROUTINE পারমিশন
--    দেওয়া থাকে না, সেখানেও নিরাপদে চলবে।
--    (প্রয়োজন: MariaDB 10.0.2+ অথবা MySQL 8.0.29+ — বর্তমান cPanel হোস্টিং সাধারণত এর চেয়ে নতুন)
-- ---------------------------------------------

-- users.role এ 'manager' যোগ (পুরনো ENUM এ শুধু admin/customer থাকতে পারে)
-- নোট: এটা IF NOT EXISTS দিয়ে গার্ড করা যায় না (MODIFY COLUMN), তবে বারবার চালালেও
-- একই ENUM এ MODIFY করলে কোনো এরর হয় না, তাই নিরাপদ।
ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','customer') NOT NULL DEFAULT 'customer';

-- users.permissions কলাম না থাকলে যোগ করা
ALTER TABLE users ADD COLUMN IF NOT EXISTS permissions JSON DEFAULT NULL AFTER role;

-- আগে থেকে থাকা সব গ্রাহককে ডিফল্টভাবে সব ফিচারের অনুমতি (permissions এখনো NULL থাকলে)
UPDATE users SET permissions = JSON_ARRAY('market','cooking','trash','cleaning','chat')
WHERE role = 'customer' AND permissions IS NULL;

-- duties এ ইউনিক কী (duty_type, duty_date) না থাকলে যোগ করা (মাসিক শিডিউল upsert এর জন্য প্রয়োজন)
-- সতর্কতা: যদি একই দিনে একই ডিউটি টাইপে একাধিক এন্ট্রি (ডুপ্লিকেট) আগে থেকে থাকে, এই ধাপে এরর আসবে —
-- সেক্ষেত্রে আগে ডুপ্লিকেট সারিগুলো মুছে ফেলুন, তারপর আবার এই ফাইলটি চালান।
ALTER TABLE duties ADD UNIQUE KEY IF NOT EXISTS uniq_duty_slot (duty_type, duty_date);

-- বাজার ডিউটির খরচ ট্র্যাক করার জন্য amount কলাম (পুরনো ইনস্টলে যোগ করা)
ALTER TABLE duties ADD COLUMN IF NOT EXISTS amount DECIMAL(10,2) DEFAULT NULL AFTER status;

-- notification_logs.status এ delivered/read যোগ (WhatsApp webhook থেকে ডেলিভারি স্ট্যাটাস রাখার জন্য)
ALTER TABLE notification_logs MODIFY COLUMN status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued';

-- বাজার খরচের সারাংশ ব্রডকাস্ট ক্যাটাগরি যোগ (পুরনো ইনস্টলে)
ALTER TABLE notification_logs MODIFY COLUMN category ENUM('duty_reminder','rent_due','rent_paid','custom','rent_overdue_list','market_summary') NOT NULL;

-- ---------------------------------------------
-- ৩. ডিফল্ট সেটিংস (না থাকলেই কেবল ঢুকবে)
-- ---------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
('whatsapp_phone_number_id', ''),
('whatsapp_access_token', ''),
('whatsapp_business_account_id', ''),
('notification_time_1', '08:00'),
('notification_time_2', '14:00'),
('notification_time_3', '20:00'),
('app_name', 'Room Manager'),
('currency_symbol', '৳'),
('timezone', 'Asia/Dhaka'), -- এডমিন প্যানেল থেকে পরিবর্তনযোগ্য, পুরো সিস্টেম এই টাইমজোন অনুযায়ী চলবে
('whatsapp_webhook_verify_token', 'room_manager_verify_2026'), -- Meta এর Webhook Configuration এ এই একই টোকেন বসাতে হবে
('system_start_year', YEAR(CURDATE())), -- সিস্টেম কোন বছর থেকে শুরু, এর আগের মাস/বছর কোথাও দেখানো হবে না
('system_start_month', MONTH(CURDATE())) -- সিস্টেম কোন মাস থেকে শুরু
ON DUPLICATE KEY UPDATE setting_value = setting_value; -- আগে থেকে থাকলে ভ্যালু বদলাবে না

-- ---------------------------------------------
-- ৪. ডিফল্ট এডমিন (শুধু একদম নতুন ইনস্টলে — user_code আগে থেকে থাকলে স্কিপ হয়ে যাবে)
--    পাসওয়ার্ড হ্যাশ এখানে প্লেসহোল্ডার — প্রথমবার চালানোর পর অবশ্যই setup_admin.php দিয়ে
--    আসল পাসওয়ার্ড সেট করে নিন এবং ফাইলটি ডিলিট করে দিন।
-- ---------------------------------------------
INSERT IGNORE INTO users (user_code, name, phone, whatsapp_number, role, password_hash, join_date, status)
VALUES ('AH-000', 'Admin', '01700000000', '8801700000000', 'admin', '$2y$10$PLACEHOLDER_RUN_SETUP_SCRIPT', CURDATE(), 'active');
