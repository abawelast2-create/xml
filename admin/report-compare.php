<?php
// =============================================================
// admin/report-compare.php - تقرير المقارنات
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'تقرير المقارنات';
$activePage = 'report-compare';

// نوع المقارنة
$compareType = in_array($_GET['type'] ?? '', ['periods', 'branches', 'employees']) ? $_GET['type'] : 'periods';

// الفلاتر
$dateFrom1 = $_GET['date_from_1'] ?? date('Y-m-01', strtotime('-1 month'));
$dateTo1   = $_GET['date_to_1']   ?? date('Y-m-t', strtotime('-1 month'));
$dateFrom2 = $_GET['date_from_2'] ?? date('Y-m-01');
$dateTo2   = $_GET['date_to_2']   ?? date('Y-m-d');

foreach (['dateFrom1','dateTo1','dateFrom2','dateTo2'] as $v) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) $$v = date('Y-m-d');
}

$branchId1  = (int)($_GET['branch_1'] ?? 0);
$branchId2  = (int)($_GET['branch_2'] ?? 0);
$empId1     = (int)($_GET['emp_1'] ?? 0);
$empId2     = (int)($_GET['emp_2'] ?? 0);

// إعدادات
$weekendDaysStr = getSystemSetting('weekend_days', '5');
$weekendDays = array_map('intval', explode(',', $weekendDaysStr));

