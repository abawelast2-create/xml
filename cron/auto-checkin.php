<?php
/**
 * ================================================================
 * cron/auto-checkin.php - Auto Check-in for Exempted Employees
 * ================================================================
 * يسجل حضور تلقائي للموظفين المستثنين بوقت عشوائي ضمن النطاق المحدد
 * يدعم الورديات المتعددة: يحدد الوردية النشطة ويتحقق من التكرار لها فقط
 * 
 * التشغيل: كل دقيقة عبر cron
 * مثال: * * * * * /usr/bin/php /path/to/cron/auto-checkin.php
 * ================================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// لو غير CLI، منع التشغيل إلا برمز سري
$cronSecret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (php_sapi_name() !== 'cli') {
    if (empty($cronSecret) || !isset($_GET['secret']) || !hash_equals($cronSecret, $_GET['secret'])) {
        http_response_code(403);
        exit('Access denied');
    }
}

$today = date('Y-m-d');
$nowTime = date('H:i:s');
$nowMin = (int)date('H') * 60 + (int)date('i');

$autoCheckins = 0;
$skipped = 0;

try {
    // جلب جميع القواعد النشطة مع بيانات الموظف
    $stmt = db()->prepare("
        SELECT r.*, e.id AS emp_id, e.name, e.branch_id
        FROM auto_attendance_rules r
        INNER JOIN employees e ON r.employee_id = e.id
        WHERE r.is_active = 1
          AND e.is_active = 1
          AND e.deleted_at IS NULL
    ");
    $stmt->execute();
    $rules = $stmt->fetchAll();

    foreach ($rules as $rule) {
        // تحقق: هل الوقت الحالي ضمن النطاق؟
        if ($nowTime < $rule['auto_time_from'] || $nowTime > $rule['auto_time_to']) {
            continue;
        }

        // جلب ورديات الفرع
        $shiftStmt = db()->prepare("SELECT shift_number, shift_start, shift_end FROM branch_shifts WHERE branch_id = ? AND is_active = 1 ORDER BY shift_number");
        $shiftStmt->execute([$rule['branch_id']]);
        $branchShifts = $shiftStmt->fetchAll();

        if (empty($branchShifts)) {
            $branchShifts = [['shift_number' => 1, 'shift_start' => getSystemSetting('work_start_time', '08:00'), 'shift_end' => getSystemSetting('work_end_time', '16:00')]];
        }

        // تحديد الوردية النشطة الآن
        $activeShift = detectActiveShift($branchShifts);
        $shiftNum = (int)$activeShift['shift_number'];
        $shiftStart = $activeShift['shift_start'];
        $shiftEnd = $activeShift['shift_end'];

        // حساب نافذة الوردية (بداية - 120 دقيقة إلى نهاية + 60 دقيقة)
        $sStart = timeToMinutes($shiftStart);
        $sEnd = timeToMinutes($shiftEnd);
        $windowStart = ($sStart - 120 + 1440) % 1440;
        $windowEnd = ($sEnd + 60) % 1440;

        // تحقق: هل تم التسجيل لهذه الوردية اليوم بالفعل؟
        // نبحث عن تسجيل حضور ضمن نافذة هذه الوردية
        if ($windowStart < $windowEnd) {
            $existsStmt = db()->prepare("
                SELECT id FROM attendances
                WHERE employee_id = ? AND attendance_date = ? AND type = 'in'
                  AND (HOUR(timestamp)*60 + MINUTE(timestamp)) BETWEEN ? AND ?
                LIMIT 1
            ");
            $existsStmt->execute([$rule['emp_id'], $today, $windowStart, $windowEnd]);
        } else {
            // نافذة تعبر منتصف الليل
            $existsStmt = db()->prepare("
                SELECT id FROM attendances
                WHERE employee_id = ? AND attendance_date = ? AND type = 'in'
                  AND ((HOUR(timestamp)*60 + MINUTE(timestamp)) >= ? OR (HOUR(timestamp)*60 + MINUTE(timestamp)) <= ?)
                LIMIT 1
            ");
            $existsStmt->execute([$rule['emp_id'], $today, $windowStart, $windowEnd]);
        }

        if ($existsStmt->fetch()) {
            $skipped++;
            continue; // تم التسجيل بالفعل لهذه الوردية
        }

        // توليد وقت عشوائي بين auto_time_from و auto_time_to
        $fromTs = strtotime($today . ' ' . $rule['auto_time_from']);
        $toTs   = strtotime($today . ' ' . $rule['auto_time_to']);
        $randomTs = random_int($fromTs, max($fromTs, $toTs));
        $randomTime = date('H:i:s', $randomTs);
        $timestamp = $today . ' ' . $randomTime;

        // حساب التبكير/التأخير
        $workStart = strtotime($today . ' ' . $shiftStart);
        $earlyMinutes = ($randomTs < $workStart) ? max(0, (int)round(($workStart - $randomTs) / 60)) : 0;
        $graceMinutes = (int) getSystemSetting('late_grace_minutes', '0');
        $lateMinutes = ($randomTs > $workStart) ? max(0, (int)round(($randomTs - $workStart) / 60) - $graceMinutes) : 0;

        // إحداثيات الفرع
        $branchLat = 0; $branchLon = 0;
        if ($rule['branch_id']) {
            $brSt = db()->prepare("SELECT latitude, longitude FROM branches WHERE id = ?");
            $brSt->execute([$rule['branch_id']]);
            $br = $brSt->fetch();
            if ($br) { $branchLat = $br['latitude']; $branchLon = $br['longitude']; }
        }

        // جلب shift_id من branch_shifts
        $autoShiftId = null;
        if ($rule['branch_id']) {
            $sidStmt = db()->prepare("SELECT id FROM branch_shifts WHERE branch_id = ? AND shift_number = ? AND is_active = 1 LIMIT 1");
            $sidStmt->execute([$rule['branch_id'], $shiftNum]);
            $sidRow = $sidStmt->fetch();
            if ($sidRow) $autoShiftId = (int)$sidRow['id'];
        }

        // تسجيل الحضور
        db()->prepare("
            INSERT INTO attendances (employee_id, type, timestamp, attendance_date, late_minutes, early_minutes, latitude, longitude, location_accuracy, ip_address, user_agent, notes, status, shift_id)
            VALUES (?, 'in', ?, ?, ?, ?, ?, ?, 0, '127.0.0.1', 'auto-checkin-cron', ?, 'manual', ?)
        ")->execute([$rule['emp_id'], $timestamp, $today, $lateMinutes, $earlyMinutes, $branchLat, $branchLon, "تسجيل تلقائي - وردية {$shiftNum}", $autoShiftId]);

        $autoCheckins++;
        echo date('Y-m-d H:i:s') . " ✅ Auto check-in: {$rule['name']} (Shift {$shiftNum})\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo date('Y-m-d H:i:s') . " - Auto check-in done: {$autoCheckins} checked in, {$skipped} skipped\n";
