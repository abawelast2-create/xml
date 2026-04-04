<?php
// =============================================================
// admin/report-monthly.php - التقرير الشهري للحضور
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'التقرير الشهري';
$activePage = 'report-monthly';

// =================== فلاتر ===================
$month    = $_GET['month'] ?? date('Y-m');
$branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
$empId    = !empty($_GET['emp_id']) ? (int)$_GET['emp_id'] : null;
$filterShift = (int)($_GET['shift'] ?? 0);

$year  = (int)date('Y', strtotime($month . '-01'));
$mon   = (int)date('m', strtotime($month . '-01'));
$daysInMonth = (int)date('t', strtotime($month . '-01'));
$monthName = date('F Y', strtotime($month . '-01'));

// الفروع
$branches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// الموظفون (مع فلتر الفرع)
$empWhere = "e.is_active = 1 AND e.deleted_at IS NULL";
$empParams = [];
if ($branchId) {
    $empWhere .= " AND e.branch_id = ?";
    $empParams[] = $branchId;
}
if ($empId) {
    $empWhere .= " AND e.id = ?";
    $empParams[] = $empId;
}

$empStmt = db()->prepare("
    SELECT e.id, e.name, e.job_title, b.name AS branch_name
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$empWhere}
    ORDER BY e.name
");
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll();

// بيانات الحضور لهذا الشهر
$startDate = "{$year}-" . str_pad($mon, 2, '0', STR_PAD_LEFT) . "-01";
$endDate   = "{$year}-" . str_pad($mon, 2, '0', STR_PAD_LEFT) . "-" . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);

// فلتر الوردية
$shiftTimeCond = '';
$shiftTimeParams = [];
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift);
    if ($sf) { $shiftTimeCond = "AND " . $sf['sql']; $shiftTimeParams = $sf['params']; }
}

