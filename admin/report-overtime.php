<?php
// =============================================================
// admin/report-overtime.php - تقرير العمل الإضافي (الأوفرتايم)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'تقرير الأوفرتايم';
$activePage = 'report-overtime';

// الفلاتر
$dateFrom    = $_GET['date_from'] ?? date('Y-m-01');
$dateTo      = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
$branchId    = (int)($_GET['branch_id'] ?? 0);
$employeeId  = (int)($_GET['employee_id'] ?? 0);

// استعلام بيانات الأوفرتايم
$where  = ["a.attendance_date BETWEEN ? AND ?", "a.type IN ('overtime-start','overtime-end')", "e.is_active = 1", "e.deleted_at IS NULL"];
$params = [$dateFrom, $dateTo];

if ($employeeId > 0) { $where[] = "e.id = ?";        $params[] = $employeeId; }
if ($branchId > 0)   { $where[] = "e.branch_id = ?";  $params[] = $branchId; }

$whereStr = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT a.employee_id, a.attendance_date, a.type, a.timestamp,
           e.name AS employee_name, e.job_title, e.branch_id, e.hourly_rate, e.salary,
           b.name AS branch_name
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$whereStr}
    ORDER BY a.employee_id, a.attendance_date, a.timestamp
");
$stmt->execute($params);
$allRecords = $stmt->fetchAll();

$otMultiplier = (float)getSystemSetting('overtime_rate_multiplier', '1.5');

// تجميع حسب الموظف واليوم
$empOT = [];
$dailyDetails = [];

$grouped = [];
foreach ($allRecords as $rec) {
    $grouped[$rec['employee_id']][$rec['attendance_date']][] = $rec;
}

foreach ($grouped as $eid => $days) {
    $firstRec = null;
    $totalMin = 0;
    $totalSessions = 0;

    foreach ($days as $date => $recs) {
        $starts = $ends = [];
        foreach ($recs as $r) {
            if (!$firstRec) $firstRec = $r;
            if ($r['type'] === 'overtime-start') $starts[] = strtotime($r['timestamp']);
            if ($r['type'] === 'overtime-end')   $ends[]   = strtotime($r['timestamp']);
        }
        sort($starts);
        sort($ends);

        $pairs = min(count($starts), count($ends));
        $dayMinutes = 0;
        for ($i = 0; $i < $pairs; $i++) {
            if ($ends[$i] > $starts[$i]) {
                $dayMinutes += (int)round(($ends[$i] - $starts[$i]) / 60);
            }
        }

        if ($dayMinutes > 0) {
            $totalMin += $dayMinutes;
            $totalSessions += $pairs;
            $dailyDetails[] = [
                'employee_id'   => $eid,
                'employee_name' => $firstRec['employee_name'],
                'job_title'     => $firstRec['job_title'],
                'branch_name'   => $firstRec['branch_name'],
                'date'          => $date,
                'sessions'      => $pairs,
                'ot_minutes'    => $dayMinutes,
                'ot_hours'      => sprintf('%d:%02d', intdiv($dayMinutes, 60), $dayMinutes % 60),
                'start_time'    => !empty($starts) ? date('h:i A', $starts[0]) : '-',
                'end_time'      => !empty($ends) ? date('h:i A', end($ends)) : '-',
            ];
        }
    }

    if ($firstRec && $totalMin > 0) {
        $hourlyRate = (float)$firstRec['hourly_rate'];
        if ($hourlyRate <= 0 && (float)$firstRec['salary'] > 0) {
            $hourlyRate = round((float)$firstRec['salary'] / 30 / 8, 2);
        }
        $otCost = round(($totalMin / 60) * $hourlyRate * $otMultiplier, 2);

        $empOT[$eid] = [
            'name'           => $firstRec['employee_name'],
            'job_title'      => $firstRec['job_title'],
            'branch_name'    => $firstRec['branch_name'],
            'total_sessions' => $totalSessions,
            'total_minutes'  => $totalMin,
            'total_hours'    => sprintf('%d:%02d', intdiv($totalMin, 60), $totalMin % 60),
            'hourly_rate'    => $hourlyRate,
            'ot_cost'        => $otCost,
        ];
    }
}

