<?php
// =============================================================
// api/upload-profile.php — رفع صورة بروفايل أو وثيقة موظف
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
    // أعد توليد token لأن verifyCsrfToken يمسحه حتى عند الفشل (null check)
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، أعد تحميل الصفحة']); exit;
}
// بعد التحقق الناجح، أعد توليد token جديد
$newCsrf = generateCsrfToken();

$action = trim($_POST['action'] ?? '');
$empId  = (int)($_POST['employee_id'] ?? 0);

if (!$empId) {
    echo json_encode(['success' => false, 'message' => 'employee_id مطلوب', 'csrf_token' => $newCsrf]); exit;
}

// التحقق من وجود الموظف
$empCheck = db()->prepare("SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL");
$empCheck->execute([$empId]);
if (!$empCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'الموظف غير موجود', 'csrf_token' => $newCsrf]); exit;
}

$upRoot = dirname(__DIR__) . '/storage/uploads/profiles/' . $empId . '/';

// ── صورة البروفايل ──────────────────────────────────────────────────
if ($action === 'photo') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'لم يُستلم الملف', 'csrf_token' => $newCsrf]); exit;
    }
    $file  = $_FILES['file'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        echo json_encode(['success' => false, 'message' => 'صيغة غير مدعومة — jpg/png/webp فقط', 'csrf_token' => $newCsrf]); exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'الحجم أكبر من 5 ميجا', 'csrf_token' => $newCsrf]); exit;
    }
    @mkdir($upRoot, 0755, true);
    // حذف الصور القديمة
    foreach (glob($upRoot . 'photo.*') as $old) @unlink($old);

    $ext      = match($mime) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
    $filename = 'photo.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upRoot . $filename)) {
        echo json_encode(['success' => false, 'message' => 'فشل حفظ الملف', 'csrf_token' => $newCsrf]); exit;
    }
    $relPath = 'profiles/' . $empId . '/' . $filename;
    db()->prepare("UPDATE employees SET profile_photo = ? WHERE id = ?")->execute([$relPath, $empId]);
    echo json_encode(['success' => true, 'path' => $relPath, 'csrf_token' => $newCsrf]);
    exit;
}

// ── وثيقة داخل مجموعة ───────────────────────────────────────────────
if ($action === 'document') {
    $groupId = (int)($_POST['group_id'] ?? 0);
    if (!$groupId) {
        echo json_encode(['success' => false, 'message' => 'group_id مطلوب', 'csrf_token' => $newCsrf]); exit;
    }
    // التحقق من أن المجموعة تخص هذا الموظف
    $gCheck = db()->prepare("SELECT id FROM emp_document_groups WHERE id = ? AND employee_id = ?");
    $gCheck->execute([$groupId, $empId]);
    if (!$gCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'المجموعة غير موجودة', 'csrf_token' => $newCsrf]); exit;
    }
    // الحد الأقصى 10 ملفات لكل مجموعة
    $cntStmt = db()->prepare("SELECT COUNT(*) FROM emp_document_files WHERE group_id = ?");
    $cntStmt->execute([$groupId]);
    if ($cntStmt->fetchColumn() >= 10) {
        echo json_encode(['success' => false, 'message' => 'الحد الأقصى 10 ملفات لكل مجموعة', 'csrf_token' => $newCsrf]); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'لم يُستلم الملف', 'csrf_token' => $newCsrf]); exit;
    }
    $file  = $_FILES['file'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    if (!in_array($mime, $allowedMimes, true)) {
        echo json_encode(['success' => false, 'message' => 'صيغة غير مدعومة — صور أو PDF فقط', 'csrf_token' => $newCsrf]); exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'الحجم أكبر من 10 ميجا', 'csrf_token' => $newCsrf]); exit;
    }
    $docDir = $upRoot . 'docs/' . $groupId . '/';
    @mkdir($docDir, 0755, true);
    $ext      = match($mime) { 'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $docDir . $filename)) {
        echo json_encode(['success' => false, 'message' => 'فشل حفظ الملف', 'csrf_token' => $newCsrf]); exit;
    }
    $relPath  = 'profiles/' . $empId . '/docs/' . $groupId . '/' . $filename;
    $fileType = ($mime === 'application/pdf') ? 'pdf' : 'image';
    $origName = preg_replace('/[^a-zA-Z0-9._\-\x{0600}-\x{06FF} ]/u', '', basename($file['name']));
    db()->prepare("INSERT INTO emp_document_files (group_id, file_path, file_type, original_name, file_size) VALUES (?,?,?,?,?)")
        ->execute([$groupId, $relPath, $fileType, $origName, $file['size']]);
    $newDocId = (int)db()->lastInsertId();
    echo json_encode([
        'success'    => true,
        'id'         => $newDocId,
        'path'       => $relPath,
        'type'       => $fileType,
        'csrf_token' => $newCsrf
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'action غير معروف', 'csrf_token' => $newCsrf]);
