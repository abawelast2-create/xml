<?php
// =============================================================
// install.php - سكريبت تثبيت قاعدة البيانات (مرة واحدة فقط)
// =============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendance_system');

// تحميل .env إن وُجد (للتوافق مع config.php)
$_envFile = __DIR__ . '/.env';
if (file_exists($_envFile)) {
    $_envLines = file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($_envLines as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') === false) continue;
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        $_val = trim($_val);
        if (preg_match('/^(["\'])(.*)\\1$/', $_val, $_m)) $_val = $_m[2];
        if (in_array($_key, ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'])) {
            // Override default constants — use runkit or just store in variable
            $_ENV[$_key] = $_val;
        }
    }
    // Re-define with .env values if found (constants already defined, use variables)
}
// Use env values over defaults
$_dbHost = $_ENV['DB_HOST'] ?? DB_HOST;
$_dbUser = $_ENV['DB_USER'] ?? DB_USER;
$_dbPass = $_ENV['DB_PASS'] ?? DB_PASS;
$_dbName = $_ENV['DB_NAME'] ?? DB_NAME;

// ===================== حماية: منع التشغيل المتكرر =====================
$lockFile = __DIR__ . '/install.lock';

if (file_exists($lockFile)) {
    die('<!DOCTYPE html><html lang="ar" dir="rtl">
    <head><meta charset="UTF-8"><title>تم التثبيت</title>
    <style>body{font-family:Arial;background:#0F172A;color:#E2E8F0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#1E293B;border-radius:16px;padding:40px;max-width:500px;text-align:center}
    h2{color:#D4A841;margin-bottom:12px} p{color:#94A3B8;margin-bottom:20px;line-height:1.7}
    a{display:inline-block;padding:10px 22px;background:#059669;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold;margin:4px}
    </style></head>
    <body><div class="box">
    <h2>✅ تم التثبيت مسبقاً</h2>
    <p>قاعدة البيانات جاهزة ومثبتة.<br>
    احذف هذا الملف من السيرفر لأسباب أمنية.</p>
    <a href="admin/dashboard.php">📊 لوحة التحكم</a>
    </div></body></html>');
}

$log     = [];
$success = true;

try {
    // الاتصال بـ MySQL مع تحديد قاعدة البيانات مباشرة (استضافة مشتركة)
    $pdo = new PDO(
        "mysql:host=" . $_dbHost . ";dbname=" . $_dbName . ";charset=utf8mb4",
        $_dbUser,
        $_dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $log[] = '✅ تم الاتصال بقاعدة البيانات: ' . $_dbName;

    // =================== مسح كل شيء أولاً (تثبيت نظيف) ===================
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DROP TABLE IF EXISTS attendances");
    $pdo->exec("DROP TABLE IF EXISTS leaves");
    $pdo->exec("DROP TABLE IF EXISTS secret_reports");
    $pdo->exec("DROP TABLE IF EXISTS tampering_cases");
    $pdo->exec("DROP TABLE IF EXISTS known_devices");
    $pdo->exec("DROP TABLE IF EXISTS employees");
    $pdo->exec("DROP TABLE IF EXISTS branches");
    $pdo->exec("DROP TABLE IF EXISTS admins");
    $pdo->exec("DROP TABLE IF EXISTS settings");
    $pdo->exec("DROP TABLE IF EXISTS login_attempts");
    $pdo->exec("DROP TABLE IF EXISTS audit_log");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    $log[] = '🗑️ تم مسح الجداول القديمة بالكامل';

    // =================== جدول الفروع ===================
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

    // =================== جدول الموظفين ===================
    $pdo->exec("
        CREATE TABLE employees (
            id                    INT AUTO_INCREMENT PRIMARY KEY,
            name                  VARCHAR(255)    NOT NULL,
            job_title             VARCHAR(255)    NOT NULL,
            pin                   VARCHAR(10)     UNIQUE NOT NULL,
            pin_changed_at        TIMESTAMP       NULL DEFAULT NULL,
            phone                 VARCHAR(20)     NULL,
            unique_token          VARCHAR(64)     UNIQUE NOT NULL,
            branch_id             INT             NULL,
            device_fingerprint    VARCHAR(64)     NULL,
            device_registered_at  TIMESTAMP       NULL,
            device_bind_mode      TINYINT(1)      NOT NULL DEFAULT 0,
            security_level        INT             DEFAULT 2,
            is_active             TINYINT(1)      DEFAULT 1,
            deleted_at            TIMESTAMP       NULL DEFAULT NULL,
            created_at            TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = '✅ تم إنشاء جدول employees';

    // =================== جدول الحضور والانصراف ===================
    $pdo->exec("
        CREATE TABLE attendances (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            employee_id         INT             NOT NULL,
            type                ENUM('in','out','overtime-start','overtime-end') NOT NULL,
            timestamp           DATETIME        NOT NULL,
            attendance_date     DATE            NOT NULL,
            late_minutes        INT             DEFAULT 0,
            latitude            DECIMAL(10,8)   NOT NULL,
            longitude           DECIMAL(11,8)   NOT NULL,
            location_accuracy   DECIMAL(5,2)    NULL,
            ip_address          VARCHAR(45)     NULL,
            user_agent          TEXT            NULL,
            notes               TEXT            NULL,
            created_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            INDEX idx_employee_date (employee_id, timestamp),
            INDEX idx_type_date (type, timestamp),
            INDEX idx_attendance_date (attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $log[] = '✅ تم إنشاء جدول attendances';

    // =================== جدول المديرين ===================
    $pdo->exec("
        CREATE TABLE admins (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            username        VARCHAR(50)     UNIQUE NOT NULL,
            password_hash   VARCHAR(255)    NOT NULL,
            full_name       VARCHAR(255)    NOT NULL,
            last_login      DATETIME        NULL,
            created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $log[] = '✅ تم إنشاء جدول admins';

    // =================== جدول الإعدادات ===================
    $pdo->exec("
        CREATE TABLE settings (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            setting_key     VARCHAR(100)    UNIQUE NOT NULL,
            setting_value   TEXT            NULL,
            description     VARCHAR(255)    NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $log[] = '✅ تم إنشاء جدول settings';

    // =================== جدول محاولات الدخول ===================
    $pdo->exec("
        CREATE TABLE login_attempts (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            ip_address      VARCHAR(45)     NOT NULL,
            username        VARCHAR(50)     NULL,
            attempted_at    DATETIME        NOT NULL,
            INDEX idx_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $log[] = '✅ تم إنشاء جدول login_attempts';

    // =================== جدول سجل التدقيق ===================
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $log[] = '✅ تم إنشاء جدول audit_log';

    // =================== جدول الأجهزة المعروفة ===================
    $pdo->exec("
        CREATE TABLE known_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fingerprint VARCHAR(64) NOT NULL,
            employee_id INT NOT NULL,
            usage_count INT NOT NULL DEFAULT 1,
            first_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fp_emp (fingerprint, employee_id),
            KEY idx_fp (fingerprint),
            KEY idx_emp (employee_id),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = '✅ تم إنشاء جدول known_devices';

    // =================== جدول الإجازات ===================
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $log[] = '✅ تم إنشاء جدول leaves';

    // =================== جدول البلاغات السرية ===================
    $pdo->exec("
        CREATE TABLE secret_reports (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            employee_id     INT             NOT NULL,
            report_text     TEXT            NULL,
            report_type     VARCHAR(50)     NOT NULL DEFAULT 'violation',
            image_paths     JSON            NULL,
            has_image       TINYINT(1)      DEFAULT 0,
            image_path      VARCHAR(500)    NULL,
            has_voice       TINYINT(1)      DEFAULT 0,
            voice_path      VARCHAR(500)    NULL,
            voice_effect    VARCHAR(20)     NULL,
            status          ENUM('new','reviewed','in_progress','resolved','dismissed','archived') DEFAULT 'new',
            admin_notes     TEXT            NULL,
            created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            reviewed_at     TIMESTAMP       NULL,
            reviewed_by     INT             NULL,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = '✅ تم إنشاء جدول secret_reports';

    // =================== جدول حالات التلاعب ===================
    $pdo->exec("
        CREATE TABLE tampering_cases (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            employee_id     INT             NOT NULL,
            case_type       VARCHAR(50)     NOT NULL,
            description     TEXT            NULL,
            attendance_date DATE            NULL,
            severity        ENUM('low','medium','high') DEFAULT 'medium',
            details_json    JSON            NULL,
            created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            INDEX idx_employee (employee_id),
            INDEX idx_type (case_type),
            INDEX idx_date (attendance_date),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = '✅ تم إنشاء جدول tampering_cases';

    // =================== إدخال الإعدادات الأولية ===================
    $settings = [
        // إعدادات الموقع
        ['work_latitude',       '24.572307', 'خط عرض موقع العمل'],
        ['work_longitude',      '46.602552', 'خط طول موقع العمل'],
        ['geofence_radius',     '25',    'نصف قطر الجيوفينس بالمتر'],
        // أوقات الدوام
        ['work_start_time',     '08:00', 'بداية الدوام الرسمي'],
        ['work_end_time',       '16:00', 'نهاية الدوام الرسمي'],
        ['check_in_start_time', '07:00', 'بداية وقت تسجيل الدخول'],
        ['check_in_end_time',   '10:00', 'نهاية وقت تسجيل الدخول'],
        ['check_out_start_time', '15:00', 'بداية وقت تسجيل الانصراف'],
        ['check_out_end_time',  '20:00', 'نهاية وقت تسجيل الانصراف'],
        ['checkout_show_before', '30',    'دقائق قبل إظهار زر الانصراف'],
        // الدوام الإضافي
        ['allow_overtime',      '1',     'السماح بالدوام الإضافي'],
        ['overtime_start_after', '60',    'دقائق بعد نهاية الدوام لبدء الإضافي'],
        ['overtime_min_duration', '30',   'الحد الأدنى للدوام الإضافي بالدقائق'],
        // إعدادات عامة
        ['site_name',           'نظام الحضور والانصراف', 'اسم النظام'],
        ['company_name',        '',      'اسم الشركة'],
        ['timezone',            'Asia/Riyadh', 'المنطقة الزمنية'],
    ];
    $stmtSet = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES (?,?,?)");
    foreach ($settings as $s) {
        $stmtSet->execute($s);
    }
    $log[] = '✅ تم إدخال الإعدادات الأولية';

    // =================== إنشاء المدير الافتراضي ===================
    $defaultAdmin = [
        'username'      => 'admin',
        'password_hash' => password_hash('Admin@1234', PASSWORD_DEFAULT),
        'full_name'     => 'مدير النظام',
    ];
    $stmtAdmin = $pdo->prepare("INSERT IGNORE INTO admins (username, password_hash, full_name) VALUES (?,?,?)");
    $stmtAdmin->execute([$defaultAdmin['username'], $defaultAdmin['password_hash'], $defaultAdmin['full_name']]);
    $log[] = '✅ تم إنشاء حساب المدير الافتراضي (admin / Admin@1234)';

    // =================== إنشاء الفروع الخمسة ===================
    $branches = [
        ['صرح الاوروبي',  24.57231000, 46.60256100, 25],
        ['صرح الرئيسي',   24.57236300, 46.60278800, 25],
        ['فضاء 1',        24.57107600, 46.61104800, 25],
        ['فضاء 2',        24.56932700, 46.61478200, 25],
        ['صرح الامريكي',  24.57246600, 46.60298500, 25],
    ];
    $stmtBranch = $pdo->prepare("
        INSERT INTO branches (name, latitude, longitude, geofence_radius,
            work_start_time, work_end_time, check_in_start_time, check_in_end_time,
            check_out_start_time, check_out_end_time, checkout_show_before,
            allow_overtime, overtime_start_after, overtime_min_duration)
        VALUES (?, ?, ?, ?, '08:00','16:00','07:00','10:00','15:00','20:00', 30, 1, 60, 30)
    ");
    $branchIds = [];
    foreach ($branches as $b) {
        $stmtBranch->execute([$b[0], $b[1], $b[2], $b[3]]);
        $branchIds[$b[0]] = (int) $pdo->lastInsertId();
    }
    $log[] = '✅ تم إنشاء ' . count($branches) . ' فروع: ' . implode('، ', array_keys($branchIds));

    // =================== بيانات الـ 40 موظف ===================
    // [الاسم, المسمى, PIN, رقم الجوال, اسم الفرع]
    $employees = [
        ['إسلام',              'موظف', '1001', '+966549820672',  'صرح الاوروبي'],
        ['حسني',               'موظف', '1002', '+966537491699',  'صرح الاوروبي'],
        ['بخاري',              'موظف', '1003', '+923095734018',  'صرح الاوروبي'],
        ['أبو سليمان',          'موظف', '1004', '+966500651865',  'صرح الاوروبي'],
        ['صابر',               'موظف', '1005', '+966570899595',  'صرح الاوروبي'],
        ['زاهر',               'موظف', '1006', '+966546481759',  'صرح الرئيسي'],
        ['أيمن',               'موظف', '1007', '+966555090870',  'صرح الرئيسي'],
        ['أمجد',               'موظف', '1008', '+966555106370',  'صرح الرئيسي'],
        ['نجيب',               'موظف', '1009', '+923475914157',  'صرح الرئيسي'],
        ['محمد جلال',           'موظف', '1010', '+966573603727',  'فضاء 1'],
        ['محمد بلال',           'موظف', '1011', '+966503863694',  'فضاء 1'],
        ['رمضان عباس علي',      'موظف', '1012', '+966594119151',  'فضاء 1'],
        ['محمد أفريدي',         'موظف', '1013', '+966565722089',  'فضاء 1'],
        ['سلفادور ديلا',        'موظف', '1014', '+966541756875',  'فضاء 1'],
        ['محمد خان',            'موظف', '1015', '+966594163035',  'فضاء 1'],
        ['أندريس بورتس',        'موظف', '1016', '+966590087140',  'فضاء 1'],
        ['حسن (آصف)',           'موظف', '1017', '+966582670736',  'فضاء 1'],
        ['رمضان أويس علي',      'موظف', '1018', '+966531096640',  'فضاء 1'],
        ['ساكدا بندولا',        'موظف', '1019', '+966572746930',  'فضاء 1'],
        ['شحاتة',              'موظف', '1020', '+966545677065',  'فضاء 1'],
        ['منذر محمد',           'موظف', '1021', '+966556593723',  'فضاء 1'],
        ['مصطفى عوض سعد',       'موظف', '1022', '+966555106370',  'فضاء 1'],
        ['عنايات',             'موظف', '1023', '+966582329361',  'فضاء 1'],
        ['محمد خميس',           'موظف', '1024', '+966153254390',  'فضاء 1'],
        ['عبد الهادي يونس',     'موظف', '1025', '+966159626196',  'فضاء 1'],
        ['عبدالله اليمني',      'موظف', '1026', '+966536765655',  'فضاء 2'],
        ['أفضل',               'موظف', '1027', '+966599258117',  'فضاء 2'],
        ['حبيب',               'موظف', '1028', '+966573263203',  'فضاء 2'],
        ['إمتي',               'موظف', '1029', '+966595806604',  'فضاء 2'],
        ['عرنوس',              'موظف', '1030', '+966500089178',  'فضاء 2'],
        ['عرفان',              'موظف', '1031', '+966597255093',  'فضاء 2'],
        ['وسيم',               'موظف', '1032', '+966531806242',  'فضاء 2'],
        ['جهاد',               'موظف', '1033', '+966508512355',  'فضاء 2'],
        ['ابانوب',             'موظف', '1034', '+966536781886',  'فضاء 2'],
        ['قتيبة',              'موظف', '1035', '+966597024453',  'فضاء 2'],
        ['وداعة الله',          'موظف', '1036', '+966571761401',  'صرح الامريكي'],
        ['وقاص',               'موظف', '1037', '+966598997295',  'صرح الامريكي'],
        ['شعبان',              'موظف', '1038', '+966595153544',  'صرح الامريكي'],
        ['مصعب',               'موظف', '1039', '+966555792273',  'صرح الامريكي'],
        ['بلال',               'موظف', '1040', '+966594154009',  'صرح الامريكي'],
    ];

    $stmtEmp = $pdo->prepare("
        INSERT IGNORE INTO employees (name, job_title, pin, phone, unique_token, branch_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $inserted = 0;
    foreach ($employees as $emp) {
        $token    = bin2hex(random_bytes(32));
        $bId      = $branchIds[$emp[4]] ?? null;
        $stmtEmp->execute([$emp[0], $emp[1], $emp[2], $emp[3], $token, $bId]);
        if ($stmtEmp->rowCount() > 0) $inserted++;
    }
    $log[] = "✅ تم إدخال {$inserted} موظف من أصل " . count($employees);

    // =================== كتابة ملف القفل =================== 
    file_put_contents($lockFile, date('Y-m-d H:i:s'));
    $log[] = '🔒 تم إنشاء ملف القفل (install.lock)';
} catch (PDOException $e) {
    $success = false;
    $log[]   = '❌ خطأ: ' . $e->getMessage();
}

// =================== عرض النتائج ===================
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تثبيت النظام</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0F172A;
            color: #E2E8F0;
            padding: 40px 20px;
        }

        .container {
            max-width: 700px;
            margin: auto;
            background: #1E293B;
            border-radius: 16px;
            padding: 36px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .5);
        }

        h1 {
            color: #D4A841;
            font-size: 1.8rem;
            margin-bottom: 24px;
            text-align: center;
        }

        .log-item {
            padding: 10px 14px;
            margin: 8px 0;
            border-radius: 8px;
            font-size: .95rem;
            background: #0F172A;
            border-right: 4px solid #D4A841;
        }

        .success-box {
            background: #064E3B;
            border-right-color: #10B981;
        }

        .error-box {
            background: #7F1D1D;
            border-right-color: #EF4444;
        }

        .btn {
            display: inline-block;
            margin-top: 24px;
            padding: 12px 28px;
            background: #D4A841;
            color: #0F172A;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            font-size: 1rem;
        }

        .btn:hover {
            background: #B8922A;
        }

        .creds {
            background: #1E3A5F;
            border: 1px solid #3B82F6;
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
        }

        .creds p {
            margin: 6px 0;
            font-size: .9rem;
        }

        .creds strong {
            color: #93C5FD;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🚀 تثبيت نظام الحضور والانصراف</h1>
        <?php foreach ($log as $line): ?>
            <div class="log-item <?= str_contains($line, '❌') ? 'error-box' : 'success-box' ?>">
                <?= htmlspecialchars($line) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div class="creds">
                <p>🔑 <strong>بيانات دخول لوحة التحكم:</strong></p>
                <p>رابط: <strong><?= defined('SITE_URL') ? '' : 'yourdomain.com' ?>/admin/login.php</strong></p>
                <p>المستخدم: <strong>admin</strong></p>
                <p>كلمة المرور: <strong>Admin@1234</strong></p>
                <p style="color:#FCD34D;margin-top:8px;">⚠️ غيّر كلمة المرور فور تسجيل الدخول!</p>
            </div>
            <a href="admin/login.php" class="btn">الذهاب إلى لوحة التحكم →</a>
        <?php else: ?>
            <p style="color:#FCA5A5;margin-top:16px;">حدث خطأ. تحقق من بيانات الاتصال في أعلى الملف.</p>
        <?php endif; ?>
    </div>
</body>

</html>