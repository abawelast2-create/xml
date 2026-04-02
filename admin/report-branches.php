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
<div class="card" style="margin-bottom:16px;padding:14px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">من</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                   style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">إلى</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                   style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text3);display:block;margin-bottom:3px">الوردية</label>
            <select name="shift_num"
                    style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                <option value="0">كل الورديات</option>
                <?php for ($sn = 1; $sn <= $maxShiftNum; $sn++): ?>
                    <option value="<?= $sn ?>" <?= $filterShiftNum === $sn ? 'selected' : '' ?>>الوردية <?= $sn ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding:8px 20px">عرض</button>
        <button type="button" onclick="window.print()" class="btn btn-secondary" style="padding:8px 16px">🖨️ طباعة</button>
    </form>
</div>

<!-- الرسم البياني -->
<div class="card" style="margin-bottom:16px;padding:20px">
    <h3 style="font-size:.95rem;margin-bottom:14px;color:var(--text-primary)"><span class="card-title-bar"></span> نسبة الحضور حسب الفرع</h3>
    <div style="display:grid;gap:12px">
        <?php foreach ($branchStats as $bs): 
            $color = $bs['attendance_rate'] >= 90 ? '#10B981' : ($bs['attendance_rate'] >= 70 ? '#F59E0B' : '#EF4444');
        ?>
        <div style="display:flex;align-items:center;gap:12px">
            <div style="width:100px;font-size:.85rem;font-weight:600;text-align:left;flex-shrink:0"><?= htmlspecialchars($bs['name']) ?></div>
            <div style="flex:1;background:var(--surface2,#F1F5F9);border-radius:8px;height:28px;overflow:hidden;position:relative">
                <div style="width:<?= $bs['attendance_rate'] ?>%;background:<?= $color ?>;height:100%;border-radius:8px;transition:width .5s;display:flex;align-items:center;justify-content:flex-end;padding:0 8px;min-width:40px">
                    <span style="color:#fff;font-size:.75rem;font-weight:700"><?= $bs['attendance_rate'] ?>%</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- الجدول -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:var(--surface2,#F8FAFC)">
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الترتيب</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الفرع</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">الموظفون</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">نسبة الحضور</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">أيام الحضور</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">أيام الغياب</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">تأخيرات</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">متوسط التأخير</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branchStats as $i => $bs):
                    $medal = '';
                    if ($i === 0) $medal = '🥇';
                    elseif ($i === 1) $medal = '🥈';
                    elseif ($i === 2) $medal = '🥉';
                ?>
                <tr style="border-bottom:1px solid var(--border-color,#E2E8F0)">
                    <td style="padding:12px 14px;font-size:.95rem"><?= $medal ?: ($i + 1) ?></td>
                    <td style="padding:12px 14px;font-weight:600"><?= htmlspecialchars($bs['name']) ?></td>
                    <td style="padding:12px 14px;text-align:center"><?= $bs['emp_count'] ?></td>
                    <td style="padding:12px 14px;text-align:center">
                        <span style="background:<?= $bs['attendance_rate'] >= 90 ? '#D1FAE5' : ($bs['attendance_rate'] >= 70 ? '#FEF3C7' : '#FEE2E2') ?>;
                               color:<?= $bs['attendance_rate'] >= 90 ? '#065F46' : ($bs['attendance_rate'] >= 70 ? '#92400E' : '#991B1B') ?>;
                               padding:4px 12px;border-radius:12px;font-weight:700;font-size:.88rem">
                            <?= $bs['attendance_rate'] ?>%
                        </span>
                    </td>
                    <td style="padding:12px 14px;text-align:center;color:#10B981;font-weight:600"><?= $bs['present_days'] ?></td>
                    <td style="padding:12px 14px;text-align:center;color:#EF4444;font-weight:600"><?= $bs['absent_days'] ?></td>
                    <td style="padding:12px 14px;text-align:center;color:#D97706;font-weight:600"><?= $bs['late_count'] ?></td>
                    <td style="padding:12px 14px;text-align:center;font-size:.85rem"><?= $bs['avg_late_min'] ?> دقيقة</td>
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
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
}
</style>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div>
</body></html>