uasort($empOT, fn($a, $b) => $b['total_minutes'] <=> $a['total_minutes']);

$grandTotalOT   = array_sum(array_column($empOT, 'total_minutes'));
$grandTotalCost  = array_sum(array_column($empOT, 'ot_cost'));
$grandSessions   = array_sum(array_column($empOT, 'total_sessions'));
$uniqueEmployees = count($empOT);

// قوائم
$employees = db()->query("SELECT id, name FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();
$branches  = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="overtime_report_' . $dateFrom . '_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['الموظف', 'الوظيفة', 'الفرع', 'عدد الجلسات', 'إجمالي الساعات', 'سعر الساعة', 'التكلفة']);
    foreach ($empOT as $s) {
        fputcsv($out, [$s['name'], $s['job_title'], $s['branch_name'] ?? '-', $s['total_sessions'], $s['total_hours'], $s['hourly_rate'], $s['ot_cost']]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
.filter-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-top:12px }
.tab-btns { display:flex; gap:8px; margin-bottom:16px }
.tab-btn { padding:8px 20px; border-radius:8px; border:2px solid var(--surface3); background:var(--surface1); cursor:pointer; font-weight:600; color:var(--text2); transition:.2s }
.tab-btn.active { border-color:var(--primary); background:var(--primary); color:#fff }
.tab-pane { display:none } .tab-pane.active { display:block }
.cost-value { color:#10B981; font-weight:700 }
</style>
<?php
$reportTitle    = 'تقرير العمل الإضافي (الأوفرتايم)';
$reportSubtitle = 'نظام الحضور والانصراف';
$reportMeta     = ["الفترة: {$dateFrom} إلى {$dateTo}", "معامل الأوفرتايم: {$otMultiplier}x"];
require __DIR__ . '/../includes/report_print_header.php';
?>

<!-- الفلاتر -->
<div class="report-filter">
    <form method="GET" class="filter-bar">
        <div class="form-group"><label>من تاريخ</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></div>
        <div class="form-group"><label>إلى تاريخ</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></div>
        <div class="form-group">
            <label>الفرع</label>
            <select class="form-control" name="branch_id">
                <option value="0">الكل</option>
                <?php foreach ($branches as $br): ?>
                <option value="<?= $br['id'] ?>" <?= $branchId == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>الموظف</label>
            <select class="form-control" name="employee_id">
                <option value="0">الكل</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $employeeId == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= svgIcon('attendance', 16) ?> بحث</button>
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn-export"><?= svgIcon('backup', 16) ?> تصدير CSV</a>
        </div>
    </form>
</div>

<!-- الإحصائيات -->
<div class="report-stats">
    <div class="report-stat accent-blue"><div class="report-stat-icon is-blue"><?= svgIcon('employees', 24) ?></div><div><div class="report-stat-value"><?= $uniqueEmployees ?></div><div class="report-stat-label">موظف لديه أوفرتايم</div></div></div>
    <div class="report-stat accent-green"><div class="report-stat-icon is-green"><?= svgIcon('overtime', 24) ?></div><div><div class="report-stat-value"><?= sprintf('%d:%02d', intdiv($grandTotalOT, 60), $grandTotalOT % 60) ?></div><div class="report-stat-label">إجمالي ساعات الأوفرتايم</div></div></div>
    <div class="report-stat accent-orange"><div class="report-stat-icon is-orange"><?= svgIcon('calendar', 24) ?></div><div><div class="report-stat-value"><?= $grandSessions ?></div><div class="report-stat-label">جلسات الأوفرتايم</div></div></div>
    <div class="report-stat accent-purple"><div class="report-stat-icon is-purple"><?= svgIcon('chart', 24) ?></div><div><div class="report-stat-value cost-value"><?= number_format($grandTotalCost, 2) ?></div><div class="report-stat-label">التكلفة التقديرية</div></div></div>
</div>

<!-- التبويبات -->
<div class="tab-btns no-print">
    <button class="tab-btn active" onclick="showTab('summary', this)">ملخص الموظفين</button>
    <button class="tab-btn" onclick="showTab('detail', this)">التفاصيل اليومية</button>
</div>

<!-- ملخص -->
<div id="tab-summary" class="tab-pane active">
    <div class="report-table-wrap">
        <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
            <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('overtime', 18) ?> ملخص الأوفرتايم</span>
        </div>
        <div style="overflow-x:auto">
        <table class="att-table">
            <thead><tr><th>#</th><th>الموظف</th><th>الفرع</th><th>الجلسات</th><th>إجمالي الساعات</th><th>سعر الساعة</th><th>التكلفة</th></tr></thead>
            <tbody>
            <?php if (empty($empOT)): ?>
                <tr><td colspan="7" class="report-empty" style="padding:40px"><p>لا يوجد بيانات أوفرتايم</p></td></tr>
            <?php else: ?>
                <?php $rank = 0; foreach ($empOT as $s): $rank++; ?>
                <tr>
                    <td><?= $rank ?></td>
                    <td><strong><?= htmlspecialchars($s['name']) ?></strong><br><small style="color:var(--text3)"><?= htmlspecialchars($s['job_title']) ?></small></td>
                    <td><?= htmlspecialchars($s['branch_name'] ?? '-') ?></td>
                    <td><span class="badge badge-blue"><?= $s['total_sessions'] ?></span></td>
                    <td><strong><?= $s['total_hours'] ?></strong></td>
                    <td><?= $s['hourly_rate'] > 0 ? number_format($s['hourly_rate'], 2) : '-' ?></td>
                    <td><span class="cost-value"><?= $s['ot_cost'] > 0 ? number_format($s['ot_cost'], 2) : '-' ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--surface2);font-weight:700">
                    <td colspan="3">الإجمالي</td>
                    <td><?= $grandSessions ?></td>
                    <td><?= sprintf('%d:%02d', intdiv($grandTotalOT, 60), $grandTotalOT % 60) ?></td>
                    <td>-</td>
                    <td class="cost-value"><?= number_format($grandTotalCost, 2) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- التفاصيل -->
<div id="tab-detail" class="tab-pane">
    <div class="report-table-wrap">
        <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
            <span class="card-title"><span class="card-title-bar"></span> تفاصيل الأوفرتايم اليومية</span>
            <span class="badge badge-blue"><?= count($dailyDetails) ?> سجل</span>
        </div>
        <div style="overflow-x:auto">
        <table class="att-table">
            <thead><tr><th>#</th><th>الموظف</th><th>الفرع</th><th>التاريخ</th><th>البداية</th><th>النهاية</th><th>المدة</th></tr></thead>
            <tbody>
            <?php if (empty($dailyDetails)): ?>
                <tr><td colspan="7" class="report-empty" style="padding:40px"><p>لا يوجد بيانات</p></td></tr>
            <?php else: ?>
                <?php foreach ($dailyDetails as $i => $d): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($d['employee_name']) ?></strong></td>
                    <td><?= htmlspecialchars($d['branch_name'] ?? '-') ?></td>
                    <td><?= $d['date'] ?></td>
                    <td><?= $d['start_time'] ?></td>
                    <td><?= $d['end_time'] ?></td>
                    <td><strong><?= $d['ot_hours'] ?></strong></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
function showTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (el) el.classList.add('active');
}
</script>

<style>
@media print {
    .sidebar, .topbar, .bottom-nav, form, .no-print, .report-filter, .filter-grid, .tab-btns { display: none !important; }
    .main-content { margin: 0 !important; }
    .content { padding: 0 !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #e5dcc8; }
    .print-report-header, .print-report-footer { display: block !important; }
    .tab-pane { display: block !important; page-break-before: auto; }
}
</style>

<?php require __DIR__ . '/../includes/report_print_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
