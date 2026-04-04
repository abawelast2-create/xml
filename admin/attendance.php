<?php
// =============================================================
// admin/attendance.php - تقارير الحضور والانصراف
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

// =================== حذف سجل ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
        exit;
    }
    $delId = (int)$_POST['delete_id'];
    if ($delId > 0) {
        $st = db()->prepare("DELETE FROM attendances WHERE id = ?");
        $st->execute([$delId]);
        auditLog('delete_attendance', "حذف سجل حضور ID={$delId}", $delId);
        echo json_encode(['success' => true, 'deleted' => $st->rowCount(), 'new_csrf' => $_SESSION['csrf_token'] ?? '']);
    } else {
        echo json_encode(['success' => false, 'message' => 'معرف غير صالح']);
    }
    exit;
}

// =================== تعديل سجل ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
        exit;
    }
    $editId      = (int)$_POST['edit_id'];
    $editType    = in_array($_POST['edit_type'] ?? '', ['in', 'out']) ? $_POST['edit_type'] : null;
    $editDate    = $_POST['edit_date'] ?? '';
    $editTime    = $_POST['edit_time'] ?? '';
    $editLate    = max(0, (int)($_POST['edit_late'] ?? 0));
    $editNotes   = trim($_POST['edit_notes'] ?? '');

    if ($editId <= 0 || !$editType || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $editDate) || !preg_match('/^\d{2}:\d{2}$/', $editTime)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $newTimestamp = $editDate . ' ' . $editTime . ':00';

    // إعادة حساب التأخير والتبكير تلقائياً عند تعديل وقت الدخول
    $editEarly = 0;
    if ($editType === 'in') {
        // جلب الموظف والفرع
        $empStmt = db()->prepare("SELECT employee_id FROM attendances WHERE id = ?");
        $empStmt->execute([$editId]);
        $attRec = $empStmt->fetch();
        if ($attRec) {
            $empBrStmt = db()->prepare("SELECT branch_id FROM employees WHERE id = ?");
            $empBrStmt->execute([$attRec['employee_id']]);
            $empBr = $empBrStmt->fetch();
            $schedule = getBranchSchedule($empBr ? ($empBr['branch_id'] ?? null) : null);
            $workStart = strtotime($editDate . ' ' . $schedule['work_start_time']);
            $checkinTime = strtotime($newTimestamp);
            $graceMinutes = (int) getSystemSetting('late_grace_minutes', '0');
            if ($checkinTime > $workStart) {
                $editLate = max(0, (int)round(($checkinTime - $workStart) / 60) - $graceMinutes);
            } else {
                $editLate = 0;
            }
            $editEarly = ($checkinTime < $workStart) ? max(0, (int)round(($workStart - $checkinTime) / 60)) : 0;
        }
    }

    $st = db()->prepare("UPDATE attendances SET type = ?, timestamp = ?, attendance_date = ?, late_minutes = ?, early_minutes = ?, notes = ? WHERE id = ?");
    $st->execute([$editType, $newTimestamp, $editDate, $editLate, $editEarly ?? 0, $editNotes ?: null, $editId]);
    auditLog('edit_attendance', "تعديل سجل حضور ID={$editId}: type={$editType}, time={$newTimestamp}, late={$editLate}", $editId);
    echo json_encode(['success' => true, 'updated' => $st->rowCount(), 'new_csrf' => $_SESSION['csrf_token'] ?? '']);
    exit;
}

// =================== جلب سجل للتعديل ===================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_id'])) {
    header('Content-Type: application/json');
    $fetchId = (int)$_GET['fetch_id'];
    if ($fetchId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف غير صالح']);
        exit;
    }
    $st = db()->prepare("SELECT id, employee_id, type, timestamp, attendance_date, late_minutes, latitude, longitude, notes FROM attendances WHERE id = ?");
    $st->execute([$fetchId]);
    $rec = $st->fetch();
    if (!$rec) {
        echo json_encode(['success' => false, 'message' => 'السجل غير موجود']);
        exit;
    }
    echo json_encode(['success' => true, 'record' => $rec]);
    exit;
}

$pageTitle  = 'تقارير الحضور';
$activePage = 'attendance';

