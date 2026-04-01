<?php
// =============================================================
// index.php - نقطة الدخول الرئيسية لنظام الحضور والانصراف
// =============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// إذا كان المدير مسجل دخوله → لوحة التحكم
if (isAdminLoggedIn()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit;
}

// المستخدم العادي → صفحة تسجيل الحضور (PIN)
header('Location: ' . SITE_URL . '/employee/index.php');
exit;
