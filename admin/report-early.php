<?php
// =============================================================
// admin/report-early.php - تقرير المتميزين (التبكير)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'تقرير المتميزين (التبكير)';
$activePage = 'report-early';

// الفلاتر
$dateFrom   = $_GET['date_from']   ?? date('Y-m-01');
$dateTo     = $_GET['date_to']     ?? date('Y-m-d');
// التحقق من صحة التواريخ
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
$branchId   = (int)($_GET['branch_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$minEarly   = max(0, (int)($_GET['min_early'] ?? 1));
$filterShift = (int)($_GET['shift'] ?? 0);

// بناء الاستعلام
$where  = ["a.type = 'in'", "a.early_minutes >= ?", "a.attendance_date BETWEEN ? AND ?"];
$params = [$minEarly, $dateFrom, $dateTo];

if ($employeeId > 0) { $where[] = "e.id = ?"; $params[] = $employeeId; }
if ($branchId > 0)   { $where[] = "e.branch_id = ?"; $params[] = $branchId; }
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift);
    if ($sf) { $where[] = $sf['sql']; $params = array_merge($params, $sf['params']); }
}

$whereStr = implode(' AND ', $where);

// تفاصيل السجلات
$stmt = db()->prepare("
    SELECT 
        e.id AS employee_id, e.name AS employee_name, e.job_title,
        b.name AS branch_name,
        a.attendance_date, a.timestamp AS checkin_time, a.early_minutes
    FROM attendances a
    INNER JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.is_active = 1 AND e.deleted_at IS NULL AND {$whereStr}
    ORDER BY a.early_minutes DESC, a.attendance_date DESC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// إحصائيات مجمعة حسب الموظف
$employeeStats = [];
foreach ($records as $rec) {
    $eid = $rec['employee_id'];
    if (!isset($employeeStats[$eid])) {
        $employeeStats[$eid] = [
            'name'         => $rec['employee_name'],
            'job_title'    => $rec['job_title'],
            'branch_name'  => $rec['branch_name'],
            'total_days'   => 0,
            'total_minutes' => 0,
            'max_early'    => 0,
        ];
    }
    $employeeStats[$eid]['total_days']++;
    $employeeStats[$eid]['total_minutes'] += $rec['early_minutes'];
    if ($rec['early_minutes'] > $employeeStats[$eid]['max_early']) {
        $employeeStats[$eid]['max_early'] = $rec['early_minutes'];
    }
}
uasort($employeeStats, fn($a, $b) => $b['total_minutes'] <=> $a['total_minutes']);

$totalEarlyMinutes = array_sum(array_column($records, 'early_minutes'));
$totalEarlyDays    = count($records);
$uniqueEmployees   = count($employeeStats);

// قوائم الفلاتر
$employees = db()->query("SELECT id, name FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();
$branches  = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// تصدير CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="early_report_' . $dateFrom . '_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['الموظف', 'الوظيفة', 'الفرع', 'عدد أيام التبكير', 'إجمالي دقائق التبكير', 'أقصى تبكير (دقيقة)']);
    foreach ($employeeStats as $s) {
        fputcsv($out, [$s['name'], $s['job_title'], $s['branch_name'] ?? '-', $s['total_days'], $s['total_minutes'], $s['max_early']]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
.filter-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-top:12px }
.rank-num { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.82rem; color:#fff }
</style>
<?php
$reportTitle = 'تقرير التبكير والموظفين المتميزين';
$reportSubtitle = 'نظام الحضور والانصراف';
$reportMeta = ["الفترة: {$dateFrom} إلى {$dateTo}"];
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
        <div class="form-group">
            <label>الحد الأدنى (دقيقة)</label>
            <input class="form-control" type="number" name="min_early" value="<?= $minEarly ?>" min="1" max="120" style="width:100px">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= svgIcon('attendance', 16) ?> بحث</button>
            <a href="?date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>&branch_id=<?= $branchId ?>&min_early=<?= $minEarly ?>&export=csv" class="btn-export"><?= svgIcon('backup', 16) ?> تصدير CSV</a>
        </div>
    </form>
</div>

<!-- الإحصائيات -->
<div class="report-stats">
    <div class="report-stat accent-green"><div class="report-stat-icon is-green"><?= svgIcon('star', 24) ?></div><div><div class="report-stat-value"><?= $uniqueEmployees ?></div><div class="report-stat-label">موظفون متميزون</div></div></div>
    <div class="report-stat accent-blue"><div class="report-stat-icon is-blue"><?= svgIcon('calendar', 24) ?></div><div><div class="report-stat-value"><?= $totalEarlyDays ?></div><div class="report-stat-label">أيام تبكير</div></div></div>
    <div class="report-stat accent-orange"><div class="report-stat-icon is-orange"><?= svgIcon('clock', 24) ?></div><div><div class="report-stat-value"><?= number_format($totalEarlyMinutes) ?></div><div class="report-stat-label">إجمالي دقائق التبكير</div></div></div>
    <div class="report-stat accent-purple"><div class="report-stat-icon is-purple"><?= svgIcon('chart', 24) ?></div><div><div class="report-stat-value"><?= $uniqueEmployees > 0 ? round($totalEarlyMinutes / $uniqueEmployees) : 0 ?></div><div class="report-stat-label">متوسط التبكير / موظف</div></div></div>
</div>

<!-- ترتيب المتميزين -->
<div class="report-table-wrap" style="margin-bottom:20px">
    <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
        <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('star', 18) ?> ترتيب المتميزين</span>
        <span class="badge badge-green"><?= $uniqueEmployees ?> موظف</span>
    </div>
    <div style="overflow-x:auto">
    <table class="att-table">
        <thead><tr><th>الترتيب</th><th>الموظف</th><th>الفرع</th><th>أيام التبكير</th><th>إجمالي التبكير</th><th>أقصى تبكير</th><th>متوسط التبكير</th></tr></thead>
        <tbody>
        <?php if (empty($employeeStats)): ?>
            <tr><td colspan="7" class="report-empty" style="padding:40px"><p>لا يوجد سجلات تبكير في هذه الفترة</p></td></tr>
        <?php else: ?>
            <?php $rank = 0; foreach ($employeeStats as $eid => $s): $rank++; ?>
            <tr>
                <td>
                    <?php if ($rank === 1): ?><div class="rank-num" style="background:linear-gradient(135deg,#F59E0B,#D97706)">1</div>
                    <?php elseif ($rank === 2): ?><div class="rank-num" style="background:linear-gradient(135deg,#9CA3AF,#6B7280)">2</div>
                    <?php elseif ($rank === 3): ?><div class="rank-num" style="background:linear-gradient(135deg,#F97316,#EA580C)">3</div>
                    <?php else: ?><span style="color:var(--text3);font-weight:600"><?= $rank ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= htmlspecialchars($s['name']) ?></strong>
                    <?php if ($rank <= 3): ?>
                        <span class="rank-badge <?= $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : 'bronze') ?>">
                            <?= $rank === 1 ? '🥇 ذهبي' : ($rank === 2 ? '🥈 فضي' : '🥉 برونزي') ?>
                        </span>
                    <?php endif; ?>
                    <br><small style="color:var(--text3)"><?= htmlspecialchars($s['job_title']) ?></small>
                </td>
                <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($s['branch_name'] ?? '-') ?></td>
                <td style="font-weight:600"><?= $s['total_days'] ?> يوم</td>
                <td style="color:#059669;font-weight:700"><?= $s['total_minutes'] ?> د</td>
                <td style="color:#7C3AED;font-weight:600"><?= $s['max_early'] ?> د</td>
                <td><?= round($s['total_minutes'] / max(1, $s['total_days'])) ?> د</td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- التفاصيل اليومية -->
<div class="report-table-wrap">
    <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
        <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('calendar', 18) ?> التفاصيل اليومية</span>
        <span class="badge badge-blue"><?= count($records) ?> سجل</span>
    </div>
    <div style="overflow-x:auto">
    <table class="att-table">
        <thead><tr><th>#</th><th>الموظف</th><th>الفرع</th><th>التاريخ</th><th>وقت الحضور</th><th>التبكير</th></tr></thead>
        <tbody>
        <?php foreach ($records as $i => $rec): ?>
        <tr style="background:rgba(5,150,105,<?= min(0.12, ($rec['early_minutes'] / 200)) ?>)">
            <td style="color:var(--text3)"><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($rec['employee_name']) ?></strong><br><small style="color:var(--text3)"><?= htmlspecialchars($rec['job_title']) ?></small></td>
            <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($rec['branch_name'] ?? '-') ?></td>
            <td style="color:var(--text2)"><?= $rec['attendance_date'] ?></td>
            <td style="color:var(--primary);font-weight:bold"><?= date('h:i A', strtotime($rec['checkin_time'])) ?></td>
            <td>
                <?php
                    $em = $rec['early_minutes'];
                    $emoji = $em >= 30 ? '🌟' : ($em >= 15 ? '⭐' : '✨');
                ?>
                <span style="color:#059669;font-weight:700"><?= $emoji ?> <?= $em ?> دقيقة</span>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text3)">لا يوجد سجلات</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

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
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #e5dcc8; }
    .print-report-header, .print-report-footer { display: block !important; }
    .content::after { opacity: .035 !important; }
}
</style>

<?php require __DIR__ . '/../includes/report_print_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div></body></html>
