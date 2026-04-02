<?php
/**
 * ================================================================
 * cron/auto-checkin.php - Auto Check-in for Exempted Employees
 * ================================================================
 * يسجل حضور تلقائي للموظفين المستثنين بوقت عشوائي ضمن النطاق المحدد
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
$dayOfWeek = (int)date('w'); // 0=Sunday

// تخطي الجمعة (5) والسبت (6) إذا أردت — يمكن تعديلها
// if (in_array($dayOfWeek, [5, 6])) exit;

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
            continue; // ليس وقته بعد أو فات وقته
        }

        // تحقق: هل تم التسجيل اليوم بالفعل؟
        $existsStmt = db()->prepare("
            SELECT id FROM attendances
            WHERE employee_id = ? AND attendance_date = ? AND type = 'in'
            LIMIT 1
        ");
        $existsStmt->execute([$rule['emp_id'], $today]);
        if ($existsStmt->fetch()) {
            $skipped++;
            continue; // تم التسجيل بالفعل
        }

        // توليد وقت عشوائي بين auto_time_from و auto_time_to
        $fromTs = strtotime($today . ' ' . $rule['auto_time_from']);
        $toTs   = strtotime($today . ' ' . $rule['auto_time_to']);
        $randomTs = random_int($fromTs, max($fromTs, $toTs));
        $randomTime = date('H:i:s', $randomTs);
        $timestamp = $today . ' ' . $randomTime;

        // حساب التبكير
        $schedule = getBranchSchedule($rule['branch_id']);
        $workStart = strtotime($today . ' ' . $schedule['work_start_time']);
        $earlyMinutes = ($randomTs < $workStart) ? max(0, (int)round(($workStart - $randomTs) / 60)) : 0;
        $graceMinutes = (int) getSystemSetting('late_grace_minutes', '0');
        $lateMinutes = ($randomTs > $workStart) ? max(0, (int)round(($randomTs - $workStart) / 60) - $graceMinutes) : 0;

        // إحداثيات الفرع (كأن الموظف سجل من المركز)
        $branchLat = 0; $branchLon = 0;
        if ($rule['branch_id']) {
            $brSt = db()->prepare("SELECT latitude, longitude FROM branches WHERE id = ?");
            $brSt->execute([$rule['branch_id']]);
            $br = $brSt->fetch();
            if ($br) { $branchLat = $br['latitude']; $branchLon = $br['longitude']; }
        }

        // تسجيل الحضور
        db()->prepare("
            INSERT INTO attendances (employee_id, type, timestamp, attendance_date, late_minutes, early_minutes, latitude, longitude, location_accuracy, ip_address, user_agent, notes)
            VALUES (?, 'in', ?, ?, ?, ?, ?, ?, 0, '127.0.0.1', 'auto-checkin-cron', 'تسجيل تلقائي')
        ")->execute([$rule['emp_id'], $timestamp, $today, $lateMinutes, $earlyMinutes, $branchLat, $branchLon]);

        $autoCheckins++;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo date('Y-m-d H:i:s') . " - Auto check-in done: {$autoCheckins} checked in, {$skipped} skipped (already registered)\n";
