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
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
$branchId   = (int)($_GET['branch_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$minEarly   = max(0, (int)($_GET['min_early'] ?? 1));

// بناء الاستعلام
$where  = ["a.type = 'in'", "a.early_minutes >= ?", "a.attendance_date BETWEEN ? AND ?"];
$params = [$minEarly, $dateFrom, $dateTo];

if ($employeeId > 0) { $where[] = "e.id = ?"; $params[] = $employeeId; }
if ($branchId > 0)   { $where[] = "e.branch_id = ?"; $params[] = $branchId; }

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
.filter-card { background:var(--surface); border-radius:var(--radius); padding:20px; margin-bottom:20px; border:1px solid var(--border) }
.filter-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-top:12px }
.star-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:8px; font-size:.8rem; font-weight:700 }
.star-gold { background:#FEF3C7; color:#92400E }
.star-silver { background:#F3F4F6; color:#4B5563 }
.star-bronze { background:#FED7AA; color:#9A3412 }
.rank-num { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.8rem; color:#fff }
</style>

<!-- الفلاتر -->
<div class="filter-card">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div><label class="form-label">من تاريخ</label><input class="form-control" type="date" name="date_from" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">إلى تاريخ</label><input class="form-control" type="date" name="date_to" value="<?= $dateTo ?>"></div>
        <div>
            <label class="form-label">الفرع</label>
            <select class="form-control" name="branch_id">
                <option value="0">الكل</option>
                <?php foreach ($branches as $br): ?>
                <option value="<?= $br['id'] ?>" <?= $branchId == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">الموظف</label>
            <select class="form-control" name="employee_id">
                <option value="0">الكل</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $employeeId == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">الحد الأدنى (دقيقة)</label>
            <input class="form-control" type="number" name="min_early" value="<?= $minEarly ?>" min="1" max="120" style="width:100px">
        </div>
        <button type="submit" class="btn btn-primary">بحث</button>
        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&branch_id=<?= $branchId ?>&min_early=<?= $minEarly ?>&export=csv" class="btn btn-green">تصدير CSV</a>
    </form>
</div>

<!-- الإحصائيات -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card"><div class="stat-icon-wrap green">⭐</div><div><div class="stat-value"><?= $uniqueEmployees ?></div><div class="stat-label">موظفون متميزون</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap blue"><?= svgIcon('attendance', 26) ?></div><div><div class="stat-value"><?= $totalEarlyDays ?></div><div class="stat-label">أيام تبكير</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap orange">⏱️</div><div><div class="stat-value"><?= number_format($totalEarlyMinutes) ?></div><div class="stat-label">إجمالي دقائق التبكير</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap purple">📊</div><div><div class="stat-value"><?= $uniqueEmployees > 0 ? round($totalEarlyMinutes / $uniqueEmployees) : 0 ?></div><div class="stat-label">متوسط التبكير / موظف</div></div></div>
</div>

<!-- ترتيب المتميزين -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> 🏆 ترتيب المتميزين</span>
        <span class="badge badge-green"><?= $uniqueEmployees ?> موظف</span>
    </div>
    <div style="overflow-x:auto">
    <table class="att-table">
        <thead><tr><th>الترتيب</th><th>الموظف</th><th>الفرع</th><th>أيام التبكير</th><th>إجمالي التبكير</th><th>أقصى تبكير</th><th>متوسط التبكير</th></tr></thead>
        <tbody>
        <?php if (empty($employeeStats)): ?>
            <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">لا يوجد سجلات تبكير في هذه الفترة</td></tr>
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
                        <span class="star-badge <?= $rank === 1 ? 'star-gold' : ($rank === 2 ? 'star-silver' : 'star-bronze') ?>">
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
<div class="card">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> التفاصيل اليومية</span>
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

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div></body></html>
