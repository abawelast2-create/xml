<?php
// =============================================================
// includes/auth.php - مصادقة المديرين
// =============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * التحقق من تسجيل دخول المدير، وإعادة توجيهه إذا لم يكن مسجلاً
 */
function requireAdminLogin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . SITE_URL . '/admin/login.php');
        exit;
    }
}

/**
 * محاولة تسجيل دخول مدير
 */
function adminLogin(string $username, string $password): array {
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $admin = $stmt->fetch();

    // دائماً نستدعي password_verify لمنع Timing Attacks (حتى لو المستخدم غير موجود)
    $dummyHash = '$2y$10$dummyhashtopreventtimingattacksXXXXXXXXXXXXXXXXXXXXXXX';
    $hashToCheck = $admin ? $admin['password_hash'] : $dummyHash;
    $passwordValid = password_verify($password, $hashToCheck);

    if (!$admin || !$passwordValid) {
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }

    // تجديد معرف الجلسة لأمان أفضل
    session_regenerate_id(true);

    $_SESSION['admin_id']       = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_name']     = $admin['full_name'];

    // تذكرني — تمديد الجلسة لـ 30 يوم
    if (!empty($_POST['remember_me'])) {
        $ttl = 2592000; // 30 يوم
        setcookie('remember_admin', '1', time() + $ttl, '/', '', true, true);
        ini_set('session.cookie_lifetime', $ttl);
        ini_set('session.gc_maxlifetime', $ttl);
        session_set_cookie_params($ttl);
    }

    // تحديث آخر تسجيل دخول
    db()->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);

    return ['success' => true, 'message' => 'تم تسجيل الدخول بنجاح'];
}

/**
 * تسجيل خروج المدير
 */
function adminLogout(): void {
    $_SESSION = [];
    // مسح كوكي تذكرني
    setcookie('remember_admin', '', time() - 3600, '/', '', true, true);
    session_destroy();
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

/**
 * هل المدير مسجل دخوله؟
 */
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}

// =============================================================
// Auto-checkout trigger (piggyback) — يعمل مرة كل دقيقة
// عند فتح أي صفحة إدارة، يتحقق ويسجل انصراف تلقائي
// =============================================================
function triggerAutoCheckout(): void {
    $lockFile = sys_get_temp_dir() . '/sarh_auto_checkout.lock';

    if (file_exists($lockFile)) {
        $lastRun = (int) file_get_contents($lockFile);
        if (time() - $lastRun < 60) {
            return;
        }
    }

    file_put_contents($lockFile, (string) time());

    try {
        $now = new DateTime();
        $graceMinutes = (int) getSystemSetting('auto_checkout_grace_minutes', '15');

        $stmt = db()->prepare("
            SELECT
                ci.id AS checkin_id,
                ci.employee_id,
                ci.timestamp AS checkin_time,
                ci.attendance_date,
                ci.latitude,
                ci.longitude,
                ci.shift_id AS existing_shift_id,
                e.branch_id
            FROM attendances ci
            INNER JOIN employees e ON ci.employee_id = e.id
            WHERE ci.type = 'in'
              AND ci.attendance_date IN (CURDATE(), DATE_SUB(CURDATE(), INTERVAL 1 DAY))
              AND e.is_active = 1
              AND e.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM attendances co
                  WHERE co.employee_id = ci.employee_id
                    AND co.type = 'out'
                    AND co.attendance_date = ci.attendance_date
                    AND co.timestamp > ci.timestamp
              )
            ORDER BY ci.employee_id, ci.timestamp
        ");
        $stmt->execute();
        $checkins = $stmt->fetchAll();

        foreach ($checkins as $ci) {
            $shiftStmt = db()->prepare("SELECT id, shift_number, shift_start, shift_end FROM branch_shifts WHERE branch_id = ? AND is_active = 1 ORDER BY shift_number");
            $shiftStmt->execute([$ci['branch_id']]);
            $shifts = $shiftStmt->fetchAll();

            if (empty($shifts)) {
                $shifts = [['id' => null, 'shift_number' => 1, 'shift_start' => getSystemSetting('work_start_time', '08:00'), 'shift_end' => getSystemSetting('work_end_time', '16:00')]];
            }

            $checkinTime = date('H:i', strtotime($ci['checkin_time']));
            $shiftNum = assignTimeToShift($checkinTime, $shifts);

            $matchedShift = null;
            foreach ($shifts as $s) {
                if ((int)$s['shift_number'] === $shiftNum) { $matchedShift = $s; break; }
            }
            if (!$matchedShift) $matchedShift = $shifts[0];

            $shiftId = $ci['existing_shift_id'] ?? ($matchedShift['id'] ?? null);

            $expectedCheckout = new DateTime($ci['attendance_date'] . ' ' . $matchedShift['shift_end']);
            $checkInDT = new DateTime($ci['checkin_time']);

            if ($expectedCheckout <= $checkInDT) {
                $expectedCheckout->modify('+1 day');
            }

            $triggerTime = clone $expectedCheckout;
            $triggerTime->modify("+{$graceMinutes} minutes");

            if ($now >= $triggerTime) {
                // Double-entry guard
                $guardStmt = db()->prepare("SELECT id FROM attendances WHERE employee_id = ? AND type = 'out' AND attendance_date = ? AND timestamp > ? LIMIT 1");
                $guardStmt->execute([$ci['employee_id'], $ci['attendance_date'], $ci['checkin_time']]);
                if ($guardStmt->fetch()) continue;

                $checkoutTimestamp = $expectedCheckout->format('Y-m-d H:i:s');
                $insertStmt = db()->prepare("
                    INSERT INTO attendances (
                        employee_id, type, timestamp, attendance_date,
                        latitude, longitude, location_accuracy,
                        ip_address, user_agent, notes,
                        status, shift_id, closed_by_checkin_id
                    ) VALUES (
                        :emp_id, 'out', :ts, :att_date,
                        :lat, :lon, 0,
                        'AUTO', 'AUTO-CHECKOUT', :notes,
                        'auto_checkout', :shift_id, :ci_id
                    )
                ");
                $insertStmt->execute([
                    'emp_id'   => $ci['employee_id'],
                    'ts'       => $checkoutTimestamp,
                    'att_date' => $ci['attendance_date'],
                    'lat'      => $ci['latitude'],
                    'lon'      => $ci['longitude'],
                    'notes'    => "انصراف تلقائي - وردية {$shiftNum}",
                    'shift_id' => $shiftId,
                    'ci_id'    => $ci['checkin_id']
                ]);
            }
        }
    } catch (Exception $e) {
        // صامت — لا نعطل صفحة الإدارة
    }
}

// تشغيل تلقائي عند تحميل الصفحة (فقط للمديرين المسجلين)
if (isAdminLoggedIn()) {
    triggerAutoCheckout();
}
