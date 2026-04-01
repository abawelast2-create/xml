<?php
// =============================================================
// includes/config.php - إعدادات النظام الأساسية
// =============================================================

// Disable QUIC/HTTP3 globally - fix ERR_QUIC_PROTOCOL_ERROR
if (!headers_sent()) { header('Alt-Svc: clear'); }

// ================== تحميل ملف .env (بدون Composer) ==================
// البحث عن .env خارج public_html أولاً (آمن)، ثم في جذر المشروع كاحتياط
$_envFile = dirname(dirname(__DIR__)) . '/.env';       // e.g. /home/user/.env  ← الموقع الآمن
if (!file_exists($_envFile)) {
    $_envFile = dirname(__DIR__) . '/.env';            // fallback: جذر المشروع (عدّل موقع الملف على السيرفر)
}
if (file_exists($_envFile)) {
    $_envLines = file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($_envLines as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') === false) continue;
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        $_val = trim($_val);
        // إزالة علامات الاقتباس إن وُجدت
        if (preg_match('/^(["\'])(.*)\\1$/', $_val, $_m)) $_val = $_m[2];
        if (!array_key_exists($_key, $_ENV)) {
            $_ENV[$_key] = $_val;
            putenv("{$_key}={$_val}");
        }
    }
}

// ================== اتصال قاعدة البيانات ==================
define('DB_HOST',    $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER',    $_ENV['DB_USER'] ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS'] ?? '');
define('DB_NAME',    $_ENV['DB_NAME'] ?? 'attendance_system');
define('DB_CHARSET', 'utf8mb4');

// ================== رابط الموقع (Auto-detect) ==================
// يتم اكتشاف الرابط تلقائياً - لا حاجة لتعديله يدوياً
$_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
// التحقق من HTTP_HOST لمنع Host Header Injection
$_rawHost  = $_SERVER['HTTP_HOST'] ?? 'localhost';
// إزالة المنفذ للمقارنة
$_hostOnly = preg_replace('/:\d+$/', '', $_rawHost);
// السماح فقط بأحرف DNS صالحة (حماية من header injection)
$_host     = preg_match('/^[a-zA-Z0-9._\-]+$/', $_hostOnly) ? $_rawHost : 'localhost';
$_basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
// اذهب لجذر المشروع (قد نكون داخل admin/ أو api/ أو employee/)
if (preg_match('#/(admin|api|employee|includes|error)$#', $_basePath)) {
    $_basePath = dirname($_basePath);
}
define('SITE_URL', $_protocol . '://' . $_host . $_basePath);
define('SITE_NAME', 'نظام الحضور والانصراف');

// ================== إعدادات الجلسة ==================
$_isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', $_isSecure ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
// إذا كان "تذكرني" مفعلاً — 30 يوم، وإلا 24 ساعة
$_rememberMe = (!empty($_COOKIE['remember_admin']) && $_COOKIE['remember_admin'] === '1');
$_sessLifetime = $_rememberMe ? 2592000 : 86400;
ini_set('session.cookie_lifetime', $_sessLifetime);
ini_set('session.gc_maxlifetime', $_sessLifetime);

// ================== timezone ==================
date_default_timezone_set('Asia/Riyadh');

// ================== بدء الجلسة ==================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ================== رسائل الخطأ ==================
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ================== Security Headers ==================
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(self)');
}

// ================== v4.0 Bootstrap (MVC + Services) ==================
$_bootstrapFile = dirname(__DIR__) . '/config/bootstrap.php';
if (file_exists($_bootstrapFile)) {
    require_once $_bootstrapFile;
}

// ================== Session Timeout (30 min idle) ==================
if (!empty($_SESSION['admin_id'])) {
    $idleTimeout = 1800; // 30 دقيقة
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
        // انتهاء مهلة الجلسة — تسجيل خروج تلقائي
        session_unset();
        session_destroy();
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['flash_error'] = 'انتهت جلستك بسبب عدم النشاط. يرجى تسجيل الدخول مجدداً.';
    } else {
        $_SESSION['last_activity'] = time();
    }
}
