<?php
// =============================================================
// api/check-in.php - API تسجيل الدخول
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

header('Content-Type: application/json; charset=utf-8');

// Rate Limiting: 30 طلب/دقيقة لكل IP
if (isRateLimited(30, 60, 'checkin')) { rateLimitResponse(); }
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'طريقة طلب غير مسموحة'], 405);
}

// قراءة جسم الطلب JSON
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    jsonResponse(['success' => false, 'message' => 'بيانات غير صالحة'], 400);
}

$token    = trim($body['token']    ?? '');
$lat      = (float) ($body['latitude']  ?? 0);
$lon      = (float) ($body['longitude'] ?? 0);
$accuracy = (float) ($body['accuracy']  ?? 0);

// التحقق من البيانات
if (empty($token)) {
    jsonResponse(['success' => false, 'message' => 'الرمز المميز مطلوب'], 400);
}
if ($lat === 0.0 && $lon === 0.0) {
    jsonResponse(['success' => false, 'message' => 'بيانات الموقع غير صالحة'], 400);
}

// التحقق من صحة الـ token
$employee = getEmployeeByToken($token);
if (!$employee) {
    jsonResponse(['success' => false, 'message' => 'رمز غير صالح أو الموظف غير مفعّل'], 403);
}

// التحقق من أن التسجيل ضمن نافذة الوردية (قبل بدء الوردية بساعة حتى نهايتها)
$schedule       = getBranchSchedule($employee['branch_id'] ?? null);
$workStart      = $schedule['work_start_time'];
$workEnd        = $schedule['work_end_time'];
$nowTime        = date('H:i');

// بداية النافذة = shift_start - 90 دقيقة (ساعة ونصف)
$earlyStart = date('H:i', strtotime($workStart) - 5400);

// التحقق: من earlyStart إلى workEnd
if ($workEnd < $earlyStart) {
    // يعبر منتصف الليل
    $outsideWindow = ($nowTime < $earlyStart && $nowTime > $workEnd);
} else {
    $outsideWindow = ($nowTime < $earlyStart || $nowTime > $workEnd);
}

if ($outsideWindow) {
    jsonResponse([
        'success' => false,
        'message' => "تسجيل الحضور متاح من {$earlyStart} إلى {$workEnd}. الوقت الحالي: {$nowTime}"
    ], 200);
}

// التحقق من النطاق الجغرافي (باستخدام فرع الموظف إن وجد)
// تخطي التحقق إذا كان الموظف معفى من شرط الموقع
if (!empty($employee['bypass_geofence'])) {
    $geoCheck = ['allowed' => true, 'distance' => 0, 'radius' => 0, 'message' => 'معفى من شرط الموقع'];
} else {
    $geoCheck = isWithinGeofence($lat, $lon, $employee['branch_id'] ?? null);
    if (!$geoCheck['allowed']) {
        jsonResponse([
            'success'  => false,
            'message'  => "⛔ لا يمكن تسجيل الحضور من خارج نطاق العمل.\n\n📍 المسافة الحالية: {$geoCheck['distance']} متر\n📏 الحد المسموح: {$geoCheck['radius']} متر\n\nيرجى التوجه إلى مقر العمل والمحاولة مجدداً.",
            'distance' => $geoCheck['distance'],
            'radius'   => $geoCheck['radius']
        ], 200);
    }
}

// تسجيل الدخول
$result = recordAttendance($employee['id'], 'in', $lat, $lon, $accuracy);

jsonResponse(array_merge($result, [
    'employee_name' => $employee['name'],
    'timestamp'     => date('Y-m-d H:i:s'),
    'distance'      => $geoCheck['distance'] ?? 0
]));
