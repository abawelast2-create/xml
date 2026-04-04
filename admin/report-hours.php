<?php
// =============================================================
// admin/report-hours.php - تقرير ساعات العمل الفعلية
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'تقرير ساعات العمل';
$activePage = 'report-hours';

// الفلاتر
$dateFrom    = $_GET['date_from'] ?? date('Y-m-01');
$dateTo      = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
$branchId    = (int)($_GET['branch_id'] ?? 0);
$employeeId  = (int)($_GET['employee_id'] ?? 0);
$filterShift = (int)($_GET['shift'] ?? 0);
$groupBy     = in_array($_GET['group_by'] ?? '', ['daily', 'weekly', 'monthly']) ? $_GET['group_by'] : 'daily';

// بناء الاستعلام — جلب كل سجلات الحضور/الانصراف
$where  = ["a.attendance_date BETWEEN ? AND ?", "e.is_active = 1", "e.deleted_at IS NULL"];
$params = [$dateFrom, $dateTo];

if ($employeeId > 0) { $where[] = "e.id = ?";        $params[] = $employeeId; }
if ($branchId > 0)   { $where[] = "e.branch_id = ?";  $params[] = $branchId; }
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift);
    if ($sf) { $where[] = $sf['sql']; $params = array_merge($params, $sf['params']); }
}

$whereStr = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT a.employee_id, a.attendance_date, a.type, a.timestamp, a.late_minutes,
           e.name AS employee_name, e.job_title, e.branch_id,
           b.name AS branch_name
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$whereStr}
    ORDER BY a.employee_id, a.attendance_date, a.timestamp
");
$stmt->execute($params);
$allRecords = $stmt->fetchAll();

// جلب ورديات الفروع
$branchShiftsMap = [];
$bsStmt = db()->query("SELECT branch_id, shift_number, shift_start, shift_end FROM branch_shifts WHERE is_active = 1 ORDER BY branch_id, shift_number");
foreach ($bsStmt->fetchAll() as $s) {
    $branchShiftsMap[$s['branch_id']][] = $s;
}

// تجميع السجلات حسب الموظف واليوم
$empDays = [];
foreach ($allRecords as $rec) {
    $eid  = $rec['employee_id'];
    $date = $rec['attendance_date'];
    if (!isset($empDays[$eid])) {
        $empDays[$eid] = [
            'name'       => $rec['employee_name'],
            'job_title'  => $rec['job_title'],
            'branch_name'=> $rec['branch_name'],
            'branch_id'  => $rec['branch_id'],
            'days'       => [],
        ];
    }
    $empDays[$eid]['days'][$date][] = $rec;
}

