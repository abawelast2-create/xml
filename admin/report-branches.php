<?php
// =============================================================
// admin/report-branches.php - مقارنة الفروع
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'مقارنة الفروع';
$activePage = 'report-branches';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$filterShiftNum = (int)($_GET['shift_num'] ?? 0);

$branches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// تجهيز ورديات كل فرع حسب رقم الوردية المختار
$branchShiftMap = [];
if ($filterShiftNum > 0) {
    $bsStmt = db()->prepare("SELECT id, branch_id FROM branch_shifts WHERE shift_number = ? AND is_active = 1");
    $bsStmt->execute([$filterShiftNum]);
    foreach ($bsStmt->fetchAll() as $bs) {
        $branchShiftMap[(int)$bs['branch_id']] = (int)$bs['id'];
    }
}

// أقصى رقم وردية موجود
$maxShiftNum = (int)db()->query("SELECT MAX(shift_number) FROM branch_shifts WHERE is_active = 1")->fetchColumn();

// بيانات كل فرع
$branchStats = [];
foreach ($branches as $b) {
    $bid = $b['id'];
    
    // فلتر الوردية لهذا الفرع
    $shiftTimeCond = '';
    $shiftTimeParams = [];
    if ($filterShiftNum > 0 && isset($branchShiftMap[$bid])) {
        $sf = buildShiftTimeFilter($branchShiftMap[$bid]);
        if ($sf) {
            $shiftTimeCond = "AND " . $sf['sql'];
            $shiftTimeParams = $sf['params'];
        }
    }
    
    // عدد الموظفين
    $stmt = db()->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([$bid]);
    $empCount = (int)$stmt->fetchColumn();
    
    // إجمالي تسجيلات الحضور
    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT CONCAT(a.employee_id, '-', a.attendance_date)) AS present_days,
               COALESCE(SUM(CASE WHEN a.type='in' AND a.late_minutes > 0 THEN 1 ELSE 0 END), 0) AS late_count,
               COALESCE(SUM(CASE WHEN a.type='in' THEN a.late_minutes ELSE 0 END), 0) AS total_late_min,
               COALESCE(AVG(CASE WHEN a.type='in' AND a.late_minutes > 0 THEN a.late_minutes END), 0) AS avg_late_min
        FROM attendances a
        JOIN employees e ON a.employee_id = e.id
        WHERE e.branch_id = ? AND a.attendance_date BETWEEN ? AND ?
          AND e.is_active = 1 AND e.deleted_at IS NULL
          {$shiftTimeCond}
    ");
    $stmt->execute(array_merge([$bid, $dateFrom, $dateTo], $shiftTimeParams));
    $attStats = $stmt->fetch();

    // أيام العمل (بدون الجمعة)
    $workDays = 0;
    $today = date('Y-m-d');
    $s = strtotime($dateFrom);
    $e = min(strtotime($dateTo), strtotime($today));
    for ($d = $s; $d <= $e; $d += 86400) {
        if (date('w', $d) != 5) $workDays++;
    }
    
    $expectedDays = $workDays * $empCount;
    $presentDays = (int)($attStats['present_days'] ?? 0);
    $absentDays = max(0, $expectedDays - $presentDays);
    $attendanceRate = $expectedDays > 0 ? round(($presentDays / $expectedDays) * 100, 1) : 0;

    $branchStats[] = [
        'id'   => $bid,
        'name' => $b['name'],
        'emp_count' => $empCount,
        'present_days' => $presentDays,
        'absent_days' => $absentDays,
        'late_count' => (int)($attStats['late_count'] ?? 0),
        'total_late_min' => (int)($attStats['total_late_min'] ?? 0),
        'avg_late_min' => round((float)($attStats['avg_late_min'] ?? 0), 1),
        'attendance_rate' => $attendanceRate,
        'work_days' => $workDays,
    ];
}

