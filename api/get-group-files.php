<?php
// api/get-group-files.php — يعيد ملفات مجموعة وثائق واحدة (للمدير فقط)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();

header('Content-Type: application/json; charset=utf-8');

$groupId = (int)($_GET['group_id'] ?? 0);
if ($groupId <= 0) {
    echo json_encode(['success' => false, 'message' => 'group_id مطلوب']);
    exit;
}

try {
    $files = db()->prepare("SELECT id, file_path, file_type, original_name, file_size FROM emp_document_files WHERE group_id = ? ORDER BY sort_order, id");
    $files->execute([$groupId]);
    echo json_encode(['success' => true, 'files' => $files->fetchAll()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات']);
}
