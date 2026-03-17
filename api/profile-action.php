<?php
// =============================================================
// api/profile-action.php — إدارة مجموعات الوثائق (CRUD)
// =============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdminLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']); exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، أعد تحميل الصفحة']); exit;
}
$newCsrf = generateCsrfToken();

$action = trim($_POST['action'] ?? '');

// ── إضافة مجموعة جديدة ──────────────────────────────────────────────
if ($action === 'add_group') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    if (!$empId) {
        echo json_encode(['success' => false, 'message' => 'employee_id مطلوب', 'csrf_token' => $newCsrf]); exit;
    }
    // التحقق من وجود الموظف
    $e = db()->prepare("SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL");
    $e->execute([$empId]);
    if (!$e->fetch()) {
        echo json_encode(['success' => false, 'message' => 'الموظف غير موجود', 'csrf_token' => $newCsrf]); exit;
    }
    // الحد الأقصى 10 مجموعات
    $cntS = db()->prepare("SELECT COUNT(*) FROM emp_document_groups WHERE employee_id = ?");
    $cntS->execute([$empId]);
    if ($cntS->fetchColumn() >= 10) {
        echo json_encode(['success' => false, 'message' => 'الحد الأقصى 10 مجموعات', 'csrf_token' => $newCsrf]); exit;
    }
    // تاريخ انتهاء افتراضي: سنة من الآن
    $defaultExpiry = date('Y-m-d', strtotime('+1 year'));
    $ins = db()->prepare("INSERT INTO emp_document_groups (employee_id, group_name, expiry_date, sort_order) VALUES (?,?,?,?)");
    $ins->execute([$empId, '', $defaultExpiry, 0]);
    $newId    = (int)db()->lastInsertId();
    $daysLeft = (int)(new DateTime())->diff(new DateTime($defaultExpiry))->days;
    echo json_encode([
        'success'    => true,
        'csrf_token' => $newCsrf,
        'group'      => [
            'id'          => $newId,
            'group_name'  => '',
            'expiry_date' => $defaultExpiry,
            'days_left'   => $daysLeft,
            'file_count'  => 0,
            'files'       => [],
        ]
    ]);
    exit;
}

// ── حفظ اسم/تاريخ مجموعة ────────────────────────────────────────────
if ($action === 'save_group') {
    $groupId    = (int)($_POST['group_id'] ?? 0);
    $empId      = (int)($_POST['employee_id'] ?? 0);
    $groupName  = sanitize($_POST['group_name'] ?? '');
    $expiryDate = trim($_POST['expiry_date'] ?? '');

    if (!$groupId || !$empId) {
        echo json_encode(['success' => false, 'message' => 'بيانات ناقصة', 'csrf_token' => $newCsrf]); exit;
    }
    // التحقق من أن التاريخ صالح
    $d = DateTime::createFromFormat('Y-m-d', $expiryDate);
    if (!$d || $d->format('Y-m-d') !== $expiryDate) {
        echo json_encode(['success' => false, 'message' => 'تاريخ انتهاء غير صالح', 'csrf_token' => $newCsrf]); exit;
    }
    // التحقق من ملكية المجموعة
    $g = db()->prepare("SELECT id FROM emp_document_groups WHERE id = ? AND employee_id = ?");
    $g->execute([$groupId, $empId]);
    if (!$g->fetch()) {
        echo json_encode(['success' => false, 'message' => 'المجموعة غير موجودة', 'csrf_token' => $newCsrf]); exit;
    }
    db()->prepare("UPDATE emp_document_groups SET group_name = ?, expiry_date = ? WHERE id = ?")
        ->execute([$groupName, $expiryDate, $groupId]);

    $today    = new DateTime();
    $expiry   = new DateTime($expiryDate);
    $diff     = $today->diff($expiry);
    $daysLeft = (int)$expiry->format('U') >= (int)$today->format('U') ? $diff->days : -$diff->days;

    echo json_encode(['success' => true, 'days_left' => $daysLeft, 'csrf_token' => $newCsrf]);
    exit;
}

