<?php
// =============================================================
// admin/report-charts.php - التقارير البيانية (v4.0)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'التقارير البيانية';
$activePage = 'report-charts';

// =================== الفترة ===================
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
$filterShift = (int)($_GET['shift'] ?? 0);

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// =================== الفروع ===================
$branches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// فلتر الوردية
$shiftTimeCond = '';
$shiftTimeParams = [];
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift);
    if ($sf) { $shiftTimeCond = "AND " . $sf['sql']; $shiftTimeParams = $sf['params']; }
}

// =================== إحصائيات الفترة ===================
$statsParams = array_merge([$dateFrom, $dateTo], $shiftTimeParams);
$statsFilter = '';
if ($branchId) {
    $statsFilter = 'AND e.branch_id = ?';
    $statsParams[] = $branchId;
}

$statsStmt = db()->prepare("
    SELECT
        COUNT(CASE WHEN a.type = 'in' THEN 1 END) AS total_check_ins,
        COUNT(CASE WHEN a.type = 'out' THEN 1 END) AS total_check_outs,
        COUNT(DISTINCT a.employee_id) AS unique_employees,
        COUNT(DISTINCT a.attendance_date) AS working_days,
        ROUND(AVG(CASE WHEN a.type = 'in' AND a.late_minutes > 0 THEN a.late_minutes END), 1) AS avg_late_minutes,
        SUM(CASE WHEN a.type = 'in' AND a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date BETWEEN ? AND ?
      AND a.type IN ('in', 'out')
      AND e.is_active = 1 AND e.deleted_at IS NULL
      {$shiftTimeCond}
      {$statsFilter}
");
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch();

// =================== بيانات الرسم البياني (حضور يومي) ===================
$chartParams = array_merge([$dateFrom, $dateTo], $shiftTimeParams);
$chartFilter = '';
if ($branchId) {
    $chartFilter = 'AND e.branch_id = ?';
    $chartParams[] = $branchId;
}

$chartStmt = db()->prepare("
    SELECT a.attendance_date,
           COUNT(CASE WHEN a.type = 'in' THEN 1 END) AS check_ins,
           COUNT(CASE WHEN a.type = 'out' THEN 1 END) AS check_outs,
           ROUND(AVG(CASE WHEN a.type = 'in' AND a.late_minutes > 0 THEN a.late_minutes END)) AS avg_late
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date BETWEEN ? AND ?
      AND e.is_active = 1 AND e.deleted_at IS NULL
      {$shiftTimeCond}
      {$chartFilter}
    GROUP BY a.attendance_date
    ORDER BY a.attendance_date
");
$chartStmt->execute($chartParams);
$chartData = $chartStmt->fetchAll();

$chartLabels   = array_column($chartData, 'attendance_date');
$chartCheckIns = array_map('intval', array_column($chartData, 'check_ins'));
$chartCheckOuts = array_map('intval', array_column($chartData, 'check_outs'));
$chartLate     = array_map(fn($v) => (int)($v ?? 0), array_column($chartData, 'avg_late'));

// =================== بيانات حسب الفرع (دائرية) ===================
$branchChartStmt = db()->prepare("
    SELECT b.name, COUNT(DISTINCT a.employee_id) AS employees
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    JOIN branches b ON e.branch_id = b.id
    WHERE a.attendance_date BETWEEN ? AND ?
      AND a.type = 'in'
      AND e.is_active = 1 AND e.deleted_at IS NULL
      {$shiftTimeCond}
    GROUP BY b.id, b.name
    ORDER BY employees DESC
");
$branchChartStmt->execute(array_merge([$dateFrom, $dateTo], $shiftTimeParams));
$branchChartData = $branchChartStmt->fetchAll();

// =================== أكثر الموظفين تأخيراً ===================
$topLateParams = array_merge([$dateFrom, $dateTo], $shiftTimeParams);
$topLateFilter = '';
if ($branchId) {
    $topLateFilter = 'AND e.branch_id = ?';
    $topLateParams[] = $branchId;
}

$topLateStmt = db()->prepare("
    SELECT e.name, b.name AS branch_name,
           COUNT(*) AS late_days,
           SUM(a.late_minutes) AS total_late,
           ROUND(AVG(a.late_minutes)) AS avg_late
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE a.attendance_date BETWEEN ? AND ?
      AND a.type = 'in' AND a.late_minutes > 0
      AND e.is_active = 1 AND e.deleted_at IS NULL
      {$shiftTimeCond}
      {$topLateFilter}
    GROUP BY a.employee_id, e.name, b.name
    ORDER BY total_late DESC
    LIMIT 10
");
$topLateStmt->execute($topLateParams);
$topLateData = $topLateStmt->fetchAll();

// =================== معدل الحضور حسب يوم الأسبوع ===================
$dowParams = array_merge([$dateFrom, $dateTo], $shiftTimeParams);
if ($branchId) $dowParams[] = $branchId;
$dayOfWeekStmt = db()->prepare("
    SELECT DAYOFWEEK(a.attendance_date) AS dow,
           COUNT(DISTINCT a.employee_id) AS employees
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date BETWEEN ? AND ?
      AND a.type = 'in'
      AND e.is_active = 1 AND e.deleted_at IS NULL
      {$shiftTimeCond}
      " . ($branchId ? 'AND e.branch_id = ?' : '') . "
    GROUP BY dow
    ORDER BY dow
");
$dayOfWeekStmt->execute($dowParams);
$dowData = $dayOfWeekStmt->fetchAll();

$arabicDays = [1 => 'الأحد', 2 => 'الاثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت'];
$dowLabels = [];
$dowValues = [];
foreach ($dowData as $d) {
    $dowLabels[] = $arabicDays[$d['dow']] ?? '';
    $dowValues[] = (int)$d['employees'];
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- Filters -->
<div class="report-filter">
    <form method="GET" class="filter-bar">
        <div class="form-group">
            <label>من تاريخ</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>إلى تاريخ</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>الفرع</label>
            <select name="branch_id" id="branchSelect" class="form-control">
                <option value="">جميع الفروع</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $branchId == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>الوردية</label>
            <select name="shift" id="shiftSelect" class="form-control">
                <option value="0">كل الورديات</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= svgIcon('chart', 16) ?> تصفية</button>
            <a href="report-daily.php?date=<?= htmlspecialchars($dateFrom) ?>" class="btn btn-secondary" style="text-decoration:none"><?= svgIcon('document', 16) ?> تقرير للطباعة</a>
        </div>
        <div style="display:flex;gap:6px;margin-right:auto">
            <a href="<?= SITE_URL ?>/api/export.php?format=csv&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?><?= $branchId ? '&branch_id='.$branchId : '' ?>"
               class="btn-export" style="padding:6px 10px;font-size:.78rem" title="تصدير CSV"><?= svgIcon('backup', 14) ?> CSV</a>
            <a href="<?= SITE_URL ?>/api/export.php?format=excel&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?><?= $branchId ? '&branch_id='.$branchId : '' ?>"
               class="btn-export" style="padding:6px 10px;font-size:.78rem" title="تصدير Excel"><?= svgIcon('backup', 14) ?> Excel</a>
            <a href="<?= SITE_URL ?>/api/export.php?format=print&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?><?= $branchId ? '&branch_id='.$branchId : '' ?>"
               target="_blank" class="btn btn-secondary" style="padding:6px 10px;font-size:.78rem;text-decoration:none" title="طباعة / PDF"><?= svgIcon('document', 14) ?> PDF</a>
        </div>
    </form>
</div>

<!-- Stats Cards -->
<div class="report-stats">
    <div class="report-stat accent-blue">
        <div class="report-stat-icon is-blue"><?= svgIcon('checkin', 24) ?></div>
        <div>
            <div class="report-stat-value"><?= number_format($stats['total_check_ins'] ?? 0) ?></div>
            <div class="report-stat-label">إجمالي الحضور</div>
        </div>
    </div>
    <div class="report-stat accent-red">
        <div class="report-stat-icon is-red"><?= svgIcon('checkout', 24) ?></div>
        <div>
            <div class="report-stat-value"><?= number_format($stats['total_check_outs'] ?? 0) ?></div>
            <div class="report-stat-label">إجمالي الانصراف</div>
        </div>
    </div>
    <div class="report-stat accent-green">
        <div class="report-stat-icon is-green"><?= svgIcon('employees', 24) ?></div>
        <div>
            <div class="report-stat-value"><?= $stats['unique_employees'] ?? 0 ?></div>
            <div class="report-stat-label">موظفين فريدين</div>
        </div>
    </div>
    <div class="report-stat accent-orange">
        <div class="report-stat-icon is-orange"><?= svgIcon('late', 24) ?></div>
        <div>
            <div class="report-stat-value"><?= $stats['avg_late_minutes'] ?? 0 ?> <small>دقيقة</small></div>
            <div class="report-stat-label">متوسط التأخير</div>
        </div>
    </div>
</div>

<!-- Row 1: Daily + Branch Charts -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px">
    <div class="chart-card">
        <h3 class="chart-card-title"><?= svgIcon('chart', 18) ?> الحضور والانصراف اليومي</h3>
        <div style="position:relative;height:280px"><canvas id="dailyChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3 class="chart-card-title"><?= svgIcon('branch', 18) ?> التوزيع حسب الفروع</h3>
        <div style="position:relative;height:280px"><canvas id="branchChart"></canvas></div>
    </div>
</div>

<!-- Row 2: Late + Day-of-Week Charts -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
    <div class="chart-card">
        <h3 class="chart-card-title"><?= svgIcon('late', 18) ?> متوسط التأخير اليومي (دقائق)</h3>
        <div style="position:relative;height:260px"><canvas id="lateChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3 class="chart-card-title"><?= svgIcon('calendar', 18) ?> الحضور حسب أيام الأسبوع</h3>
        <div style="position:relative;height:260px"><canvas id="dowChart"></canvas></div>
    </div>
</div>

<!-- Row 3: Top Late Employees Table -->
<?php if (!empty($topLateData)): ?>
<div class="report-table-wrap" style="margin-bottom:20px">
    <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
        <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('late', 18) ?> أكثر الموظفين تأخيراً</span>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>الفرع</th>
                    <th>أيام التأخير</th>
                    <th>إجمالي الدقائق</th>
                    <th>المتوسط</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topLateData as $i => $emp): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($emp['name']) ?></strong></td>
                    <td><?= htmlspecialchars($emp['branch_name']) ?></td>
                    <td><?= $emp['late_days'] ?></td>
                    <td><span class="badge badge-red"><?= number_format($emp['total_late']) ?></span></td>
                    <td><?= $emp['avg_late'] ?> دقيقة</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<style>
    @media (max-width: 900px) {
        div[style*="grid-template-columns: 2fr 1fr"],
        div[style*="grid-template-columns:2fr 1fr"],
        div[style*="grid-template-columns: 1fr 1fr"],
        div[style*="grid-template-columns:1fr 1fr"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function(){
    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#94A3B8' : '#64748B';
    const gridColor = isDark ? '#334155' : '#E2E8F0';

    Chart.defaults.font.family = 'Tajawal, sans-serif';
    Chart.defaults.color = textColor;

    // ============ Daily Attendance Bar Chart ============
    new Chart(document.getElementById('dailyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                {
                    label: 'حضور',
                    data: <?= json_encode($chartCheckIns) ?>,
                    backgroundColor: isDark ? 'rgba(16,185,129,.6)' : 'rgba(16,185,129,.75)',
                    borderRadius: 4,
                    borderSkipped: false,
                },
                {
                    label: 'انصراف',
                    data: <?= json_encode($chartCheckOuts) ?>,
                    backgroundColor: isDark ? 'rgba(59,130,246,.6)' : 'rgba(59,130,246,.75)',
                    borderRadius: 4,
                    borderSkipped: false,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top', rtl: true } },
            scales: {
                x: { grid: { color: gridColor }, ticks: { maxRotation: 45, font: { size: 10 } } },
                y: { grid: { color: gridColor }, beginAtZero: true }
            }
        }
    });

    // ============ Branch Distribution Doughnut ============
    new Chart(document.getElementById('branchChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($branchChartData, 'name'), JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode(array_map('intval', array_column($branchChartData, 'employees'))) ?>,
                backgroundColor: ['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#06B6D4','#F97316','#14B8A6','#6366F1'],
                borderWidth: 2,
                borderColor: isDark ? '#1E293B' : '#FFFFFF',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', rtl: true, labels: { padding: 12, font: { size: 11 } } }
            }
        }
    });

    // ============ Late Minutes Line Chart ============
    new Chart(document.getElementById('lateChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'متوسط التأخير',
                data: <?= json_encode($chartLate) ?>,
                borderColor: '#F59E0B',
                backgroundColor: isDark ? 'rgba(245,158,11,.12)' : 'rgba(245,158,11,.08)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#F59E0B',
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: gridColor }, ticks: { maxRotation: 45, font: { size: 10 } } },
                y: { grid: { color: gridColor }, beginAtZero: true, title: { display: true, text: 'دقيقة', font: { size: 11 } } }
            }
        }
    });

    // ============ Day of Week Polar Area ============
    new Chart(document.getElementById('dowChart'), {
        type: 'polarArea',
        data: {
            labels: <?= json_encode($dowLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode($dowValues) ?>,
                backgroundColor: [
                    'rgba(239,68,68,.6)', 'rgba(59,130,246,.6)', 'rgba(16,185,129,.6)',
                    'rgba(245,158,11,.6)', 'rgba(139,92,246,.6)', 'rgba(236,72,153,.6)',
                    'rgba(6,182,212,.6)'
                ],
                borderWidth: 1,
                borderColor: isDark ? '#1E293B' : '#FFFFFF',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', rtl: true, labels: { padding: 10, font: { size: 11 } } }
            },
            scales: {
                r: { grid: { color: gridColor }, ticks: { display: false } }
            }
        }
    });
})();

// فلتر الورديات الديناميكي
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

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

</div></div>
</body></html>
