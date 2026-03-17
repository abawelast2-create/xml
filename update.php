<?php
// =============================================================
// update.php - تحديث قاعدة البيانات للإصدار الجديد
// =============================================================
// يُشغّل مرة واحدة لإضافة الجداول والأعمدة والإعدادات الجديدة
// =============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<html><head><meta charset='utf-8'><title>تحديث النظام</title></head><body style='font-family:Arial;direction:rtl;padding:20px'>";
echo "<h2>🔄 تحديث قاعدة البيانات</h2>";

try {
    $pdo = db();
    $log = [];
    
    // =================== إنشاء جدول الفروع إذا لم يكن موجوداً ===================
    try {
        $pdo->query("SELECT 1 FROM branches LIMIT 1");
        $log[] = '⏭️ جدول branches موجود';
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE branches (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                name                  VARCHAR(255) NOT NULL UNIQUE,
                latitude              DECIMAL(10,8) NOT NULL DEFAULT 24.57230700,
                longitude             DECIMAL(11,8) NOT NULL DEFAULT 46.60255200,
                geofence_radius       INT NOT NULL DEFAULT 500,
                work_start_time       TIME NOT NULL DEFAULT '08:00:00',
                work_end_time         TIME NOT NULL DEFAULT '16:00:00',
                check_in_start_time   TIME NOT NULL DEFAULT '07:00:00',
                check_in_end_time     TIME NOT NULL DEFAULT '10:00:00',
                check_out_start_time  TIME NOT NULL DEFAULT '15:00:00',
                check_out_end_time    TIME NOT NULL DEFAULT '20:00:00',
                checkout_show_before  INT NOT NULL DEFAULT 30,
                allow_overtime        TINYINT(1) NOT NULL DEFAULT 1,
                overtime_start_after  INT NOT NULL DEFAULT 60,
                overtime_min_duration INT NOT NULL DEFAULT 30,
                is_active             TINYINT(1) NOT NULL DEFAULT 1,
                created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = '✅ تم إنشاء جدول branches';
    }

    // =================== إضافة أعمدة جديدة للموظفين ===================
    $employeeColumns = [
        'branch_id'            => 'INT NULL AFTER unique_token',
        'device_fingerprint'   => 'VARCHAR(64) NULL AFTER branch_id',
        'device_registered_at' => 'TIMESTAMP NULL AFTER device_fingerprint',
        'device_bind_mode'     => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER device_registered_at',
        'pin_changed_at'       => 'TIMESTAMP NULL DEFAULT NULL AFTER pin',
    ];
    foreach ($employeeColumns as $col => $def) {
        try {
            $pdo->query("SELECT $col FROM employees LIMIT 1");
            $log[] = "⏭️ عمود employees.$col موجود";
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN $col $def");
            $log[] = "✅ تم إضافة عمود employees.$col";
        }
    }

    // =================== تحديث جدول الحضور لدعم overtime ===================
    try {
        $pdo->exec("ALTER TABLE attendances MODIFY COLUMN type ENUM('in','out','overtime') NOT NULL");
        $log[] = '✅ تم تحديث جدول attendances لدعم الدوام الإضافي';
    } catch (Exception $e) {
        $log[] = '⚠️ جدول attendances: ' . $e->getMessage();
    }

    // =================== v3.0: عمود attendance_date + late_minutes في الحضور ===================
    $attendanceNewCols = [
        'attendance_date' => 'DATE NULL AFTER timestamp',
        'late_minutes'    => 'INT DEFAULT 0 AFTER attendance_date',
    ];
    foreach ($attendanceNewCols as $col => $def) {
        try {
            $pdo->query("SELECT $col FROM attendances LIMIT 1");
            $log[] = "⏭️ عمود attendances.$col موجود";
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE attendances ADD COLUMN $col $def");
            $log[] = "✅ تم إضافة عمود attendances.$col";
        }
    }

    // تعبئة attendance_date من timestamp للبيانات القديمة
    try {
        $affected = $pdo->exec("UPDATE attendances SET attendance_date = DATE(timestamp) WHERE attendance_date IS NULL");
        if ($affected > 0) {
            $log[] = "✅ تم تعبئة attendance_date لـ $affected سجل قديم";
        }
    } catch (Exception $e) {
        $log[] = '⚠️ تعبئة attendance_date: ' . $e->getMessage();
    }

    // إضافة فهرس attendance_date
    try {
        $pdo->exec("ALTER TABLE attendances ADD INDEX idx_attendance_date (attendance_date)");
        $log[] = '✅ تم إضافة فهرس idx_attendance_date';
    } catch (Exception $e) {
        // الفهرس موجود بالفعل
        $log[] = '⏭️ فهرس idx_attendance_date موجود';
    }

    // =================== v3.0: عمود deleted_at في الموظفين (Soft Delete) ===================
    try {
        $pdo->query("SELECT deleted_at FROM employees LIMIT 1");
        $log[] = '⏭️ عمود employees.deleted_at موجود';
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER is_active");
        $log[] = '✅ تم إضافة عمود employees.deleted_at';
    }

    // =================== v3.0: جدول محاولات الدخول ===================
    try {
        $pdo->query("SELECT 1 FROM login_attempts LIMIT 1");
        $log[] = '⏭️ جدول login_attempts موجود';
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE login_attempts (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                ip_address      VARCHAR(45)     NOT NULL,
                username        VARCHAR(50)     NULL,
                attempted_at    DATETIME        NOT NULL,
                INDEX idx_ip_time (ip_address, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = '✅ تم إنشاء جدول login_attempts';
    }

    // =================== v3.0: جدول سجل التدقيق ===================
    try {
        $pdo->query("SELECT 1 FROM audit_log LIMIT 1");
        $log[] = '⏭️ جدول audit_log موجود';
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE audit_log (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                admin_id        INT             NULL,
                action          VARCHAR(50)     NOT NULL,
                details         TEXT            NULL,
                target_id       INT             NULL,
                ip_address      VARCHAR(45)     NULL,
                created_at      DATETIME        NOT NULL,
                INDEX idx_action (action),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = '✅ تم إنشاء جدول audit_log';
    }

    // =================== v4.0: عمود PIN + توليد تلقائي ===================
    // إضافة عمود pin إذا لم يكن موجوداً
    try {
        $pdo->query("SELECT pin FROM employees LIMIT 1");
        $log[] = '⏭️ عمود employees.pin موجود';
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN pin VARCHAR(10) NULL AFTER job_title");
        $log[] = '✅ تم إضافة عمود employees.pin';
        // إضافة فهرس فريد
        try {
            $pdo->exec("ALTER TABLE employees ADD UNIQUE INDEX idx_pin (pin)");
            $log[] = '✅ تم إضافة فهرس فريد للـ PIN';
        } catch (Exception $e2) { }
    }

    // توليد PIN تلقائي لكل موظف بدون PIN
    $empsNoPinStmt = $pdo->query("SELECT id FROM employees WHERE pin IS NULL OR pin = ''");
    $empsNoPin = $empsNoPinStmt->fetchAll();
    $pinCount = 0;
    foreach ($empsNoPin as $emp) {
        // توليد PIN فريد
        do {
            $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare("SELECT id FROM employees WHERE pin = ?");
            $chk->execute([$pin]);
        } while ($chk->fetch());
        $pdo->prepare("UPDATE employees SET pin = ? WHERE id = ?")->execute([$pin, $emp['id']]);
        $pinCount++;
    }
    if ($pinCount > 0) {
        $log[] = "✅ تم توليد PIN لـ {$pinCount} موظف";
    }

    // =================== v3.0: جدول الإجازات ===================
    try {
        $pdo->query("SELECT 1 FROM leaves LIMIT 1");
        $log[] = '⏭️ جدول leaves موجود';
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE leaves (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                employee_id     INT             NOT NULL,
                leave_type      ENUM('annual','sick','unpaid','other') NOT NULL DEFAULT 'annual',
                start_date      DATE            NOT NULL,
                end_date        DATE            NOT NULL,
                reason          TEXT            NULL,
                status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_by     INT             NULL,
                created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL,
                INDEX idx_employee_dates (employee_id, start_date, end_date),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = '✅ تم إنشاء جدول leaves';
    }
    
    // =================== إضافة الإعدادات الجديدة ===================
    $newSettings = [
        ['work_latitude',       '24.572307', 'خط عرض موقع العمل'],
        ['work_longitude',      '46.602552', 'خط طول موقع العمل'],
        ['geofence_radius',     '500',       'نصف قطر الجيوفينس بالمتر'],
        ['work_start_time',     '08:00', 'بداية الدوام الرسمي'],
        ['work_end_time',       '16:00', 'نهاية الدوام الرسمي'],
        ['check_in_start_time', '07:00', 'بداية وقت تسجيل الدخول'],
        ['check_in_end_time',   '10:00', 'نهاية وقت تسجيل الدخول'],
        ['check_out_start_time','15:00', 'بداية وقت تسجيل الانصراف'],
        ['check_out_end_time',  '20:00', 'نهاية وقت تسجيل الانصراف'],
        ['checkout_show_before','30',    'دقائق قبل إظهار زر الانصراف'],
        ['allow_overtime',      '1',     'السماح بالدوام الإضافي'],
        ['overtime_start_after','60',    'دقائق بعد نهاية الدوام لبدء الإضافي'],
        ['overtime_min_duration','30',   'الحد الأدنى للدوام الإضافي بالدقائق'],
        ['site_name',           'نظام الحضور والانصراف', 'اسم النظام'],
        ['company_name',        '',      'اسم الشركة'],
        ['timezone',            'Asia/Riyadh', 'المنطقة الزمنية'],
    ];
    
    $stmtCheck = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?,?,?)");
    
    foreach ($newSettings as $s) {
        $stmtCheck->execute([$s[0]]);
        if (!$stmtCheck->fetch()) {
            $stmtInsert->execute($s);
            $log[] = "✅ تم إضافة إعداد: {$s[0]}";
        } else {
            $log[] = "⏭️ الإعداد موجود: {$s[0]}";
        }
    }
    
    // =================== إضافة أعمدة الاستراحة للفروع ===================
    $breakCols = [
        'break_start' => 'TIME NULL DEFAULT NULL',
        'break_end'   => 'TIME NULL DEFAULT NULL',
    ];
    foreach ($breakCols as $col => $def) {
        try {
            $pdo->query("SELECT `$col` FROM branches LIMIT 1");
            $log[] = "⏭️ عمود branches.$col موجود";
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE branches ADD COLUMN `$col` $def");
            $log[] = "✅ تم إضافة عمود branches.$col";
        }
    }

    echo "<ul>";
    foreach ($log as $l) {
        $color = str_contains($l, '✅') ? '#10B981' : (str_contains($l, '⚠️') ? '#F59E0B' : '#94A3B8');
        echo "<li style='color:$color;margin:6px 0'>$l</li>";
    }
    echo "</ul>";
    
    echo "<h3 style='color:green'>✅ تم التحديث بنجاح!</h3>";
    echo "<p><a href='admin/login.php' style='color:#3B82F6'>الذهاب للوحة التحكم →</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