// ── حذف مجموعة وكل وثائقها ──────────────────────────────────────────
if ($action === 'delete_group') {
    $groupId = (int)($_POST['group_id'] ?? 0);
    $empId   = (int)($_POST['employee_id'] ?? 0);
    if (!$groupId || !$empId) {
        echo json_encode(['success' => false, 'message' => 'بيانات ناقصة', 'csrf_token' => $newCsrf]); exit;
    }
    // التحقق من الملكية
    $g = db()->prepare("SELECT id FROM emp_document_groups WHERE id = ? AND employee_id = ?");
    $g->execute([$groupId, $empId]);
    if (!$g->fetch()) {
        echo json_encode(['success' => false, 'message' => 'المجموعة غير موجودة', 'csrf_token' => $newCsrf]); exit;
    }
    // حذف الملفات الفعلية من السيرفر
    $files = db()->prepare("SELECT file_path FROM emp_document_files WHERE group_id = ?");
    $files->execute([$groupId]);
    foreach ($files->fetchAll() as $f) {
        $fullPath = dirname(__DIR__) . '/storage/uploads/' . $f['file_path'];
        if (file_exists($fullPath)) @unlink($fullPath);
    }
    // حذف مجلد المجموعة إن أصبح فارغاً
    $docDir = dirname(__DIR__) . '/storage/uploads/profiles/' . $empId . '/docs/' . $groupId;
    if (is_dir($docDir)) @rmdir($docDir);

    db()->prepare("DELETE FROM emp_document_files WHERE group_id = ?")->execute([$groupId]);
    db()->prepare("DELETE FROM emp_document_groups WHERE id = ?")->execute([$groupId]);
    echo json_encode(['success' => true, 'csrf_token' => $newCsrf]);
    exit;
}

// ── حذف وثيقة واحدة ────────────────────────────────────────────────
if ($action === 'delete_document') {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $empId = (int)($_POST['employee_id'] ?? 0);
    if (!$docId || !$empId) {
        echo json_encode(['success' => false, 'message' => 'بيانات ناقصة', 'csrf_token' => $newCsrf]); exit;
    }
    // التحقق من الملكية عبر JOIN
    $d = db()->prepare("
        SELECT f.id, f.file_path
        FROM emp_document_files f
        JOIN emp_document_groups g ON f.group_id = g.id
        WHERE f.id = ? AND g.employee_id = ?
    ");
    $d->execute([$docId, $empId]);
    $doc = $d->fetch();
    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'الوثيقة غير موجودة', 'csrf_token' => $newCsrf]); exit;
    }
    $fullPath = dirname(__DIR__) . '/storage/uploads/' . $doc['file_path'];
    if (file_exists($fullPath)) @unlink($fullPath);
    db()->prepare("DELETE FROM emp_document_files WHERE id = ?")->execute([$docId]);
    echo json_encode(['success' => true, 'csrf_token' => $newCsrf]);
    exit;
}

// ── حذف صورة البروفايل ───────────────────────────────────────────────
if ($action === 'delete_photo') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    if (!$empId) {
        echo json_encode(['success' => false, 'message' => 'employee_id مطلوب', 'csrf_token' => $newCsrf]); exit;
    }
    $e = db()->prepare("SELECT profile_photo FROM employees WHERE id = ? AND deleted_at IS NULL");
    $e->execute([$empId]);
    $emp = $e->fetch();
    if ($emp && $emp['profile_photo']) {
        $fp = dirname(__DIR__) . '/storage/uploads/' . $emp['profile_photo'];
        if (file_exists($fp)) @unlink($fp);
        db()->prepare("UPDATE employees SET profile_photo = NULL WHERE id = ?")->execute([$empId]);
    }
    echo json_encode(['success' => true, 'csrf_token' => $newCsrf]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'action غير معروف', 'csrf_token' => $newCsrf]);
