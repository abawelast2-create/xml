<?php
// =============================================================
// api/send-all-links.php - إرسال جميع روابط الموظفين عبر واتساب
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'طريقة طلب غير مسموحة'], 405);
}

// التحقق من CSRF
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'رمز الأمان غير صحيح'], 403);
}

$phone = trim($_POST['phone'] ?? '966578448146');

try {
    // جلب جميع الموظفين النشطين مرتبين حسب الفرع
    $stmt = db()->query("
        SELECT e.name, e.pin, e.unique_token, e.phone, b.name AS branch_name
        FROM employees e
        LEFT JOIN branches b ON e.branch_id = b.id
        WHERE e.is_active = 1 AND e.deleted_at IS NULL
        ORDER BY b.name, e.name
    ");
    $employees = $stmt->fetchAll();
    
    if (empty($employees)) {
        jsonResponse(['success' => false, 'message' => 'لا يوجد موظفين نشطين']);
    }
    
    // بناء رسالة واحدة تحتوي على جميع الروابط
    $message = "🔗 *روابط الحضور - " . (getSystemSetting('company_name') ?: 'نظام الحضور') . "*\n";
    $message .= "📅 " . date('Y-m-d') . "\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n\n";
    
    $currentBranch = '';
    $count = 0;
    
    foreach ($employees as $emp) {
        $branch = $emp['branch_name'] ?: 'بدون فرع';
        
        if ($branch !== $currentBranch) {
            $currentBranch = $branch;
            $message .= "\n🏢 *{$currentBranch}*\n";
            $message .= "──────────────\n";
        }
        
        $count++;
        $link = SITE_URL . '/employee/attendance.php?token=' . $emp['unique_token'];
        $message .= "👤 {$emp['name']}\n";
        $message .= "🔗 {$link}\n\n";
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "📊 إجمالي الموظفين: {$count}\n";
    $message .= "⚠️ هذه الروابط سرية - لا تشاركها";
    
    // بناء رابط واتساب
    $waUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
    
    auditLog('send_all_links', "إرسال جميع الروابط ({$count} موظف) إلى الرقم {$phone}");
    
    jsonResponse([
        'success'  => true,
        'wa_url'   => $waUrl,
        'count'    => $count,
        'message'  => "تم تجهيز {$count} رابط للإرسال"
    ]);

} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ], 500);
}