// حساب ساعات العمل
$dailyDetails = [];
$empSummary   = [];
foreach ($empDays as $eid => $emp) {
    $totalMinutes    = 0;
    $totalDays       = 0;
    $totalLate       = 0;
    $totalOvertimeMin = 0;

    foreach ($emp['days'] as $date => $recs) {
        // فصل check-in و check-out
        $ins = $outs = [];
        $otStarts = $otEnds = [];
        foreach ($recs as $r) {
            if ($r['type'] === 'in')             $ins[]      = strtotime($r['timestamp']);
            elseif ($r['type'] === 'out')        $outs[]     = strtotime($r['timestamp']);
            elseif ($r['type'] === 'overtime-start') $otStarts[] = strtotime($r['timestamp']);
            elseif ($r['type'] === 'overtime-end')   $otEnds[]   = strtotime($r['timestamp']);
        }
        sort($ins);
        sort($outs);

        // حساب ساعات العمل العادية (أول in إلى آخر out)
        $workMinutes = 0;
        if (!empty($ins) && !empty($outs)) {
            $firstIn = $ins[0];
            $lastOut = end($outs);
            if ($lastOut > $firstIn) {
                $workMinutes = (int)round(($lastOut - $firstIn) / 60);
            }
        }

        // حساب الأوفرتايم
        $otMinutes = 0;
        $otCount = min(count($otStarts), count($otEnds));
        for ($i = 0; $i < $otCount; $i++) {
            if ($otEnds[$i] > $otStarts[$i]) {
                $otMinutes += (int)round(($otEnds[$i] - $otStarts[$i]) / 60);
            }
        }

        // تحديد الوردية
        $shifts = $branchShiftsMap[$emp['branch_id']] ?? [];
        $shiftNum = 1;
        if (!empty($ins) && !empty($shifts)) {
            $shiftNum = assignTimeToShift(date('H:i', $ins[0]), $shifts);
        }

        $lateMin = 0;
        foreach ($recs as $r) {
            if ($r['type'] === 'in' && $r['late_minutes'] > 0) $lateMin += $r['late_minutes'];
        }

        if ($workMinutes > 0) $totalDays++;
        $totalMinutes += $workMinutes;
        $totalLate += $lateMin;
        $totalOvertimeMin += $otMinutes;

        $dailyDetails[] = [
            'employee_id'   => $eid,
            'employee_name' => $emp['name'],
            'job_title'     => $emp['job_title'],
            'branch_name'   => $emp['branch_name'],
            'date'          => $date,
            'shift_number'  => $shiftNum,
            'first_in'      => !empty($ins) ? date('h:i A', $ins[0]) : '-',
            'last_out'      => !empty($outs) ? date('h:i A', end($outs)) : '-',
            'work_minutes'  => $workMinutes,
            'work_hours'    => sprintf('%d:%02d', intdiv($workMinutes, 60), $workMinutes % 60),
            'late_minutes'  => $lateMin,
            'ot_minutes'    => $otMinutes,
        ];
    }

    $empSummary[$eid] = [
        'name'           => $emp['name'],
        'job_title'      => $emp['job_title'],
        'branch_name'    => $emp['branch_name'],
        'total_days'     => $totalDays,
        'total_minutes'  => $totalMinutes,
        'total_hours'    => sprintf('%d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60),
        'avg_hours'      => $totalDays > 0 ? sprintf('%d:%02d', intdiv((int)($totalMinutes / $totalDays), 60), (int)($totalMinutes / $totalDays) % 60) : '0:00',
        'total_late'     => $totalLate,
        'total_overtime' => $totalOvertimeMin,
    ];
}

uasort($empSummary, fn($a, $b) => $b['total_minutes'] <=> $a['total_minutes']);

$grandTotalMinutes = array_sum(array_column($empSummary, 'total_minutes'));
$grandTotalDays    = array_sum(array_column($empSummary, 'total_days'));
$uniqueEmployees   = count($empSummary);
$grandTotalOT      = array_sum(array_column($empSummary, 'total_overtime'));

// قوائم
$employees = db()->query("SELECT id, name FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();
$branches  = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="work_hours_' . $dateFrom . '_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (($_GET['csv_type'] ?? '') === 'detail') {
        fputcsv($out, ['الموظف', 'الوظيفة', 'الفرع', 'التاريخ', 'الوردية', 'أول حضور', 'آخر انصراف', 'ساعات العمل', 'التأخير (دقيقة)', 'أوفرتايم (دقيقة)']);
        foreach ($dailyDetails as $d) {
            fputcsv($out, [$d['employee_name'], $d['job_title'], $d['branch_name'] ?? '-', $d['date'], 'و'.$d['shift_number'], $d['first_in'], $d['last_out'], $d['work_hours'], $d['late_minutes'], $d['ot_minutes']]);
        }
    } else {
        fputcsv($out, ['الموظف', 'الوظيفة', 'الفرع', 'أيام العمل', 'إجمالي الساعات', 'متوسط يومي', 'تأخير (دقيقة)', 'أوفرتايم (دقيقة)']);
        foreach ($empSummary as $s) {
            fputcsv($out, [$s['name'], $s['job_title'], $s['branch_name'] ?? '-', $s['total_days'], $s['total_hours'], $s['avg_hours'], $s['total_late'], $s['total_overtime']]);
        }
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
.filter-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-top:12px }
.hours-bar { background:var(--surface3); border-radius:4px; height:8px; overflow:hidden; min-width:60px }
.hours-bar-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#3B82F6,#2563EB) }
.tab-btns { display:flex; gap:8px; margin-bottom:16px }
.tab-btn { padding:8px 20px; border-radius:8px; border:2px solid var(--surface3); background:var(--surface1); cursor:pointer; font-weight:600; color:var(--text2); transition:.2s }
.tab-btn.active { border-color:var(--primary); background:var(--primary); color:#fff }
.tab-pane { display:none } .tab-pane.active { display:block }
</style>
<?php
$reportTitle    = 'تقرير ساعات العمل الفعلية';
$reportSubtitle = 'نظام الحضور والانصراف';
$reportMeta     = ["الفترة: {$dateFrom} إلى {$dateTo}"];
require __DIR__ . '/../includes/report_print_header.php';
?>

<!-- الفلاتر -->
<div class="report-filter">
    <form method="GET" class="filter-bar">
        <div class="form-group"><label>من تاريخ</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></div>
        <div class="form-group"><label>إلى تاريخ</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></div>
        <div class="form-group">
            <label>الفرع</label>
            <select class="form-control" name="branch_id" id="branchSelect">
                <option value="0">الكل</option>
                <?php foreach ($branches as $br): ?>
                <option value="<?= $br['id'] ?>" <?= $branchId == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>الوردية</label>
            <select class="form-control" name="shift" id="shiftSelect">
                <option value="0">كل الورديات</option>
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
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn-export"><?= svgIcon('backup', 16) ?> ملخص CSV</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv','csv_type'=>'detail'])) ?>" class="btn-export"><?= svgIcon('backup', 16) ?> تفصيلي CSV</a>
        </div>
    </form>
</div>

<!-- الإحصائيات -->
<div class="report-stats">
    <div class="report-stat accent-blue"><div class="report-stat-icon is-blue"><?= svgIcon('employees', 24) ?></div><div><div class="report-stat-value"><?= $uniqueEmployees ?></div><div class="report-stat-label">موظف</div></div></div>
    <div class="report-stat accent-green"><div class="report-stat-icon is-green"><?= svgIcon('clock', 24) ?></div><div><div class="report-stat-value"><?= sprintf('%d:%02d', intdiv($grandTotalMinutes, 60), $grandTotalMinutes % 60) ?></div><div class="report-stat-label">إجمالي الساعات</div></div></div>
    <div class="report-stat accent-orange"><div class="report-stat-icon is-orange"><?= svgIcon('calendar', 24) ?></div><div><div class="report-stat-value"><?= number_format($grandTotalDays) ?></div><div class="report-stat-label">أيام العمل</div></div></div>
    <div class="report-stat accent-purple"><div class="report-stat-icon is-purple"><?= svgIcon('overtime', 24) ?></div><div><div class="report-stat-value"><?= sprintf('%d:%02d', intdiv($grandTotalOT, 60), $grandTotalOT % 60) ?></div><div class="report-stat-label">إجمالي الأوفرتايم</div></div></div>
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
            <span class="card-title"><span class="card-title-bar"></span> ملخص ساعات العمل</span>
            <span class="badge badge-blue"><?= $uniqueEmployees ?> موظف</span>
        </div>
        <div style="overflow-x:auto">
        <table class="att-table">
            <thead><tr>
                <th>#</th><th>الموظف</th><th>الفرع</th><th>أيام العمل</th>
                <th>إجمالي الساعات</th><th>متوسط يومي</th><th>التأخير</th><th>أوفرتايم</th><th>شريط</th>
            </tr></thead>
            <tbody>
            <?php if (empty($empSummary)): ?>
                <tr><td colspan="9" class="report-empty" style="padding:40px"><p>لا يوجد بيانات</p></td></tr>
            <?php else: ?>
                <?php $maxMin = max(array_column($empSummary, 'total_minutes') ?: [1]); $rank = 0; ?>
                <?php foreach ($empSummary as $eid => $s): $rank++; ?>
                <tr>
                    <td><?= $rank ?></td>
                    <td><strong><?= htmlspecialchars($s['name']) ?></strong><br><small style="color:var(--text3)"><?= htmlspecialchars($s['job_title']) ?></small></td>
                    <td><?= htmlspecialchars($s['branch_name'] ?? '-') ?></td>
                    <td><span class="badge badge-blue"><?= $s['total_days'] ?></span></td>
                    <td><strong><?= $s['total_hours'] ?></strong></td>
                    <td><?= $s['avg_hours'] ?></td>
                    <td><?php if ($s['total_late'] > 0): ?><span class="badge badge-red"><?= $s['total_late'] ?> د</span><?php else: ?>-<?php endif; ?></td>
                    <td><?php if ($s['total_overtime'] > 0): ?><span class="badge badge-green"><?= sprintf('%d:%02d', intdiv($s['total_overtime'], 60), $s['total_overtime'] % 60) ?></span><?php else: ?>-<?php endif; ?></td>
                    <td><div class="hours-bar"><div class="hours-bar-fill" style="width:<?= $maxMin > 0 ? round($s['total_minutes'] / $maxMin * 100) : 0 ?>%"></div></div></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- التفاصيل اليومية -->
<div id="tab-detail" class="tab-pane">
    <div class="report-table-wrap">
        <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
            <span class="card-title"><span class="card-title-bar"></span> التفاصيل اليومية</span>
            <span class="badge badge-blue"><?= count($dailyDetails) ?> سجل</span>
        </div>
        <div style="overflow-x:auto">
        <table class="att-table">
            <thead><tr>
                <th>#</th><th>الموظف</th><th>الفرع</th><th>التاريخ</th><th>الوردية</th>
                <th>أول حضور</th><th>آخر انصراف</th><th>ساعات العمل</th><th>التأخير</th><th>أوفرتايم</th>
            </tr></thead>
            <tbody>
            <?php if (empty($dailyDetails)): ?>
                <tr><td colspan="10" class="report-empty" style="padding:40px"><p>لا يوجد بيانات</p></td></tr>
            <?php else: ?>
                <?php foreach ($dailyDetails as $i => $d): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($d['employee_name']) ?></strong></td>
                    <td><?= htmlspecialchars($d['branch_name'] ?? '-') ?></td>
                    <td><?= $d['date'] ?></td>
                    <td><span class="badge badge-blue">و<?= $d['shift_number'] ?></span></td>
                    <td><?= $d['first_in'] ?></td>
                    <td><?= $d['last_out'] ?></td>
                    <td><strong><?= $d['work_hours'] ?></strong></td>
                    <td><?php if ($d['late_minutes'] > 0): ?><span class="badge badge-red"><?= $d['late_minutes'] ?> د</span><?php else: ?>-<?php endif; ?></td>
                    <td><?php if ($d['ot_minutes'] > 0): ?><span class="badge badge-green"><?= $d['ot_minutes'] ?> د</span><?php else: ?>-<?php endif; ?></td>
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

// تحميل الورديات ديناميكياً
const allShifts = <?= json_encode(getAllBranchShifts()) ?>;
document.getElementById('branchSelect')?.addEventListener('change', function() {
    const sel = document.getElementById('shiftSelect');
    sel.innerHTML = '<option value="0">كل الورديات</option>';
    const bShifts = allShifts[this.value] || [];
    bShifts.forEach(s => {
        sel.innerHTML += `<option value="${s.id}">الوردية ${s.num} (${s.start} - ${s.end})</option>`;
    });
});
// تهيئة
(function() {
    const bv = document.getElementById('branchSelect')?.value;
    if (bv && bv !== '0' && allShifts[bv]) {
        const sel = document.getElementById('shiftSelect');
        sel.innerHTML = '<option value="0">كل الورديات</option>';
        allShifts[bv].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id; opt.textContent = `الوردية ${s.num} (${s.start} - ${s.end})`;
            if (s.id == <?= $filterShift ?>) opt.selected = true;
            sel.appendChild(opt);
        });
    }
})();
</script>

<style>
@media print {
    .sidebar, .topbar, .bottom-nav, form, .no-print, .report-filter, .filter-grid, .tab-btns { display: none !important; }
    .main-content { margin: 0 !important; }
    .content { padding: 0 !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #e5dcc8; }
    .print-report-header, .print-report-footer { display: block !important; }
    .tab-pane { display: block !important; page-break-before: auto; }
    .hours-bar { border: 1px solid #ddd; }
    .hours-bar-fill { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<?php require __DIR__ . '/../includes/report_print_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
