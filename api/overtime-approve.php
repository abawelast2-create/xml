<?php
// =============================================================
// api/overtime-approve.php - API اعتماد العمل الإضافي
// =============================================================
// يتيح للمدير/HR تغيير حالة الانصراف التلقائي إلى "عمل إضافي معتمد"
// بحيث لا تُحذف بيانات الموظف الذي عمل وقتاً إضافياً
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// فقط المسؤولون
if (!isAdminLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'غير مصرح'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'طريقة طلب غير مسموحة'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    // Fallback to POST form data
    $body = $_POST;
}

$attendanceId = (int)($body['attendance_id'] ?? 0);
$action       = $body['action'] ?? ''; // 'approve' or 'revert'
$csrfToken    = $body['csrf_token'] ?? '';

if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(['success' => false, 'message' => 'رمز الحماية غير صالح'], 403);
}

if ($attendanceId <= 0) {
    jsonResponse(['success' => false, 'message' => 'معرف السجل مطلوب'], 400);
}

if (!in_array($action, ['approve', 'revert'], true)) {
    jsonResponse(['success' => false, 'message' => 'الإجراء غير صالح. استخدم approve أو revert'], 400);
}

try {
    // جلب السجل
    $stmt = db()->prepare("
        SELECT a.id, a.employee_id, a.type, a.status, a.attendance_date, a.timestamp,
               e.name AS employee_name
        FROM attendances a
        JOIN employees e ON a.employee_id = e.id
        WHERE a.id = ?
    ");
    $stmt->execute([$attendanceId]);
    $record = $stmt->fetch();

    if (!$record) {
        jsonResponse(['success' => false, 'message' => 'السجل غير موجود'], 404);
    }

    if ($action === 'approve') {
        // اعتماد كعمل إضافي
        if ($record['status'] !== 'auto_checkout') {
            jsonResponse(['success' => false, 'message' => 'هذا السجل ليس انصراف تلقائي. الحالة الحالية: ' . ($record['status'] ?? 'manual')], 400);
        }

        $updateStmt = db()->prepare("UPDATE attendances SET status = 'overtime_approved', notes = CONCAT(COALESCE(notes,''), ' | اعتمد كعمل إضافي بواسطة المسؤول') WHERE id = ?");
        $updateStmt->execute([$attendanceId]);

        auditLog('overtime_approve', "اعتماد انصراف تلقائي كعمل إضافي: سجل #{$attendanceId} - {$record['employee_name']} - {$record['attendance_date']}", $attendanceId);

        jsonResponse([
            'success'    => true,
            'message'    => "تم اعتماد سجل {$record['employee_name']} كعمل إضافي",
            'new_status' => 'overtime_approved',
            'new_csrf'   => $_SESSION['csrf_token'] ?? ''
        ]);

    } elseif ($action === 'revert') {
        // إلغاء الاعتماد (إعادة إلى auto_checkout)
        if ($record['status'] !== 'overtime_approved') {
            jsonResponse(['success' => false, 'message' => 'هذا السجل ليس عملاً إضافياً معتمداً.'], 400);
        }

        $updateStmt = db()->prepare("UPDATE attendances SET status = 'auto_checkout', notes = CONCAT(COALESCE(notes,''), ' | تم إلغاء اعتماد العمل الإضافي') WHERE id = ?");
        $updateStmt->execute([$attendanceId]);

        auditLog('overtime_revert', "إلغاء اعتماد عمل إضافي: سجل #{$attendanceId} - {$record['employee_name']} - {$record['attendance_date']}", $attendanceId);

        jsonResponse([
            'success'    => true,
            'message'    => "تم إلغاء اعتماد العمل الإضافي لـ {$record['employee_name']}",
            'new_status' => 'auto_checkout',
            'new_csrf'   => $_SESSION['csrf_token'] ?? ''
        ]);
    }

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'خطأ في النظام: ' . $e->getMessage()], 500);
}
