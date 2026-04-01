<?php
/**
 * ================================================================
 * cron/auto-checkout.php - Auto Check-out at end time
 * ================================================================
 * يسجل انصراف تلقائي للموظفين الذين لم يسجلوا انصراف عند وصول وقت نهاية
 * الانصراف (check_out_end_time)
 * 
 * التشغيل: كل دقيقة عبر cron
 * مثال: * * * * * /usr/bin/php /path/to/cron/auto-checkout.php
 * ================================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// لو غير CLI، منع التشغيل إلا برمز سري من متغير البيئة
$cronSecret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (php_sapi_name() !== 'cli') {
    if (empty($cronSecret) || !isset($_GET['secret']) || !hash_equals($cronSecret, $_GET['secret'])) {
        http_response_code(403);
        exit('Access denied');
    }
}

$now = new DateTime();
$currentTime = $now->format('H:i:s');
$today = $now->format('Y-m-d');

// ================================================================
// 1. AUTO CHECK-OUT للموظفين الذين لم يسجلوا انصراف
// ================================================================
try {
    // جلب جميع الموظفين الذين لديهم check-in اليوم أو أمس بدون check-out
    // (أمس لأن الوردية الثانية تتجاوز منتصف الليل)
    $stmt = db()->prepare("
        SELECT DISTINCT
            e.id AS employee_id,
            e.name,
            e.branch_id,
            ci.id AS checkin_id,
            ci.timestamp AS checkin_time,
            ci.attendance_date,
            ci.latitude,
            ci.longitude
        FROM employees e
        INNER JOIN attendances ci ON e.id = ci.employee_id
        WHERE ci.type = 'in'
          AND ci.attendance_date IN (CURDATE(), DATE_SUB(CURDATE(), INTERVAL 1 DAY))
          AND e.is_active = 1
          AND e.deleted_at IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM attendances co
              WHERE co.employee_id = e.id
                AND co.type = 'out'
                AND co.attendance_date = ci.attendance_date
          )
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll();

    $autoCheckouts = 0;
    $skipped = 0;

    db()->beginTransaction();
    try {
        foreach ($employees as $emp) {
            try {
                // جلب جدول الفرع (مع الورديات)
                $schedule = getBranchSchedule($emp['branch_id']);
                $shifts   = $schedule['shifts'] ?? [];

                // تحديد الوردية المناسبة بناءً على وقت تسجيل الدخول
                $checkinMin = timeToMinutes(date('H:i', strtotime($emp['checkin_time'])));
                $coEnd = $schedule['work_end_time']; // افتراضي: نهاية الوردية النشطة
                $empShift = 1;

                foreach ($shifts as $shift) {
                    $shiftStart  = timeToMinutes($shift['shift_start']);
                    $shiftEnd    = timeToMinutes($shift['shift_end']);
                    $earlyWindow = ($shiftStart - 90 + 1440) % 1440; // 90 دقيقة قبل البداية

                    if ($shiftEnd < $earlyWindow) { // يعبر منتصف الليل
                        if ($checkinMin >= $earlyWindow || $checkinMin <= $shiftEnd) {
                            $coEnd    = $shift['shift_end'];
                            $empShift = (int)$shift['shift_number'];
                            break;
                        }
                    } else {
                        if ($checkinMin >= $earlyWindow && $checkinMin <= $shiftEnd) {
                            $coEnd    = $shift['shift_end'];
                            $empShift = (int)$shift['shift_number'];
                            break;
                        }
                    }
                }

            // بناء الوقت المتوقع للانصراف التلقائي
            $expectedCheckout = new DateTime($emp['attendance_date'] . ' ' . $coEnd);
            $checkInDT = new DateTime($emp['checkin_time']);

            // إذا وقت الانصراف قبل وقت الدخول → يعني تجاوز منتصف الليل
            if ($expectedCheckout <= $checkInDT) {
                $expectedCheckout->modify('+1 day');
            }

            // إذا الوقت الحالي >= وقت الانصراف المتوقع
            if ($now >= $expectedCheckout) {
                // تسجيل انصراف تلقائي
                $insertStmt = db()->prepare("
                    INSERT INTO attendances (
                        employee_id, type, timestamp, attendance_date,
                        latitude, longitude, location_accuracy,
                        ip_address, user_agent, notes
                    ) VALUES (
                        :emp_id, 'out', NOW(), :att_date,
                        :lat, :lon, 0,
                        'AUTO', 'AUTO-CHECKOUT-CRON', 'انصراف تلقائي - لم يسجل الموظف'
                    )
                ");
                $insertStmt->execute([
                    'emp_id'   => $emp['employee_id'],
                    'att_date' => $emp['attendance_date'],
                    'lat'      => $emp['latitude'],
                    'lon'      => $emp['longitude']
                ]);
                $autoCheckouts++;
                echo "[{$now->format('Y-m-d H:i:s')}] ✅ AUTO CHECK-OUT (Shift {$empShift}): {$emp['name']} (ID {$emp['employee_id']})\n";
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            echo "[{$now->format('Y-m-d H:i:s')}] ❌ خطأ في تسجيل انصراف {$emp['name']}: " . $e->getMessage() . "\n";
        }
    }
    db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        echo "[{$now->format('Y-m-d H:i:s')}] ❌ خطأ في العملية: " . $e->getMessage() . "\n";
    }

    echo "[{$now->format('Y-m-d H:i:s')}] 📊 إجمالي: {$autoCheckouts} انصراف تلقائي، {$skipped} تم تخطيهم (لم يحن الوقت)\n";

} catch (Exception $e) {
    echo "[{$now->format('Y-m-d H:i:s')}] ❌ خطأ في AUTO CHECK-OUT: " . $e->getMessage() . "\n";
}

// ================================================================
// 2. AUTO-END OVERTIME للموظفين الذين لم ينهوا الدوام الإضافي حتى 3 فجراً
// ================================================================
try {
    // إذا الوقت الحالي = 03:00 (بدقة ±1 دقيقة)
    $hour = (int)$now->format('H');
    $minute = (int)$now->format('i');

    if ($hour === 3 && $minute <= 1) {
        // جلب جميع sessions الدوام الإضافي المفتوحة من الأمس
        $yesterday = (new DateTime())->modify('-1 day')->format('Y-m-d');

        $stmt = db()->prepare("
            SELECT DISTINCT
                e.id AS employee_id,
                e.name,
                ot_start.id AS ot_start_id,
                ot_start.timestamp AS ot_start_time
            FROM employees e
            INNER JOIN attendances ot_start ON e.id = ot_start.employee_id
            WHERE ot_start.type = 'overtime-start'
              AND ot_start.attendance_date = :yesterday
              AND e.is_active = 1
              AND e.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM attendances ot_end
                  WHERE ot_end.employee_id = e.id
                    AND ot_end.type = 'overtime-end'
                    AND ot_end.timestamp > ot_start.timestamp
              )
        ");
        $stmt->execute(['yesterday' => $yesterday]);
        $overtimeSessions = $stmt->fetchAll();

        $autoEnds = 0;

        foreach ($overtimeSessions as $session) {
            // إنهاء الدوام الإضافي تلقائياً
            $insertStmt = db()->prepare("
                INSERT INTO attendances (
                    employee_id, type, timestamp, attendance_date,
                    latitude, longitude, location_accuracy,
                    ip_address, user_agent, notes
                ) VALUES (
                    :emp_id, 'overtime-end', NOW(), CURDATE(),
                    0, 0, 0,
                    'AUTO', 'AUTO-END-OVERTIME-CRON', 'إنهاء دوام إضافي تلقائي - 3 فجراً'
                )
            ");
            $insertStmt->execute(['emp_id' => $session['employee_id']]);
            $autoEnds++;
            echo "[{$now->format('Y-m-d H:i:s')}] ⏰ AUTO-END OVERTIME: {$session['name']} (ID {$session['employee_id']})\n";
        }

        if ($autoEnds > 0) {
            echo "[{$now->format('Y-m-d H:i:s')}] 📊 إجمالي: {$autoEnds} إنهاء دوام إضافي تلقائي\n";
        }
    }

} catch (Exception $e) {
    echo "[{$now->format('Y-m-d H:i:s')}] ❌ خطأ في AUTO-END OVERTIME: " . $e->getMessage() . "\n";
}

echo "[{$now->format('Y-m-d H:i:s')}] ✅ Cron job completed\n";
