<?php
// =============================================================
// admin/report-absence.php - تقرير الغياب التفصيلي
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'تقرير الغياب';
$activePage = 'report-absence';

// =================== فلاتر ===================
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
$filterShift = (int)($_GET['shift'] ?? 0);

$branches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// جلب الموظفين
$empWhere = "e.is_active = 1 AND e.deleted_at IS NULL";
$empParams = [];
if ($branchId) {
    $empWhere .= " AND e.branch_id = ?";
    $empParams[] = $branchId;
}
$empStmt = db()->prepare("
    SELECT e.id, e.name, e.job_title, b.name AS branch_name
    FROM employees e LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$empWhere} ORDER BY e.name
");
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll();

// فلتر الوردية
$shiftTimeCond = '';
$shiftTimeParams = [];
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift, '');
    if ($sf) { $shiftTimeCond = "AND " . $sf['sql']; $shiftTimeParams = $sf['params']; }
}

// أيام الحضور لكل موظف
$attStmt = db()->prepare("
    SELECT employee_id, attendance_date
    FROM attendances
    WHERE attendance_date BETWEEN ? AND ? AND type = 'in'
    $shiftTimeCond
    GROUP BY employee_id, attendance_date
");
$attStmt->execute(array_merge([$dateFrom, $dateTo], $shiftTimeParams));
$attendedDays = [];
foreach ($attStmt->fetchAll() as $row) {
    $attendedDays[$row['employee_id']][$row['attendance_date']] = true;
}

// الإجازات المعتمدة
$leaveStmt = db()->prepare("
    SELECT employee_id, start_date, end_date, leave_type
    FROM leaves WHERE status = 'approved' AND start_date <= ? AND end_date >= ?
");
$leaveStmt->execute([$dateTo, $dateFrom]);
$leaveMap = [];
foreach ($leaveStmt->fetchAll() as $lv) {
    $s = max(strtotime($lv['start_date']), strtotime($dateFrom));
    $e = min(strtotime($lv['end_date']), strtotime($dateTo));
    for ($d = $s; $d <= $e; $d += 86400) {
        $leaveMap[$lv['employee_id']][date('Y-m-d', $d)] = $lv['leave_type'];
    }
}

// حساب الغياب لكل موظف
$absenceData = [];
$today = date('Y-m-d');
foreach ($employees as $emp) {
    $absentDays = [];
    $start = strtotime($dateFrom);
    $end   = min(strtotime($dateTo), strtotime($today));
    for ($d = $start; $d <= $end; $d += 86400) {
        $dateStr = date('Y-m-d', $d);
        $dow = date('w', $d);
        if ($dow == 5) continue; // الجمعة عطلة
        if (isset($attendedDays[$emp['id']][$dateStr])) continue;
        if (isset($leaveMap[$emp['id']][$dateStr])) continue;
        $absentDays[] = $dateStr;
    }
    if (!empty($absentDays)) {
        $absenceData[] = [
            'employee' => $emp,
            'absent_days' => $absentDays,
            'count' => count($absentDays)
        ];
    }
}

// الترتيب حسب عدد الغياب (الأكثر أولاً)
usort($absenceData, fn($a, $b) => $b['count'] - $a['count']);

$totalAbsent = array_sum(array_column($absenceData, 'count'));

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- الأدوات -->
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
        <button type="submit" class="btn btn-primary" style="padding:8px 20px">عرض</button>
        <button type="button" onclick="window.print()" class="btn btn-secondary" style="padding:8px 16px">🖨️ طباعة</button>
    </form>
</div>

<!-- ملخص -->
<div class="stats-grid" style="margin-bottom:16px">
    <div class="stat-card">
        <div class="stat-icon-wrap red">✗</div>
        <div><div class="stat-value"><?= $totalAbsent ?></div><div class="stat-label">إجمالي أيام الغياب</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#FEF3C7;color:#D97706">👥</div>
        <div><div class="stat-value"><?= count($absenceData) ?></div><div class="stat-label">موظفون لديهم غياب</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#DBEAFE;color:#2563EB">📊</div>
        <div><div class="stat-value"><?= count($absenceData) > 0 ? round($totalAbsent / count($absenceData), 1) : 0 ?></div><div class="stat-label">متوسط أيام الغياب</div></div>
    </div>
</div>

<!-- الجدول -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:var(--surface2,#F8FAFC)">
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">#</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الموظف</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الفرع</th>
                    <th style="padding:12px 14px;text-align:center;font-size:.82rem;color:var(--text3);font-weight:600">أيام الغياب</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">التواريخ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($absenceData)): ?>
                    <tr><td colspan="5" style="padding:40px;text-align:center;color:var(--green)">🎉 لا يوجد غياب في هذه الفترة!</td></tr>
                <?php endif; ?>
                <?php foreach ($absenceData as $i => $row): ?>
                <tr style="border-bottom:1px solid var(--border-color,#E2E8F0)">
                    <td style="padding:12px 14px;font-size:.85rem;color:var(--text3)"><?= $i + 1 ?></td>
                    <td style="padding:12px 14px">
                        <strong style="font-size:.9rem"><?= htmlspecialchars($row['employee']['name']) ?></strong>
                        <div style="font-size:.78rem;color:var(--text3)"><?= htmlspecialchars($row['employee']['job_title']) ?></div>
                    </td>
                    <td style="padding:12px 14px;font-size:.88rem"><?= htmlspecialchars($row['employee']['branch_name'] ?? '—') ?></td>
                    <td style="padding:12px 14px;text-align:center">
                        <span style="background:<?= $row['count'] >= 5 ? '#FEE2E2' : ($row['count'] >= 3 ? '#FEF3C7' : '#F1F5F9') ?>;
                               color:<?= $row['count'] >= 5 ? '#991B1B' : ($row['count'] >= 3 ? '#92400E' : 'var(--text-primary)') ?>;
                               padding:4px 12px;border-radius:12px;font-weight:700;font-size:.88rem">
                            <?= $row['count'] ?> يوم
                        </span>
                    </td>
                    <td style="padding:12px 14px">
                        <div style="display:flex;flex-wrap:wrap;gap:4px">
                            <?php foreach (array_slice($row['absent_days'], 0, 7) as $ad): ?>
                                <span style="background:var(--surface2,#F8FAFC);padding:2px 8px;border-radius:6px;font-size:.75rem;direction:ltr"><?= date('m/d', strtotime($ad)) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($row['absent_days']) > 7): ?>
                                <span style="color:var(--text3);font-size:.75rem">+<?= count($row['absent_days']) - 7 ?> أخرى</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
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
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
}
</style>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div>
</body></html>