$attStmt = db()->prepare("
    SELECT a.employee_id, a.attendance_date,
           MIN(CASE WHEN a.type='in' THEN a.timestamp END) AS first_in,
           MAX(CASE WHEN a.type='out' THEN a.timestamp END) AS last_out,
           MIN(CASE WHEN a.type='in' THEN a.late_minutes END) AS late_minutes
    FROM attendances a
    WHERE a.attendance_date BETWEEN ? AND ?
    $shiftTimeCond
    GROUP BY a.employee_id, a.attendance_date
");
$attStmt->execute(array_merge([$startDate, $endDate], $shiftTimeParams));
$attData = $attStmt->fetchAll();

// ترتيب البيانات: [emp_id][date] => row
$attMap = [];
foreach ($attData as $row) {
    $attMap[$row['employee_id']][$row['attendance_date']] = $row;
}

// الإجازات المعتمدة
$leaveStmt = db()->prepare("
    SELECT employee_id, start_date, end_date, leave_type
    FROM leaves
    WHERE status = 'approved' AND start_date <= ? AND end_date >= ?
");
$leaveStmt->execute([$endDate, $startDate]);
$leaveData = $leaveStmt->fetchAll();

$leaveMap = [];
foreach ($leaveData as $lv) {
    $s = max(strtotime($lv['start_date']), strtotime($startDate));
    $e = min(strtotime($lv['end_date']), strtotime($endDate));
    for ($d = $s; $d <= $e; $d += 86400) {
        $dateStr = date('Y-m-d', $d);
        $leaveMap[$lv['employee_id']][$dateStr] = $lv['leave_type'];
    }
}

// جميع الموظفين (بدون فلتر) للقائمة المنسدلة
$allEmps = db()->query("SELECT id, name FROM employees WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name")->fetchAll();

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- الأدوات -->
<div class="card" style="margin-bottom:16px;padding:14px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">الشهر</label>
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>"
                   style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">الفرع</label>
            <select name="branch_id" id="branchSelect" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                <option value="">كل الفروع</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $branchId == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">الوردية</label>
            <select name="shift" id="shiftSelect" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                <option value="0">كل الورديات</option>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">الموظف</label>
            <select name="emp_id" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                <option value="">كل الموظفين</option>
                <?php foreach ($allEmps as $ae): ?>
                    <option value="<?= $ae['id'] ?>" <?= $empId == $ae['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ae['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding:8px 20px">عرض</button>
        <button type="button" onclick="window.print()" class="btn btn-secondary" style="padding:8px 16px">🖨️ طباعة</button>
    </form>
</div>

<!-- ملخص -->
<?php
$totalPresent = 0; $totalAbsent = 0; $totalLate = 0; $totalLateMin = 0;
$today = date('Y-m-d');
foreach ($employees as $emp) {
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $mon, $d);
        if ($dateStr > $today) continue;
        $dayOfWeek = date('w', strtotime($dateStr));
        if ($dayOfWeek == 5) continue; // الجمعة عطلة
        
        if (isset($attMap[$emp['id']][$dateStr])) {
            $totalPresent++;
            $late = (int)($attMap[$emp['id']][$dateStr]['late_minutes'] ?? 0);
            if ($late > 0) { $totalLate++; $totalLateMin += $late; }
        } elseif (isset($leaveMap[$emp['id']][$dateStr])) {
            // إجازة - لا يحسب غياب
        } else {
            $totalAbsent++;
        }
    }
}
?>
<div class="stats-grid" style="margin-bottom:16px">
    <div class="stat-card">
        <div class="stat-icon-wrap green">✓</div>
        <div><div class="stat-value"><?= $totalPresent ?></div><div class="stat-label">أيام حضور</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap red">✗</div>
        <div><div class="stat-value"><?= $totalAbsent ?></div><div class="stat-label">أيام غياب</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#FEF3C7;color:#D97706">⏰</div>
        <div><div class="stat-value"><?= $totalLate ?></div><div class="stat-label">تأخيرات</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#DBEAFE;color:#2563EB">📊</div>
        <div><div class="stat-value"><?= $totalLateMin ?> د</div><div class="stat-label">إجمالي دقائق التأخير</div></div>
    </div>
</div>

<!-- الجدول -->
<?php foreach ($employees as $emp): ?>
<div class="card" style="margin-bottom:16px;padding:0;overflow:hidden">
    <div style="padding:14px 16px;background:var(--surface2,#F8FAFC);border-bottom:1px solid var(--border-color,#E2E8F0);display:flex;justify-content:space-between;align-items:center">
        <div>
            <strong style="font-size:.95rem"><?= htmlspecialchars($emp['name']) ?></strong>
            <span style="color:var(--text3);font-size:.82rem;margin-right:8px"><?= htmlspecialchars($emp['job_title']) ?></span>
            <span style="color:var(--text3);font-size:.78rem;margin-right:8px">— <?= htmlspecialchars($emp['branch_name'] ?? 'بدون فرع') ?></span>
        </div>
        <?php
        $empPresent = 0; $empAbsent = 0; $empLate = 0; $empLeave = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $mon, $d);
            if ($dateStr > $today) continue;
            $dayOfWeek = date('w', strtotime($dateStr));
            if ($dayOfWeek == 5) continue;
            if (isset($attMap[$emp['id']][$dateStr])) {
                $empPresent++;
                if (($attMap[$emp['id']][$dateStr]['late_minutes'] ?? 0) > 0) $empLate++;
            } elseif (isset($leaveMap[$emp['id']][$dateStr])) {
                $empLeave++;
            } else {
                $empAbsent++;
            }
        }
        ?>
        <div style="display:flex;gap:10px;font-size:.78rem">
            <span style="color:#10B981">حضور: <?= $empPresent ?></span>
            <span style="color:#EF4444">غياب: <?= $empAbsent ?></span>
            <span style="color:#D97706">تأخير: <?= $empLate ?></span>
            <span style="color:#6366F1">إجازة: <?= $empLeave ?></span>
        </div>
    </div>
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;min-width:650px">
            <thead>
                <tr style="background:var(--surface2,#F8FAFC)">
                    <th style="padding:8px;text-align:center;width:34px">اليوم</th>
                    <th style="padding:8px;text-align:center">الحالة</th>
                    <th style="padding:8px;text-align:center">أول دخول</th>
                    <th style="padding:8px;text-align:center">آخر خروج</th>
                    <th style="padding:8px;text-align:center">التأخير</th>
                </tr>
            </thead>
            <tbody>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $dateStr = sprintf('%04d-%02d-%02d', $year, $mon, $d);
                $dayOfWeek = date('w', strtotime($dateStr));
                $dayName = ['أحد','إثنين','ثلاثاء','أربعاء','خميس','جمعة','سبت'][$dayOfWeek];
                $isFriday = $dayOfWeek == 5;
                $isFuture = $dateStr > $today;
                $att = $attMap[$emp['id']][$dateStr] ?? null;
                $leave = $leaveMap[$emp['id']][$dateStr] ?? null;
                
                $statusLabel = '';
                $statusCls = '';
                if ($isFuture) {
                    $statusLabel = '—'; $statusCls = '';
                } elseif ($isFriday) {
                    $statusLabel = 'عطلة'; $statusCls = 'color:var(--text3)';
                } elseif ($att) {
                    $late = (int)($att['late_minutes'] ?? 0);
                    if ($late > 0) { $statusLabel = 'متأخر'; $statusCls = 'color:#D97706;font-weight:600'; }
                    else { $statusLabel = 'حاضر'; $statusCls = 'color:#10B981;font-weight:600'; }
                } elseif ($leave) {
                    $types = ['annual'=>'سنوية','sick'=>'مرضية','unpaid'=>'بدون راتب','other'=>'أخرى'];
                    $statusLabel = 'إجازة (' . ($types[$leave] ?? $leave) . ')';
                    $statusCls = 'color:#6366F1;font-weight:600';
                } else {
                    $statusLabel = 'غائب'; $statusCls = 'color:#EF4444;font-weight:600';
                }
            ?>
                <tr style="border-bottom:1px solid var(--border-color,#E2E8F0);<?= $isFriday ? 'background:var(--surface2,#F8FAFC);opacity:.6' : '' ?>">
                    <td style="padding:6px 8px;text-align:center;font-weight:600"><?= $d ?> <small style="color:var(--text3)"><?= $dayName ?></small></td>
                    <td style="padding:6px 8px;text-align:center;<?= $statusCls ?>"><?= $statusLabel ?></td>
                    <td style="padding:6px 8px;text-align:center;direction:ltr"><?= $att && $att['first_in'] ? date('h:i A', strtotime($att['first_in'])) : '—' ?></td>
                    <td style="padding:6px 8px;text-align:center;direction:ltr"><?= $att && $att['last_out'] ? date('h:i A', strtotime($att['last_out'])) : '—' ?></td>
                    <td style="padding:6px 8px;text-align:center;<?= ($att && ($att['late_minutes'] ?? 0) > 0) ? 'color:#D97706;font-weight:600' : '' ?>">
                        <?= $att && ($att['late_minutes'] ?? 0) > 0 ? $att['late_minutes'] . ' د' : '—' ?>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($employees)): ?>
<div class="card" style="padding:40px;text-align:center;color:var(--text3)">لا يوجد موظفون مطابقون للفلاتر</div>
<?php endif; ?>

<script>
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
</script>

<style>
@media print {
    .sidebar, .topbar, .bottom-nav, form, .no-print { display: none !important; }
    .main-content { margin: 0 !important; }
    .content { padding: 0 !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
}
</style>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div>
</body></html>
