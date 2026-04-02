<?php
/**
 * Migration v5 - New features:
 * 1. early_minutes column in attendances
 * 2. late_grace_minutes setting
 * 3. auto_attendance_rules table
 * 4. session_lifetime setting
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$results = [];

// 1. Add early_minutes column to attendances
try {
    $cols = db()->query("SHOW COLUMNS FROM attendances LIKE 'early_minutes'")->fetch();
    if (!$cols) {
        db()->exec("ALTER TABLE attendances ADD COLUMN early_minutes INT DEFAULT 0 AFTER late_minutes");
        $results[] = "✅ Added early_minutes column to attendances";
    } else {
        $results[] = "⏭️ early_minutes column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ early_minutes: " . $e->getMessage();
}

// 2. Add late_grace_minutes setting
try {
    $exists = db()->query("SELECT 1 FROM settings WHERE setting_key = 'late_grace_minutes'")->fetch();
    if (!$exists) {
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute(['late_grace_minutes', '0']);
        $results[] = "✅ Added late_grace_minutes setting (default: 0)";
    } else {
        $results[] = "⏭️ late_grace_minutes setting already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ late_grace_minutes: " . $e->getMessage();
}

// 3. Add session_lifetime setting
try {
    $exists = db()->query("SELECT 1 FROM settings WHERE setting_key = 'session_lifetime'")->fetch();
    if (!$exists) {
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute(['session_lifetime', '120']);
        $results[] = "✅ Added session_lifetime setting (default: 120 min)";
    } else {
        $results[] = "⏭️ session_lifetime setting already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ session_lifetime: " . $e->getMessage();
}

// 4. Create auto_attendance_rules table
try {
    $tableExists = db()->query("SHOW TABLES LIKE 'auto_attendance_rules'")->fetch();
    if (!$tableExists) {
        db()->exec("
            CREATE TABLE auto_attendance_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                auto_time_from TIME NOT NULL DEFAULT '08:00:00',
                auto_time_to TIME NOT NULL DEFAULT '08:05:00',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_employee (employee_id),
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results[] = "✅ Created auto_attendance_rules table";
    } else {
        $results[] = "⏭️ auto_attendance_rules table already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ auto_attendance_rules: " . $e->getMessage();
}

// 5. Add support_phone setting
try {
    $exists = db()->query("SELECT 1 FROM settings WHERE setting_key = 'support_phone'")->fetch();
    if (!$exists) {
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute(['support_phone', '966578448146']);
        $results[] = "✅ Added support_phone setting";
    } else {
        $results[] = "⏭️ support_phone setting already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ support_phone: " . $e->getMessage();
}

echo "=== Migration v5 Results ===\n\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\n=== Done ===\n";