// =================== إضافة سجل يدوي ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_add'])) {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
        exit;
    }
    $manualEmpId = (int)($_POST['manual_emp_id'] ?? 0);
    $manualName  = trim($_POST['manual_name'] ?? '');
    $manualType  = in_array($_POST['manual_type'] ?? '', ['in', 'out']) ? $_POST['manual_type'] : 'in';
    $manualDate  = $_POST['manual_date'] ?? date('Y-m-d');
    $manualTime  = $_POST['manual_time'] ?? date('H:i');
    $manualNotes = trim($_POST['manual_notes'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $manualDate) || !preg_match('/^\d{2}:\d{2}$/', $manualTime)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    // إذا اختار موظف موجود
    if ($manualEmpId > 0) {
        $empCheck = db()->prepare("SELECT id, branch_id FROM employees WHERE id = ?");
        $empCheck->execute([$manualEmpId]);
        if (!$empCheck->fetch()) {
            echo json_encode(['success' => false, 'message' => 'الموظف غير موجود']);
            exit;
        }
    } elseif (!empty($manualName)) {
        // إنشاء موظف جديد غير مسجل
        $newToken = generateUniqueToken();
        $newPin   = generateUniquePin();
        db()->prepare("INSERT INTO employees (name, job_title, pin, unique_token, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())")
            ->execute([$manualName, 'غير مسجل', $newPin, $newToken]);
        $manualEmpId = (int)db()->lastInsertId();
        $manualNotes = ($manualNotes ? $manualNotes . ' | ' : '') . 'تمت الإضافة يدوياً';
    } else {
        echo json_encode(['success' => false, 'message' => 'يرجى اختيار موظف أو إدخال اسم']);
        exit;
    }

    $ts = $manualDate . ' ' . $manualTime . ':00';
    // حساب التأخير والتبكير
    $lateMin = 0; $earlyMin = 0;
    if ($manualType === 'in') {
        $empBrSt = db()->prepare("SELECT branch_id FROM employees WHERE id = ?");
        $empBrSt->execute([$manualEmpId]);
        $empBr = $empBrSt->fetch();
        $schedule = getBranchSchedule($empBr ? ($empBr['branch_id'] ?? null) : null);
        $wStart = strtotime($manualDate . ' ' . $schedule['work_start_time']);
        $cTime  = strtotime($ts);
        $graceMinutes = (int) getSystemSetting('late_grace_minutes', '0');
        if ($cTime > $wStart) {
            $lateMin = max(0, (int)round(($cTime - $wStart) / 60) - $graceMinutes);
        } elseif ($cTime < $wStart) {
            $earlyMin = max(0, (int)round(($wStart - $cTime) / 60));
        }
    }

    db()->prepare("INSERT INTO attendances (employee_id, type, timestamp, attendance_date, late_minutes, early_minutes, latitude, longitude, notes, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)")
        ->execute([$manualEmpId, $manualType, $ts, $manualDate, $lateMin, $earlyMin, $manualNotes ?: 'إضافة يدوية', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    auditLog('manual_attendance', "إضافة حضور يدوي: emp={$manualEmpId}, type={$manualType}, date={$manualDate}", $manualEmpId);
    echo json_encode(['success' => true, 'message' => 'تم إضافة السجل بنجاح', 'new_csrf' => $_SESSION['csrf_token'] ?? '']);
    exit;
}

// =================== فلاتر ===================
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
// التحقق من صحة التواريخ وتبديلها إن كان البداية بعد النهاية
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp;
}
$empId    = (int)($_GET['emp_id'] ?? 0);
$type     = $_GET['type'] ?? '';
$filterBranch = (int)($_GET['branch'] ?? 0);
$filterShift  = (int)($_GET['shift'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

// بناء الاستعلام مع الفلاتر
$where  = ["a.attendance_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($empId > 0) { $where[] = "a.employee_id = ?"; $params[] = $empId; }
if (in_array($type, ['in','out'])) { $where[] = "a.type = ?"; $params[] = $type; }
if ($filterBranch > 0) { $where[] = "e.branch_id = ?"; $params[] = $filterBranch; }
if ($filterShift > 0) {
    $shiftFilter = buildShiftTimeFilter($filterShift);
    if ($shiftFilter) { $where[] = $shiftFilter['sql']; $params = array_merge($params, $shiftFilter['params']); }
}

$whereStr = implode(' AND ', $where);

// العدد الكلي
$totalStmt = db()->prepare("SELECT COUNT(*) FROM attendances a JOIN employees e ON a.employee_id=e.id WHERE $whereStr");
$totalStmt->execute($params);
$total      = (int)$totalStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

// النتائج
$recStmt = db()->prepare("
    SELECT a.*, e.name AS employee_name, e.job_title, b.name AS branch_name
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE $whereStr
    ORDER BY a.timestamp DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $v) { $recStmt->bindValue($i + 1, $v); }
$recStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$recStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$recStmt->execute();
$records = $recStmt->fetchAll();

// قائمة الموظفين للفلتر
$empList = db()->query("SELECT id, name FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();

// قائمة الفروع للفلتر
$branchList = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// إحصائيات الفترة
$statsStmt = db()->prepare("
    SELECT
        COUNT(CASE WHEN a.type='in' THEN 1 END)  AS total_in,
        COUNT(CASE WHEN a.type='out' THEN 1 END) AS total_out,
        COUNT(DISTINCT a.employee_id)             AS unique_employees,
        COUNT(DISTINCT a.attendance_date)          AS working_days
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE $whereStr
");
$statsStmt->execute($params);
$periodStats = $statsStmt->fetch();

// تصدير CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // التحقق من صحة نطاق التواريخ
    $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
    $toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$fromDate || !$toDate) {
        die('تواريخ غير صالحة');
    }
    if ($fromDate > $toDate) {
        $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp;
    }
    $daysDiff = $toDate->diff($fromDate)->days;
    if ($daysDiff > 365) {
        die('لا يمكن تصدير أكثر من سنة واحدة');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . $dateFrom . '_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    fputcsv($out, ['#', 'الاسم', 'الوظيفة', 'الفرع', 'النوع', 'التاريخ', 'الوقت', 'خط العرض', 'خط الطول', 'الدقة (م)']);
    $allStmt = db()->prepare("SELECT a.*, e.name AS employee_name, e.job_title, b.name AS branch_name FROM attendances a JOIN employees e ON a.employee_id=e.id LEFT JOIN branches b ON e.branch_id=b.id WHERE $whereStr ORDER BY a.timestamp DESC");
    $allStmt->execute($params);
    $i = 1;
    while ($row = $allStmt->fetch()) {
        fputcsv($out, [
            $i++,
            $row['employee_name'],
            $row['job_title'],
            $row['branch_name'] ?? '-',
            $row['type'] === 'in' ? 'دخول' : 'انصراف',
            date('Y-m-d', strtotime($row['timestamp'])),
            date('H:i:s', strtotime($row['timestamp'])),
            $row['latitude'],
            $row['longitude'],
            $row['location_accuracy'],
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- فلاتر -->
<div class="card" style="margin-bottom:20px">
    <form method="GET" class="filter-bar" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0">
            <label class="form-label">من تاريخ</label>
            <input class="form-control" type="date" name="date_from" value="<?= $dateFrom ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">إلى تاريخ</label>
            <input class="form-control" type="date" name="date_to" value="<?= $dateTo ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">الموظف</label>
            <select class="form-control" name="emp_id">
                <option value="0">الكل</option>
                <?php foreach ($empList as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $empId == $e['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">الفرع</label>
            <select class="form-control" name="branch" id="branchSelect">
                <option value="0">الكل</option>
                <?php foreach ($branchList as $br): ?>
                <option value="<?= $br['id'] ?>" <?= $filterBranch == $br['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($br['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">الوردية</label>
            <select class="form-control" name="shift" id="shiftSelect">
                <option value="0">كل الورديات</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">النوع</label>
            <select class="form-control" name="type">
                <option value="">الكل</option>
                <option value="in"  <?= $type==='in'  ? 'selected':'' ?>>دخول</option>
                <option value="out" <?= $type==='out' ? 'selected':'' ?>>انصراف</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">بحث</button>
        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&emp_id=<?= $empId ?>&type=<?= $type ?>&branch=<?= $filterBranch ?>&shift=<?= $filterShift ?>&export=csv"
           class="btn btn-green">تصدير CSV</a>
        <a href="report-daily.php?date=<?= $dateFrom ?>&branch=<?= $filterBranch ?>"
           target="_blank"
           class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">
           🖨️ تقرير يومي
        </a>
        <button type="button" class="btn btn-green" onclick="openManualModal()" style="background:#059669;border-color:#059669">
           ➕ إضافة يدوية
        </button>
    </form>
</div>

<!-- إحصائيات الفترة -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card"><div class="stat-icon-wrap orange"><?= svgIcon('attendance', 26) ?></div><div><div class="stat-value" data-live="att_total"><?= $total ?></div><div class="stat-label">إجمالي التسجيلات</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap green"><?= svgIcon('checkin', 26) ?></div><div><div class="stat-value" data-live="att_in"><?= $periodStats['total_in'] ?></div><div class="stat-label">تسجيلات دخول</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap purple"><?= svgIcon('checkout', 26) ?></div><div><div class="stat-value" data-live="att_out"><?= $periodStats['total_out'] ?></div><div class="stat-label">تسجيلات انصراف</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap blue"><?= svgIcon('employees', 26) ?></div><div><div class="stat-value" data-live="att_emp"><?= $periodStats['unique_employees'] ?></div><div class="stat-label">موظفون فريدون</div></div></div>
</div>

<!-- الجدول -->
<div class="card">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> سجلات الحضور (<span id="totalCount"><?= $total ?></span>)</span>
        <div style="display:flex;align-items:center;gap:10px">
            <div class="live-indicator" id="liveIndicator">
                <span class="live-dot"></span>
                <span id="liveText">مباشر</span>
            </div>
            <span class="badge badge-blue" id="pageBadge">صفحة <?= $page ?> / <?= max(1,$totalPages) ?></span>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="att-table">
        <thead>
            <tr><th>#</th><th>الموظف</th><th>الفرع</th><th>النوع</th><th>التاريخ</th><th>الوقت</th><th>التأخير</th><th>التبكير</th><th>الموقع</th><th>إجراءات</th></tr>
        </thead>
        <tbody id="attendanceTableBody">
        <?php if (empty($records)): ?>
            <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text3)">لا توجد سجلات في هذه الفترة</td></tr>
        <?php else: ?>
            <?php foreach ($records as $i => $rec):
                $isEarly = ($rec['type'] === 'in' && ($rec['early_minutes'] ?? 0) > 0);
                $rowStyle = $isEarly ? 'background:rgba(5,150,105,.06)' : '';
            ?>
            <tr data-ts="<?= htmlspecialchars($rec['timestamp']) ?>" style="<?= $rowStyle ?>">
                <td style="color:var(--text3)"><?= $offset + $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($rec['employee_name']) ?></strong><br><small style="color:var(--text3)"><?= htmlspecialchars($rec['job_title']) ?></small></td>
                <td style="font-size:.78rem;color:var(--text2)"><?= htmlspecialchars($rec['branch_name'] ?? '-') ?></td>
                <td><span class="badge <?= $rec['type'] === 'in' ? 'badge-green' : 'badge-red' ?>"><?= $rec['type'] === 'in' ? '▶ دخول' : '◀ انصراف' ?></span></td>
                <td style="color:var(--text2)"><?= date('Y-m-d', strtotime($rec['timestamp'])) ?></td>
                <td style="color:var(--primary);font-weight:bold"><?= date('h:i:s A', strtotime($rec['timestamp'])) ?></td>
                <td style="color:<?= ($rec['late_minutes'] ?? 0) > 0 ? '#DC2626' : 'var(--text3)' ?>;font-size:.82rem"><?= ($rec['late_minutes'] ?? 0) > 0 ? $rec['late_minutes'] . ' د' : '-' ?></td>
                <td style="font-size:.82rem"><?php
                    $early = (int)($rec['early_minutes'] ?? 0);
                    if ($early > 0) {
                        $starEmoji = $early >= 30 ? '🌟' : ($early >= 15 ? '⭐' : '✨');
                        echo "<span style='color:#059669;font-weight:bold'>{$starEmoji} {$early} د</span>";
                    } else {
                        echo "<span style='color:var(--text3)'>-</span>";
                    }
                ?></td>
                <td><a href="https://maps.google.com/?q=<?= $rec['latitude'] ?>,<?= $rec['longitude'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="عرض على الخريطة">📍</a></td>
                <td style="white-space:nowrap">
                    <button class="btn btn-primary btn-sm" onclick="openEditModal(<?= $rec['id'] ?>)" title="تعديل">✏️</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteRecord(<?= $rec['id'] ?>, this)" title="حذف">🗑️</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:center" id="paginationWrap">
    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
        <?php
            $maxPages = min($totalPages, 10);
            $qs = http_build_query(array_filter([
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'emp_id'    => $empId ?: null,
                'type'      => $type ?: null,
                'branch'    => $filterBranch ?: null,
                'shift'     => $filterShift ?: null,
            ]));
            for ($p = 1; $p <= $maxPages; $p++):
        ?>
            <a href="?<?= $qs ?>&page=<?= $p ?>" class="page-btn<?= $p === $page ? ' active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- نافذة تعديل السجل -->
<div class="modal-overlay" id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--surface,#fff);border-radius:16px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
        <div style="background:linear-gradient(135deg,var(--primary),var(--primary-d,#ea580c));padding:16px 24px;color:#fff;display:flex;justify-content:space-between;align-items:center">
            <h3 style="margin:0;font-size:1.1rem">✏️ تعديل سجل الحضور</h3>
            <button onclick="closeEditModal()" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer">&times;</button>
        </div>
        <form id="editForm" style="padding:24px;display:flex;flex-direction:column;gap:14px">
            <input type="hidden" id="editId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">النوع</label>
                    <select id="editType" class="form-control" style="width:100%">
                        <option value="in">▶ دخول</option>
                        <option value="out">◀ انصراف</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">التأخير (دقائق)</label>
                    <input type="number" id="editLate" class="form-control" min="0" value="0" style="width:100%">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">التاريخ</label>
                    <input type="date" id="editDate" class="form-control" required style="width:100%">
                </div>
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">الوقت</label>
                    <input type="time" id="editTime" class="form-control" required style="width:100%">
                </div>
            </div>
            <div>
                <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">ملاحظات</label>
                <textarea id="editNotes" class="form-control" rows="2" placeholder="ملاحظات اختيارية..." style="width:100%;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary">إلغاء</button>
                <button type="submit" class="btn btn-primary" id="editSaveBtn">💾 حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة إضافة يدوية -->
<div class="modal-overlay" id="manualModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--surface,#fff);border-radius:16px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
        <div style="background:linear-gradient(135deg,#059669,#047857);padding:16px 24px;color:#fff;display:flex;justify-content:space-between;align-items:center">
            <h3 style="margin:0;font-size:1.1rem">➕ إضافة سجل حضور يدوي</h3>
            <button onclick="closeManualModal()" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer">&times;</button>
        </div>
        <form id="manualForm" style="padding:24px;display:flex;flex-direction:column;gap:14px">
            <div>
                <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">اختر موظف مسجل</label>
                <select id="manualEmpSelect" name="manual_emp_id" class="form-control" style="width:100%">
                    <option value="0">— غير مسجل (أدخل الاسم أدناه) —</option>
                    <?php foreach ($empList as $e): ?>
                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="manualNameWrap">
                <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">اسم الموظف (غير مسجل)</label>
                <input type="text" name="manual_name" class="form-control" placeholder="أدخل الاسم الكامل..." style="width:100%">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">النوع</label>
                    <select name="manual_type" class="form-control" style="width:100%">
                        <option value="in">▶ دخول</option>
                        <option value="out">◀ انصراف</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">التاريخ</label>
                    <input type="date" name="manual_date" class="form-control" value="<?= date('Y-m-d') ?>" required style="width:100%">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">الوقت</label>
                    <input type="time" name="manual_time" class="form-control" value="<?= date('H:i') ?>" required style="width:100%">
                </div>
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">ملاحظات</label>
                    <input type="text" name="manual_notes" class="form-control" placeholder="اختياري..." style="width:100%">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
                <button type="button" onclick="closeManualModal()" class="btn btn-secondary">إلغاء</button>
                <button type="submit" class="btn btn-primary" id="manualSaveBtn" style="background:#059669;border-color:#059669">💾 إضافة السجل</button>
            </div>
        </form>
    </div>
</div>

<script>
// =================== فلتر الورديات الديناميكي ===================
(function(){
    const branchShifts = <?= json_encode(getAllBranchShifts(), JSON_UNESCAPED_UNICODE) ?>;
    const branchSel = document.getElementById('branchSelect');
    const shiftSel = document.getElementById('shiftSelect');
    const curShift = <?= $filterShift ?>;
    function updateShifts(){
        const bid = branchSel ? branchSel.value : 0;
        shiftSel.innerHTML = '<option value="0">كل الورديات</option>';
        if(bid && branchShifts[bid]){
            branchShifts[bid].forEach(s=>{
                const o = document.createElement('option');
                o.value = s.id;
                o.textContent = 'وردية '+s.num+' ('+s.start+' - '+s.end+')';
                if(s.id == curShift) o.selected = true;
                shiftSel.appendChild(o);
            });
        }
    }
    if(branchSel) branchSel.addEventListener('change', ()=>{ shiftSel.value = 0; updateShifts(); });
    updateShifts();
})();

// =================== الساعة ===================
function tick(){ const el=document.getElementById('topbarClock'); if(el) el.textContent=new Date().toLocaleString('ar-SA'); }
tick(); setInterval(tick,1000);

function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
document.getElementById('sidebarOverlay')?.addEventListener('click', toggleSidebar);

// =================== التحديث بالوقت الفعلي (جزئي) ===================
const REFRESH_INTERVAL = 20000;
let refreshTimer = null;
let failCount = 0;

const currentFilters = {
    date_from: <?= json_encode($dateFrom) ?>,
    date_to:   <?= json_encode($dateTo) ?>,
    emp_id:    <?= json_encode($empId) ?>,
    type:      <?= json_encode($type) ?>,
    branch:    <?= json_encode($filterBranch) ?>,
    page:      <?= json_encode($page) ?>,
};

// تحديث قيمة إحصائية واحدة فقط
function updateStatValue(key, newValue) {
    const el = document.querySelector(`[data-live="${key}"]`);
    if (!el) return;
    const nv = String(newValue);
    if (el.textContent.trim() !== nv) {
        el.textContent = nv;
        el.classList.add('updated');
        setTimeout(() => el.classList.remove('updated'), 600);
    }
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
}

function setLiveStatus(status) {
    const indicator = document.getElementById('liveIndicator');
    const text = document.getElementById('liveText');
    if (!indicator) return;
    indicator.classList.remove('paused', 'error');
    if (status === 'live') { text.textContent = 'مباشر'; }
    else if (status === 'paused') { indicator.classList.add('paused'); text.textContent = 'متوقف'; }
    else if (status === 'error') { indicator.classList.add('error'); text.textContent = 'خطأ'; }
}

// آخر timestamp لأحدث سجل في الجدول
let lastRecordTs = <?= json_encode(!empty($records) ? $records[0]['timestamp'] : null) ?>;

// إضافة صفوف جديدة أعلى الجدول (صفحة 1 + عرض اليوم فقط)
function prependNewRecords(apiRecords) {
    const today = new Date().toISOString().slice(0,10);
    const isToday = currentFilters.date_from === today && currentFilters.date_to === today;
    const isPage1 = currentFilters.page === 1;
    if (!isToday || !isPage1 || !lastRecordTs) return;

    const tbody = document.getElementById('attendanceTableBody');
    if (!tbody) return;

    const newRecs = apiRecords.filter(r => r.timestamp > lastRecordTs);
    if (newRecs.length === 0) return;

    // إزالة رسالة "لا توجد سجلات" إن وُجدت
    const emptyRow = tbody.querySelector('td[colspan="8"]');
    if (emptyRow) emptyRow.closest('tr').remove();

    newRecs.reverse().forEach(rec => {
        const tr = document.createElement('tr');
        tr.setAttribute('data-ts', rec.timestamp);
        tr.classList.add('new-row');
        const badgeClass = rec.type === 'in' ? 'badge-green' : 'badge-red';
        const badgeText  = rec.type === 'in' ? '▶ دخول' : '◀ انصراف';
        tr.innerHTML = `
            <td style="color:var(--text3)">●</td>
            <td><strong>${escapeHtml(rec.employee_name)}</strong><br><small style="color:var(--text3)">${escapeHtml(rec.job_title)}</small></td>
            <td style="font-size:.78rem;color:var(--text2)">${escapeHtml(rec.branch_name)}</td>
            <td><span class="badge ${badgeClass}">${badgeText}</span></td>
            <td style="color:var(--text2)">${escapeHtml(rec.date)}</td>
            <td style="color:var(--primary);font-weight:bold">${escapeHtml(rec.time)}</td>
            <td><a href="https://maps.google.com/?q=${rec.latitude},${rec.longitude}" target="_blank" class="btn btn-secondary btn-sm" title="عرض على الخريطة">الخريطة</a></td>
            <td style="color:var(--text3);font-size:.8rem">${escapeHtml(rec.accuracy)}</td>
            <td style="white-space:nowrap">
                <button class="btn btn-primary btn-sm" onclick="openEditModal(${rec.id})" title="تعديل">✏️</button>
                <button class="btn btn-danger btn-sm" onclick="deleteRecord(${rec.id}, this)" title="حذف">🗑️</button>
            </td>`;
        tbody.insertBefore(tr, tbody.firstChild);
    });

    // الاحتفاظ بأحدث 25 فقط
    while (tbody.children.length > 25) {
        tbody.removeChild(tbody.lastChild);
    }

    lastRecordTs = apiRecords[0].timestamp;
}

async function fetchAttendanceStats() {
    try {
        const params = new URLSearchParams({
            date_from: currentFilters.date_from,
            date_to:   currentFilters.date_to,
            emp_id:    currentFilters.emp_id,
            type:      currentFilters.type,
            branch:    currentFilters.branch,
            page:      currentFilters.page,
        });
        const resp = await fetch(`../api/realtime-attendance.php?${params}`, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'خطأ');

        // تحديث جزئي: الإحصائيات فقط
        updateStatValue('att_total', data.stats.total);
        updateStatValue('att_in', data.stats.total_in);
        updateStatValue('att_out', data.stats.total_out);
        updateStatValue('att_emp', data.stats.unique_employees);

        // تحديث العدد الكلي في العنوان
        const totalSpan = document.getElementById('totalCount');
        if (totalSpan && totalSpan.textContent !== String(data.pagination.total)) {
            totalSpan.textContent = data.pagination.total;
        }

        // إضافة سجلات جديدة فوق الجدول (اليوم + صفحة 1 فقط)
        prependNewRecords(data.records);

        setLiveStatus('live');
        failCount = 0;
    } catch (e) {
        failCount++;
        console.warn('Real-time fetch failed:', e.message);
        setLiveStatus(failCount >= 3 ? 'error' : 'paused');
    }
}

// =================== حذف سجل ===================
async function deleteRecord(id, btn) {
    if (!confirm('هل أنت متأكد من حذف هذا السجل؟')) return;
    btn.disabled = true;
    btn.textContent = '...';
    try {
        const fd = new FormData();
        fd.append('delete_id', id);
        fd.append('csrf_token', document.getElementById('csrfToken')?.value || '');
        const resp = await fetch('', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (data.success) {
            // تحديث CSRF token
            if (data.new_csrf) document.getElementById('csrfToken').value = data.new_csrf;
            const row = btn.closest('tr');
            row.style.transition = 'opacity .3s, transform .3s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(30px)';
            setTimeout(() => row.remove(), 300);
            // تحديث العداد
            const tc = document.getElementById('totalCount');
            if (tc) tc.textContent = Math.max(0, parseInt(tc.textContent) - 1);
        } else {
            alert(data.message || 'فشل الحذف');
            btn.disabled = false;
            btn.textContent = '🗑️';
        }
    } catch (e) {
        alert('خطأ: ' + e.message);
        btn.disabled = false;
        btn.textContent = '🗑️';
    }
}

// =================== تعديل سجل ===================
function openEditModal(id) {
    const modal = document.getElementById('editModal');
    modal.style.display = 'flex';
    document.getElementById('editSaveBtn').disabled = true;
    document.getElementById('editSaveBtn').textContent = 'جارٍ التحميل...';

    fetch('?fetch_id=' + id, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'خطأ'); closeEditModal(); return; }
            const rec = data.record;
            document.getElementById('editId').value = rec.id;
            document.getElementById('editType').value = rec.type;
            document.getElementById('editDate').value = rec.attendance_date;
            document.getElementById('editTime').value = rec.timestamp.substring(11, 16);
            document.getElementById('editLate').value = rec.late_minutes || 0;
            document.getElementById('editNotes').value = rec.notes || '';
            document.getElementById('editSaveBtn').disabled = false;
            document.getElementById('editSaveBtn').textContent = '💾 حفظ التعديلات';
        })
        .catch(e => { alert('خطأ: ' + e.message); closeEditModal(); });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('editSaveBtn');
    btn.disabled = true;
    btn.textContent = 'جارٍ الحفظ...';

    try {
        const fd = new FormData();
        fd.append('edit_id', document.getElementById('editId').value);
        fd.append('edit_type', document.getElementById('editType').value);
        fd.append('edit_date', document.getElementById('editDate').value);
        fd.append('edit_time', document.getElementById('editTime').value);
        fd.append('edit_late', document.getElementById('editLate').value);
        fd.append('edit_notes', document.getElementById('editNotes').value);
        fd.append('csrf_token', document.getElementById('csrfToken')?.value || '');

        const resp = await fetch('', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (data.success) {
            // تحديث CSRF token
            if (data.new_csrf) document.getElementById('csrfToken').value = data.new_csrf;
            closeEditModal();
            location.reload();
        } else {
            alert(data.message || 'فشل الحفظ');
            btn.disabled = false;
            btn.textContent = '💾 حفظ التعديلات';
        }
    } catch (e) {
        alert('خطأ: ' + e.message);
        btn.disabled = false;
        btn.textContent = '💾 حفظ التعديلات';
    }
});

// إغلاق النافذة بالنقر خارجها
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// بدء التحديث الدوري
refreshTimer = setInterval(fetchAttendanceStats, REFRESH_INTERVAL);

// إيقاف عند إخفاء الصفحة
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(refreshTimer);
        setLiveStatus('paused');
    } else {
        fetchAttendanceStats();
        refreshTimer = setInterval(fetchAttendanceStats, REFRESH_INTERVAL);
    }
});

// =================== إضافة يدوية ===================
function openManualModal() {
    document.getElementById('manualModal').style.display = 'flex';
}
function closeManualModal() {
    document.getElementById('manualModal').style.display = 'none';
}
document.getElementById('manualModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeManualModal();
});
document.getElementById('manualEmpSelect')?.addEventListener('change', function() {
    document.getElementById('manualNameWrap').style.display = this.value === '0' ? 'block' : 'none';
});
document.getElementById('manualForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('manualSaveBtn');
    btn.disabled = true;
    btn.textContent = 'جارٍ الحفظ...';
    try {
        const fd = new FormData(this);
        fd.append('manual_add', '1');
        fd.append('csrf_token', document.getElementById('csrfToken')?.value || '');
        const resp = await fetch('', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (data.success) {
            // تحديث CSRF token
            if (data.new_csrf) document.getElementById('csrfToken').value = data.new_csrf;
            closeManualModal();
            location.reload();
        } else {
            alert(data.message || 'فشل الإضافة');
            btn.disabled = false;
            btn.textContent = '💾 إضافة السجل';
        }
    } catch (e) {
        alert('خطأ: ' + e.message);
        btn.disabled = false;
        btn.textContent = '💾 إضافة السجل';
    }
});
</script>

</div></div>
</body></html>
