<?php
// =============================================================
// api/serve-file.php — عرض ملفات البروفايل بشكل آمن
// =============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$relPath = trim($_GET['f'] ?? '');

// منع path traversal — يجب أن يبدأ بـ profiles/رقم/
if (
    empty($relPath) ||
    str_contains($relPath, '..') ||
    str_contains($relPath, "\0") ||
    !preg_match('#^profiles/\d+/#', $relPath)
) {
    http_response_code(403); exit;
}

// المصادقة: مدير أو موظف صاحب الملف
$authorized = false;

if (isAdminLoggedIn()) {
    $authorized = true;
} elseif (!empty($_GET['t'])) {
    $token = trim($_GET['t']);
    $emp   = getEmployeeByToken($token);
    if ($emp) {
        $empId = (int)$emp['id'];
        if (preg_match('#^profiles/' . $empId . '/#', $relPath)) {
            $authorized = true;
        }
    }
}

if (!$authorized) { http_response_code(403); exit; }

$fullPath = dirname(__DIR__) . '/storage/uploads/' . $relPath;
if (!file_exists($fullPath) || !is_file($fullPath)) { http_response_code(404); exit; }

// التحقق من نوع MIME الفعلي (ليس الامتداد)
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mime     = $finfo->file($fullPath);
$safe     = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
if (!in_array($mime, $safe, true)) { http_response_code(403); exit; }

// منع تنفيذ محتوى html/js من داخل PDF
if ($mime === 'application/pdf') {
    header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=7200');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
