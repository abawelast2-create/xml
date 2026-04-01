<?php
// =============================================================
// includes/auth.php - مصادقة المديرين
// =============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * التحقق من تسجيل دخول المدير، وإعادة توجيهه إذا لم يكن مسجلاً
 */
function requireAdminLogin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . SITE_URL . '/admin/login.php');
        exit;
    }
}

/**
 * محاولة تسجيل دخول مدير
 */
function adminLogin(string $username, string $password): array {
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $admin = $stmt->fetch();

    // دائماً نستدعي password_verify لمنع Timing Attacks (حتى لو المستخدم غير موجود)
    $dummyHash = '$2y$10$dummyhashtopreventtimingattacksXXXXXXXXXXXXXXXXXXXXXXX';
    $hashToCheck = $admin ? $admin['password_hash'] : $dummyHash;
    $passwordValid = password_verify($password, $hashToCheck);

    if (!$admin || !$passwordValid) {
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }

    // تجديد معرف الجلسة لأمان أفضل
    session_regenerate_id(true);

    $_SESSION['admin_id']       = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_name']     = $admin['full_name'];

    // تذكرني — تمديد الجلسة لـ 30 يوم
    if (!empty($_POST['remember_me'])) {
        $ttl = 2592000; // 30 يوم
        setcookie('remember_admin', '1', time() + $ttl, '/', '', true, true);
        ini_set('session.cookie_lifetime', $ttl);
        ini_set('session.gc_maxlifetime', $ttl);
        session_set_cookie_params($ttl);
    }

    // تحديث آخر تسجيل دخول
    db()->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);

    return ['success' => true, 'message' => 'تم تسجيل الدخول بنجاح'];
}

/**
 * تسجيل خروج المدير
 */
function adminLogout(): void {
    $_SESSION = [];
    // مسح كوكي تذكرني
    setcookie('remember_admin', '', time() - 3600, '/', '', true, true);
    session_destroy();
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

/**
 * هل المدير مسجل دخوله؟
 */
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}