// دالة مساعدة لحساب إحصائيات فترة
function calcPeriodStats(string $dateFrom, string $dateTo, array $weekendDays, int $branchFilter = 0, int $empFilter = 0): array {
    $holidayDates = [];
    try {
        $hStmt = db()->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $hStmt->execute([$dateFrom, $dateTo]);
        $holidayDates = array_column($hStmt->fetchAll(), 'holiday_date');
    } catch (Exception $e) {}

    $workingDays = 0;
    $cur = strtotime($dateFrom); $end = strtotime($dateTo);
    while ($cur <= $end) {
        $dow = (int)date('w', $cur); $d = date('Y-m-d', $cur);
        if (!in_array($dow, $weekendDays) && !in_array($d, $holidayDates)) $workingDays++;
        $cur = strtotime('+1 day', $cur);
    }

    $attWhere = ["a.attendance_date BETWEEN ? AND ?", "e.is_active = 1", "e.deleted_at IS NULL"];
    $attParams = [$dateFrom, $dateTo];
    if ($branchFilter > 0) { $attWhere[] = "e.branch_id = ?"; $attParams[] = $branchFilter; }
    if ($empFilter > 0) { $attWhere[] = "e.id = ?"; $attParams[] = $empFilter; }
    $w = implode(' AND ', $attWhere);

    $stmt = db()->prepare("
        SELECT a.employee_id, a.attendance_date, a.type, a.timestamp, a.late_minutes, a.early_minutes
        FROM attendances a JOIN employees e ON a.employee_id = e.id
        WHERE {$w}
        ORDER BY a.employee_id, a.attendance_date, a.timestamp
    ");
    $stmt->execute($attParams);
    $allAtt = $stmt->fetchAll();

    $empCount = 0;
    $cntSql = "SELECT COUNT(*) FROM employees e WHERE e.is_active = 1 AND e.deleted_at IS NULL";
    $cntParams = [];
    if ($branchFilter > 0) { $cntSql .= " AND e.branch_id = ?"; $cntParams[] = $branchFilter; }
    if ($empFilter > 0) { $cntSql .= " AND e.id = ?"; $cntParams[] = $empFilter; }
    $cntStmt = db()->prepare($cntSql);
    $cntStmt->execute($cntParams);
    $empCount = (int)$cntStmt->fetchColumn();

    $grouped = [];
    foreach ($allAtt as $a) $grouped[$a['employee_id']][$a['attendance_date']][] = $a;

    $totalPresent = 0;
    $totalLateDay = 0;
    $totalLateMins = 0;
    $totalEarlyDay = 0;
    $totalWorkMin = 0;
    $totalOTMin = 0;
    $empsPresent = 0;

    foreach ($grouped as $eid => $days) {
        $empPresent = false;
        foreach ($days as $date => $recs) {
            $dow = (int)date('w', strtotime($date));
            if (in_array($dow, $weekendDays) || in_array($date, $holidayDates)) continue;

            $hasIn = false; $dayLate = 0; $dayEarly = 0;
            $ins = $outs = $otS = $otE = [];
            foreach ($recs as $r) {
                if ($r['type'] === 'in') { $hasIn = true; $ins[] = strtotime($r['timestamp']); $dayLate += (int)$r['late_minutes']; $dayEarly += (int)$r['early_minutes']; }
                elseif ($r['type'] === 'out') $outs[] = strtotime($r['timestamp']);
                elseif ($r['type'] === 'overtime-start') $otS[] = strtotime($r['timestamp']);
                elseif ($r['type'] === 'overtime-end') $otE[] = strtotime($r['timestamp']);
            }
            if ($hasIn) { $totalPresent++; $empPresent = true; }
            if ($dayLate > 0) { $totalLateDay++; $totalLateMins += $dayLate; }
            if ($dayEarly > 0) $totalEarlyDay++;

            sort($ins); sort($outs);
            if (!empty($ins) && !empty($outs) && end($outs) > $ins[0])
                $totalWorkMin += (int)round((end($outs) - $ins[0]) / 60);

            sort($otS); sort($otE);
            for ($i = 0; $i < min(count($otS), count($otE)); $i++)
                if ($otE[$i] > $otS[$i]) $totalOTMin += (int)round(($otE[$i] - $otS[$i]) / 60);
        }
        if ($empPresent) $empsPresent++;
    }

    $totalAbsent = max(0, ($empCount * $workingDays) - $totalPresent);
    $attRate = ($empCount * $workingDays) > 0 ? round($totalPresent / ($empCount * $workingDays) * 100, 1) : 0;

    return [
        'working_days'  => $workingDays,
        'emp_count'     => $empCount,
        'present_days'  => $totalPresent,
        'absent_days'   => $totalAbsent,
        'late_days'     => $totalLateDay,
        'late_minutes'  => $totalLateMins,
        'early_days'    => $totalEarlyDay,
        'work_hours'    => sprintf('%d:%02d', intdiv($totalWorkMin, 60), $totalWorkMin % 60),
        'ot_hours'      => sprintf('%d:%02d', intdiv($totalOTMin, 60), $totalOTMin % 60),
        'attendance_rate'=> $attRate,
        'avg_late'      => $totalLateDay > 0 ? round($totalLateMins / $totalLateDay) : 0,
    ];
}

// حساب البيانات
$stats1 = $stats2 = null;
$label1 = $label2 = '';

if ($compareType === 'periods') {
    $stats1 = calcPeriodStats($dateFrom1, $dateTo1, $weekendDays);
    $stats2 = calcPeriodStats($dateFrom2, $dateTo2, $weekendDays);
    $label1 = "{$dateFrom1} → {$dateTo1}";
    $label2 = "{$dateFrom2} → {$dateTo2}";
} elseif ($compareType === 'branches') {
    $branches = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();
    if ($branchId1 > 0) $stats1 = calcPeriodStats($dateFrom1, $dateTo1, $weekendDays, $branchId1);
    if ($branchId2 > 0) $stats2 = calcPeriodStats($dateFrom1, $dateTo1, $weekendDays, $branchId2);
    $branchNames = array_column($branches, 'name', 'id');
    $label1 = $branchNames[$branchId1] ?? 'الفرع 1';
    $label2 = $branchNames[$branchId2] ?? 'الفرع 2';
} elseif ($compareType === 'employees') {
    if ($empId1 > 0) $stats1 = calcPeriodStats($dateFrom1, $dateTo1, $weekendDays, 0, $empId1);
    if ($empId2 > 0) $stats2 = calcPeriodStats($dateFrom1, $dateTo1, $weekendDays, 0, $empId2);
    $empNames = array_column(db()->query("SELECT id, name FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll(), 'name', 'id');
    $label1 = $empNames[$empId1] ?? 'الموظف 1';
    $label2 = $empNames[$empId2] ?? 'الموظف 2';
}

$branchesList  = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();
$employeesList = db()->query("SELECT id, name FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();

// دالة عرض فرق
function showDiff($v1, $v2, bool $higherBetter = true): string {
    if ($v1 == $v2) return '<span style="color:var(--text3)">—</span>';
    $diff = $v2 - $v1;
    $pct  = $v1 > 0 ? round(($diff / $v1) * 100, 1) : 0;
    $sign = $diff > 0 ? '+' : '';
    $good = ($diff > 0 && $higherBetter) || ($diff < 0 && !$higherBetter);
    $color = $good ? '#10B981' : '#EF4444';
    return "<span style='color:{$color};font-weight:600'>{$sign}{$diff} ({$sign}{$pct}%)</span>";
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
.compare-tabs { display:flex; gap:8px; margin-bottom:20px }
.compare-tab { padding:10px 24px; border-radius:8px; border:2px solid var(--surface3); background:var(--surface1); cursor:pointer; font-weight:600; color:var(--text2); text-decoration:none; transition:.2s; font-size:.88rem }
.compare-tab.active { border-color:var(--primary); background:var(--primary); color:#fff }
.compare-table th.period { padding:10px; text-align:center; font-size:.9rem }
.compare-table td { padding:10px 14px }
.compare-table .metric { font-weight:600; color:var(--text2) }
.metric-icon { display:inline-flex; width:28px; height:28px; align-items:center; justify-content:center; border-radius:6px; margin-left:8px }
</style>
<?php
$reportTitle    = 'تقرير المقارنات';
$reportSubtitle = 'نظام الحضور والانصراف';
$reportMeta     = [];
require __DIR__ . '/../includes/report_print_header.php';
?>

<!-- تبويبات نوع المقارنة -->
<div class="compare-tabs no-print">
    <a href="?type=periods" class="compare-tab <?= $compareType === 'periods' ? 'active' : '' ?>"><?= svgIcon('calendar', 16) ?> مقارنة فترات</a>
    <a href="?type=branches" class="compare-tab <?= $compareType === 'branches' ? 'active' : '' ?>"><?= svgIcon('branch', 16) ?> مقارنة فروع</a>
    <a href="?type=employees" class="compare-tab <?= $compareType === 'employees' ? 'active' : '' ?>"><?= svgIcon('employees', 16) ?> مقارنة موظفين</a>
</div>

<!-- الفلاتر -->
<div class="report-filter">
    <form method="GET" class="filter-bar">
        <input type="hidden" name="type" value="<?= $compareType ?>">

        <?php if ($compareType === 'periods'): ?>
        <div class="form-group"><label>الفترة الأولى — من</label><input class="form-control" type="date" name="date_from_1" value="<?= htmlspecialchars($dateFrom1) ?>"></div>
        <div class="form-group"><label>إلى</label><input class="form-control" type="date" name="date_to_1" value="<?= htmlspecialchars($dateTo1) ?>"></div>
        <div class="form-group"><label>الفترة الثانية — من</label><input class="form-control" type="date" name="date_from_2" value="<?= htmlspecialchars($dateFrom2) ?>"></div>
        <div class="form-group"><label>إلى</label><input class="form-control" type="date" name="date_to_2" value="<?= htmlspecialchars($dateTo2) ?>"></div>

        <?php elseif ($compareType === 'branches'): ?>
        <div class="form-group"><label>من تاريخ</label><input class="form-control" type="date" name="date_from_1" value="<?= htmlspecialchars($dateFrom1) ?>"></div>
        <div class="form-group"><label>إلى تاريخ</label><input class="form-control" type="date" name="date_to_1" value="<?= htmlspecialchars($dateTo1) ?>"></div>
        <div class="form-group"><label>الفرع الأول</label>
            <select class="form-control" name="branch_1"><?php foreach ($branchesList as $br): ?><option value="<?= $br['id'] ?>" <?= $branchId1 == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>الفرع الثاني</label>
            <select class="form-control" name="branch_2"><?php foreach ($branchesList as $br): ?><option value="<?= $br['id'] ?>" <?= $branchId2 == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option><?php endforeach; ?></select>
        </div>

        <?php elseif ($compareType === 'employees'): ?>
        <div class="form-group"><label>من تاريخ</label><input class="form-control" type="date" name="date_from_1" value="<?= htmlspecialchars($dateFrom1) ?>"></div>
        <div class="form-group"><label>إلى تاريخ</label><input class="form-control" type="date" name="date_to_1" value="<?= htmlspecialchars($dateTo1) ?>"></div>
        <div class="form-group"><label>الموظف الأول</label>
            <select class="form-control" name="emp_1"><?php foreach ($employeesList as $e): ?><option value="<?= $e['id'] ?>" <?= $empId1 == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>الموظف الثاني</label>
            <select class="form-control" name="emp_2"><?php foreach ($employeesList as $e): ?><option value="<?= $e['id'] ?>" <?= $empId2 == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option><?php endforeach; ?></select>
        </div>
        <?php endif; ?>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= svgIcon('compare', 16) ?> مقارنة</button>
            <button type="button" onclick="window.print()" class="btn-export"><?= svgIcon('document', 16) ?> طباعة</button>
        </div>
    </form>
</div>

<!-- جدول المقارنة -->
<?php if ($stats1 && $stats2): ?>
<div class="report-table-wrap">
    <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
        <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('compare', 18) ?> نتيجة المقارنة</span>
    </div>
    <div style="overflow-x:auto">
    <table class="att-table compare-table">
        <thead>
        <tr>
            <th>المؤشر</th>
            <th class="period" style="background:rgba(59,130,246,.06)"><?= htmlspecialchars($label1) ?></th>
            <th class="period" style="background:rgba(16,185,129,.06)"><?= htmlspecialchars($label2) ?></th>
            <th>الفرق</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $metrics = [
            ['أيام العمل',     'working_days',    true],
            ['عدد الموظفين',    'emp_count',        true],
            ['أيام الحضور',     'present_days',     true],
            ['أيام الغياب',     'absent_days',      false],
            ['أيام التأخير',    'late_days',        false],
            ['دقائق التأخير',   'late_minutes',     false],
            ['متوسط التأخير',   'avg_late',         false],
            ['أيام التبكير',    'early_days',       true],
            ['ساعات العمل',     'work_hours',       true],
            ['ساعات الأوفرتايم', 'ot_hours',        true],
            ['نسبة الحضور %',   'attendance_rate',  true],
        ];
        foreach ($metrics as [$mLabel, $mKey, $mHigherBetter]):
            $v1 = $stats1[$mKey]; $v2 = $stats2[$mKey];
        ?>
        <tr>
            <td class="metric"><?= $mLabel ?></td>
            <td style="text-align:center;font-weight:600"><?= is_numeric($v1) ? number_format((float)$v1, (is_float($v1) && $v1 != (int)$v1) ? 1 : 0) : $v1 ?></td>
            <td style="text-align:center;font-weight:600"><?= is_numeric($v2) ? number_format((float)$v2, (is_float($v2) && $v2 != (int)$v2) ? 1 : 0) : $v2 ?></td>
            <td style="text-align:center"><?= (is_numeric($v1) && is_numeric($v2)) ? showDiff($v1, $v2, $mHigherBetter) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- رسم بياني -->
<div style="background:var(--surface1);border-radius:12px;padding:20px;border:1px solid var(--surface3);margin-top:20px">
    <canvas id="compareChart" height="80"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('compareChart'), {
    type: 'bar',
    data: {
        labels: ['حضور', 'غياب', 'تأخير', 'تبكير'],
        datasets: [
            { label: <?= json_encode($label1) ?>, data: [<?= $stats1['present_days'] ?>, <?= $stats1['absent_days'] ?>, <?= $stats1['late_days'] ?>, <?= $stats1['early_days'] ?>], backgroundColor: 'rgba(59,130,246,.7)', borderRadius: 6 },
            { label: <?= json_encode($label2) ?>, data: [<?= $stats2['present_days'] ?>, <?= $stats2['absent_days'] ?>, <?= $stats2['late_days'] ?>, <?= $stats2['early_days'] ?>], backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 6 }
        ]
    },
    options: { responsive: true, plugins: { legend: { labels: { font: { family: 'Tajawal' } } } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php else: ?>
<div class="report-empty" style="padding:60px;text-align:center">
    <div style="font-size:3rem;margin-bottom:12px"><?= svgIcon('compare', 48) ?></div>
    <p>اختر عناصر المقارنة ثم اضغط "مقارنة"</p>
</div>
<?php endif; ?>

<style>
@media print {
    .sidebar, .topbar, .bottom-nav, form, .no-print, .report-filter, .compare-tabs { display: none !important; }
    .main-content { margin: 0 !important; }
    .content { padding: 0 !important; }
    .card, .chart-wrap { break-inside: avoid; box-shadow: none !important; border: 1px solid #e5dcc8; }
    .compare-table th, .compare-table td { padding: 6px 8px !important; }
    .print-report-header, .print-report-footer { display: block !important; }
    .chart-wrap canvas { max-height: 200px !important; }
}
</style>

<?php require __DIR__ . '/../includes/report_print_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