// ترتيب الفروع حسب نسبة الحضور
usort($branchStats, fn($a, $b) => $b['attendance_rate'] <=> $a['attendance_rate']);

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- أدوات -->
<?php
$reportTitle = 'تقرير مقارنة الفروع';
$reportSubtitle = 'نظام الحضور والانصراف';
$reportMeta = ["الفترة: {$dateFrom} إلى {$dateTo}"];
require __DIR__ . '/../includes/report_print_header.php';
?>
<div class="report-filter">
    <form method="GET" class="filter-bar">
        <div class="form-group">
            <label>من</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>إلى</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>الوردية</label>
            <select name="shift_num" class="form-control">
                <option value="0">كل الورديات</option>
                <?php for ($sn = 1; $sn <= $maxShiftNum; $sn++): ?>
                    <option value="<?= $sn ?>" <?= $filterShiftNum === $sn ? 'selected' : '' ?>>الوردية <?= $sn ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= svgIcon('compare', 16) ?> عرض</button>
            <button type="button" onclick="window.print()" class="btn btn-secondary"><?= svgIcon('document', 16) ?> طباعة</button>
        </div>
    </form>
</div>

<!-- الرسم البياني -->
<div class="chart-card" style="margin-bottom:20px">
    <h3 class="chart-card-title"><?= svgIcon('chart', 18) ?> نسبة الحضور حسب الفرع</h3>
    <div style="display:grid;gap:12px">
        <?php foreach ($branchStats as $bs): 
            $color = $bs['attendance_rate'] >= 90 ? 'var(--green)' : ($bs['attendance_rate'] >= 70 ? 'var(--yellow)' : 'var(--red)');
        ?>
        <div style="display:flex;align-items:center;gap:12px">
            <div style="width:100px;font-size:.85rem;font-weight:600;text-align:left;flex-shrink:0"><?= htmlspecialchars($bs['name']) ?></div>
            <div style="flex:1;background:var(--surface3);border-radius:8px;height:28px;overflow:hidden;position:relative">
                <div style="width:<?= $bs['attendance_rate'] ?>%;background:<?= $color ?>;height:100%;border-radius:8px;transition:width .5s;display:flex;align-items:center;justify-content:flex-end;padding:0 8px;min-width:40px">
                    <span style="color:#fff;font-size:.75rem;font-weight:700"><?= $bs['attendance_rate'] ?>%</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- الجدول -->
<div class="report-table-wrap">
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>الترتيب</th>
                    <th>الفرع</th>
                    <th style="text-align:center">الموظفون</th>
                    <th style="text-align:center">نسبة الحضور</th>
                    <th style="text-align:center">أيام الحضور</th>
                    <th style="text-align:center">أيام الغياب</th>
                    <th style="text-align:center">تأخيرات</th>
                    <th style="text-align:center">متوسط التأخير</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branchStats as $i => $bs):
                    $medal = '';
                    if ($i === 0) $medal = '🥇';
                    elseif ($i === 1) $medal = '🥈';
                    elseif ($i === 2) $medal = '🥉';
                ?>
                <tr>
                    <td style="font-size:.95rem"><?= $medal ?: ($i + 1) ?></td>
                    <td style="font-weight:600"><?= htmlspecialchars($bs['name']) ?></td>
                    <td style="text-align:center"><?= $bs['emp_count'] ?></td>
                    <td style="text-align:center">
                        <span class="badge <?= $bs['attendance_rate'] >= 90 ? 'badge-green' : ($bs['attendance_rate'] >= 70 ? 'badge-yellow' : 'badge-red') ?>">
                            <?= $bs['attendance_rate'] ?>%
                        </span>
                    </td>
                    <td style="text-align:center;color:var(--green);font-weight:600"><?= $bs['present_days'] ?></td>
                    <td style="text-align:center;color:var(--red);font-weight:600"><?= $bs['absent_days'] ?></td>
                    <td style="text-align:center;color:var(--yellow);font-weight:600"><?= $bs['late_count'] ?></td>
                    <td style="text-align:center;font-size:.85rem"><?= $bs['avg_late_min'] ?> دقيقة</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@media print {
    .sidebar, .topbar, .bottom-nav, form, .no-print { display: none !important; }
    .main-content { margin: 0 !important; }
    .content { padding: 0 !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #e5dcc8; }
    .print-report-header, .print-report-footer { display: block !important; }
    .content::after { opacity: .035 !important; }
}
</style>

<?php require __DIR__ . '/../includes/report_print_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div>
</body></html>
