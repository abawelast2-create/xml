<?php
// =============================================================
// admin/late-report-pdf.php - طباعة وتصدير تقرير التأخير (PDF)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

// ======== الفلاتر ========
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01');
$dateTo     = $_GET['date_to']   ?? date('Y-m-d');
$filterType = $_GET['filter_type'] ?? 'all';
$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : null;
$branchId   = isset($_GET['branch_id'])   ? (int)$_GET['branch_id']   : null;
$bulkMode   = $_GET['bulk_mode'] ?? null; // 'employees' | 'branches' | null

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// ======== جلب البيانات ========
$days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

function getLateRecords(array $extraWhere = [], array $extraParams = [], string $dateFrom = '', string $dateTo = ''): array
{
    $where = ["a.type = 'in'", "a.late_minutes > 0", "a.attendance_date BETWEEN ? AND ?"];
    $params = [$dateFrom, $dateTo];
    foreach ($extraWhere as $w) $where[] = $w;
    foreach ($extraParams as $p) $params[] = $p;
    $sql = "SELECT e.id AS employee_id, e.name AS employee_name, e.job_title,
                   b.name AS branch_name,
                   DATE_FORMAT(DATE_SUB(a.timestamp, INTERVAL a.late_minutes MINUTE), '%H:%i') AS work_start_time,
                   a.attendance_date, a.timestamp AS checkin_time, a.late_minutes
            FROM attendances a
            INNER JOIN employees e ON a.employee_id = e.id
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE e.is_active = 1 AND e.deleted_at IS NULL AND " . implode(' AND ', $where) . "
            ORDER BY a.attendance_date ASC, e.name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ======== بناء قائمة الأقسام للطباعة ========
$sections = [];

if ($bulkMode === 'employees') {
    // قسم لكل موظف
    $allRecords = getLateRecords([], [], $dateFrom, $dateTo);
    $grouped = [];
    foreach ($allRecords as $r) {
        $grouped[$r['employee_id']][] = $r;
    }
    foreach ($grouped as $empId => $records) {
        $sections[] = [
            'title' => 'تقرير التأخير - ' . htmlspecialchars($records[0]['employee_name']),
            'subtitle' => htmlspecialchars($records[0]['job_title'] ?? '') . ' | ' . htmlspecialchars($records[0]['branch_name'] ?? ''),
            'records' => $records,
        ];
    }
} elseif ($bulkMode === 'branches') {
    // قسم لكل فرع
    $allRecords = getLateRecords([], [], $dateFrom, $dateTo);
    $grouped = [];
    foreach ($allRecords as $r) {
        $grouped[$r['branch_name'] ?? 'غير محدد'][] = $r;
    }
    foreach ($grouped as $branchName => $records) {
        $sections[] = [
            'title' => 'تقرير التأخير - فرع ' . htmlspecialchars($branchName),
            'subtitle' => '',
            'records' => $records,
        ];
    }
} elseif ($filterType === 'employee' && $employeeId) {
    $records = getLateRecords(['e.id = ?'], [$employeeId], $dateFrom, $dateTo);
    $empName = $records[0]['employee_name'] ?? 'موظف';
    $sections[] = [
        'title' => 'تقرير التأخير - ' . htmlspecialchars($empName),
        'subtitle' => htmlspecialchars($records[0]['job_title'] ?? '') . ' | ' . htmlspecialchars($records[0]['branch_name'] ?? ''),
        'records' => $records,
    ];
} elseif ($filterType === 'branch' && $branchId) {
    $records = getLateRecords(['e.branch_id = ?'], [$branchId], $dateFrom, $dateTo);
    $stmt = db()->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->execute([$branchId]);
    $branchName = $stmt->fetchColumn() ?: 'الفرع';
    $sections[] = [
        'title' => 'تقرير التأخير - فرع ' . htmlspecialchars($branchName),
        'subtitle' => '',
        'records' => $records,
    ];
} else {
    $records = getLateRecords([], [], $dateFrom, $dateTo);
    $sections[] = [
        'title' => 'تقرير التأخير - جميع الموظفين',
        'subtitle' => '',
        'records' => $records,
    ];
}

// ======== دالة مساعدة ========
function buildEmployeeStats(array $records): array
{
    $stats = [];
    foreach ($records as $r) {
        $id = $r['employee_id'];
        if (!isset($stats[$id])) {
            $stats[$id] = [
                'name' => $r['employee_name'],
                'job_title' => $r['job_title'],
                'branch_name' => $r['branch_name'],
                'days' => 0,
                'total' => 0,
                'max' => 0,
            ];
        }
        $stats[$id]['days']++;
        $stats[$id]['total'] += $r['late_minutes'];
        if ($r['late_minutes'] > $stats[$id]['max']) $stats[$id]['max'] = $r['late_minutes'];
    }
    uasort($stats, fn($a, $b) => $b['total'] <=> $a['total']);
    return $stats;
}

function fmtMins(int $m): string
{
    if ($m >= 60) {
        return floor($m / 60) . ' س ' . ($m % 60) . ' د';
    }
    return $m . ' دقيقة';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تقرير التأخير - <?= htmlspecialchars(($sections[0]['title'] ?? '')) ?></title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap');
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
        font-family:'Tajawal',Arial,sans-serif;
        font-size:12px;
        color:#1E293B;
        background:#faf7f0;
        position: relative;
    }
    body::before {
        content: '';
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 350px; height: 350px;
        background: url('../assets/images/loogo.png') center/contain no-repeat;
        opacity: .04;
        pointer-events: none;
        z-index: 0;
    }

    .no-print { text-align:center; padding:16px; background:linear-gradient(135deg,#1a2744,#243454); border-bottom:3px solid #c9a84c; }
    .no-print button { padding:10px 28px; background:#c9a84c; color:#1a2744; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-family:inherit; margin:0 4px; font-weight:700; }
    .no-print button.btn-green { background:#059669; color:#fff; }
    .no-print button.btn-gray { background:#64748B; color:#fff; }

    .section { padding:24px; position:relative; z-index:1; background:#fff; max-width:900px; margin:16px auto; border:1px solid #e5dcc8; }
    .page-break { page-break-after: always; break-after: page; }

    .report-header { background:linear-gradient(135deg,#1a2744 0%,#243454 100%); color:#fff; border-radius:0; padding:0; margin-bottom:18px; position:relative; overflow:hidden; }
    .report-header::before,.report-header::after { content:''; position:absolute; right:0; left:0; height:4px; background:linear-gradient(90deg,#b8962e,#c9a84c,#e8d9a0,#c9a84c,#b8962e); }
    .report-header::before { top:0; }
    .report-header::after { bottom:0; }
    .rh-pdf-inner { padding:20px 24px; display:flex; justify-content:space-between; align-items:center; gap:16px; }
    .rh-pdf-logo { width:50px; height:50px; object-fit:contain; filter:drop-shadow(0 2px 6px rgba(201,168,76,.4)); }
    .rh-pdf-center { text-align:center; flex:1; }
    .report-header h1 { font-size:18px; font-weight:900; margin-bottom:4px; color:#e8d9a0; }
    .report-header .sub { font-size:11px; color:rgba(255,255,255,.6); margin-top:4px; }
    .rh-pdf-divider { width:60px; height:1px; background:linear-gradient(90deg,transparent,#c9a84c,transparent); margin:6px auto 0; }
    .report-header .meta { text-align:left; font-size:11px; color:rgba(255,255,255,.7); }

    .summary-box { display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .summary-card { flex:1; min-width:100px; background:#fdfbf6; border:1px solid #e5dcc8; border-radius:8px; padding:10px 14px; text-align:center; }
    .summary-card .val { font-size:22px; font-weight:700; color:#1a2744; }
    .summary-card .lbl { font-size:10px; color:#8b8778; margin-top:2px; }

    .section-title { font-size:13px; font-weight:700; color:#1a2744; margin:16px 0 8px; padding-bottom:6px; border-bottom:2px solid #c9a84c; }

    table { width:100%; border-collapse:collapse; margin-bottom:16px; }
    th { background:linear-gradient(180deg,#f7f3e8,#f0ead8); color:#1a2744; font-weight:700; font-size:10.5px; padding:7px 10px; text-align:right; border:1px solid #e5dcc8; border-bottom:2px solid #c9a84c; }
    td { padding:6px 10px; border:1px solid #e5dcc8; font-size:11px; vertical-align:middle; }
    tr:nth-child(even) td { background:#fdfbf6; }

    .late-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; }
    .late-low     { background:#FEF3C7; color:#92400E; }
    .late-medium  { background:#FED7AA; color:#9A3412; }
    .late-high    { background:#FECACA; color:#991B1B; }
    .late-critical{ background:#EF4444; color:#fff; }

    .badge-green { background:#D1FAE5; color:#065F46; padding:2px 7px; border-radius:8px; font-size:10px; }
    .badge-yellow{ background:#FEF3C7; color:#92400E; padding:2px 7px; border-radius:8px; font-size:10px; }
    .badge-red   { background:#FEE2E2; color:#991B1B; padding:2px 7px; border-radius:8px; font-size:10px; }

    .footer-row { display:flex; justify-content:space-between; font-size:10px; color:#8b8778; margin-top:16px; padding-top:10px; border-top:2px solid #c9a84c; background:#fdfbf6; padding:10px 12px; border-radius:0 0 0 0; }

    .empty-section { text-align:center; padding:30px; color:#8b8778; font-size:13px; border:1px dashed #e5dcc8; border-radius:8px; }

    @media print {
        body { font-size:11px; background:#fff; }
        body::before { position:fixed; opacity:.035; }
        .no-print { display:none !important; }
        .section { padding:12px; border:none; margin:0; max-width:none; box-shadow:none; }
        .report-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .report-header::before,.report-header::after { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .summary-card  { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .late-badge    { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .badge-green,.badge-yellow,.badge-red { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .footer-row { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        @page { size:A4; margin:1.5cm 1cm; }
    }
</style>
</head>
<body>

<!-- شريط الطباعة -->
<div class="no-print">
    <button onclick="window.print()">🖨️ طباعة / حفظ كـ PDF</button>
    <button class="btn-green" onclick="downloadCSV()">📥 تحميل CSV</button>
    <button class="btn-gray" onclick="window.close()">✕ إغلاق</button>
</div>

<?php $sectionIndex = 0; foreach ($sections as $section):
    $records = $section['records'];
    $empStats = buildEmployeeStats($records);
    $totalMins = array_sum(array_column($records, 'late_minutes'));
    $totalDays = count($records);
    $uniqueEmps = count($empStats);
    $avgMins = $totalDays ? round($totalMins / $totalDays) : 0;
    $sectionIndex++;
    $isLast = ($sectionIndex === count($sections));
?>
<div class="section <?= !$isLast ? 'page-break' : '' ?>">

    <!-- رأس التقرير -->
    <div class="report-header">
        <div class="rh-pdf-inner">
            <img src="../assets/images/loogo.png" class="rh-pdf-logo" alt="">
            <div class="rh-pdf-center">
                <h1><?= $section['title'] ?></h1>
                <?php if ($section['subtitle']): ?>
                    <div class="sub"><?= $section['subtitle'] ?></div>
                <?php endif; ?>
                <div class="sub">الفترة: <?= $dateFrom ?> — <?= $dateTo ?></div>
                <div class="rh-pdf-divider"></div>
            </div>
            <div class="meta">
                <div>نظام الحضور والانصراف</div>
                <div style="margin-top:4px">تاريخ الإنشاء: <?= date('Y-m-d H:i') ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($records)): ?>
        <div class="empty-section">🎉 لا توجد حالات تأخير في هذه الفترة</div>
    <?php else: ?>

    <!-- الإحصائيات -->
    <div class="summary-box">
        <div class="summary-card">
            <div class="val"><?= number_format($totalMins) ?></div>
            <div class="lbl">إجمالي دقائق التأخير</div>
        </div>
        <div class="summary-card">
            <div class="val"><?= number_format($totalDays) ?></div>
            <div class="lbl">أيام التأخير</div>
        </div>
        <div class="summary-card">
            <div class="val"><?= $uniqueEmps ?></div>
            <div class="lbl">موظفون متأخرون</div>
        </div>
        <div class="summary-card">
            <div class="val"><?= $avgMins ?></div>
            <div class="lbl">متوسط التأخير (د/يوم)</div>
        </div>
        <div class="summary-card">
            <div class="val"><?= floor($totalMins/60) ?>:<?= str_pad($totalMins%60,2,'0',STR_PAD_LEFT) ?></div>
            <div class="lbl">الإجمالي (ساعة:دقيقة)</div>
        </div>
    </div>

    <?php if (count($empStats) > 1): ?>
    <!-- ملخص حسب الموظف -->
    <div class="section-title">ملخص التأخير حسب الموظف</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>الموظف</th>
                <th>الوظيفة</th>
                <th>الفرع</th>
                <th>أيام التأخير</th>
                <th>إجمالي الدقائق</th>
                <th>أقصى تأخير</th>
                <th>المتوسط/يوم</th>
                <th>التقييم</th>
            </tr>
        </thead>
        <tbody>
        <?php $seq = 0; foreach ($empStats as $eId => $s): $seq++; $avg = $s['days'] ? round($s['total']/$s['days']) : 0; ?>
            <tr>
                <td><?= $seq ?></td>
                <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                <td style="color:#64748B;font-size:10px"><?= htmlspecialchars($s['job_title'] ?? '') ?></td>
                <td style="font-size:10px"><?= htmlspecialchars($s['branch_name'] ?? '-') ?></td>
                <td style="font-weight:700;text-align:center"><?= $s['days'] ?></td>
                <td>
                    <?php $cls = $s['total']<30?'late-low':($s['total']<120?'late-medium':($s['total']<300?'late-high':'late-critical')); ?>
                    <span class="late-badge <?= $cls ?>"><?= $s['total'] ?> د</span>
                </td>
                <td style="text-align:center"><?= $s['max'] ?> د</td>
                <td style="text-align:center"><?= $avg ?> د</td>
                <td>
                    <?php if ($avg<10) echo '<span class="badge-green">جيد</span>';
                    elseif ($avg<30) echo '<span class="badge-yellow">متوسط</span>';
                    else echo '<span class="badge-red">يحتاج تحسين</span>'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- التفاصيل اليومية -->
    <div class="section-title">التفاصيل اليومية</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>التاريخ</th>
                <th>اليوم</th>
                <th>الموظف</th>
                <th>الفرع</th>
                <th>بداية الدوام</th>
                <th>وقت الحضور</th>
                <th>التأخير</th>
            </tr>
        </thead>
        <tbody>
        <?php $seq = 0; foreach ($records as $r): $seq++;
            $lm = $r['late_minutes'];
            $cls = $lm<15?'late-low':($lm<45?'late-medium':($lm<90?'late-high':'late-critical'));
        ?>
            <tr>
                <td style="color:#94A3B8"><?= $seq ?></td>
                <td style="font-weight:600"><?= $r['attendance_date'] ?></td>
                <td style="font-size:10px;color:#64748B"><?= $days[date('w',strtotime($r['attendance_date']))] ?></td>
                <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
                <td style="font-size:10px"><?= htmlspecialchars($r['branch_name'] ?? '-') ?></td>
                <td style="font-family:monospace;color:#64748B"><?= $r['work_start_time'] ?? '-' ?></td>
                <td style="font-family:monospace;font-weight:700;color:#1a2744"><?= date('H:i:s', strtotime($r['checkin_time'])) ?></td>
                <td><span class="late-badge <?= $cls ?>"><?= fmtMins($lm) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-row">
        <span>تقرير تأخير الموظفين — نظام الحضور والانصراف</span>
        <span>الفترة: <?= $dateFrom ?> إلى <?= $dateTo ?> | <?= date('Y-m-d H:i') ?></span>
    </div>

    <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
<?php
// تجهيز بيانات CSV للتصدير من JS
$allCsvRows = [];
foreach ($sections as $section) {
    foreach ($section['records'] as $r) {
        $allCsvRows[] = [
            $r['attendance_date'],
            $r['employee_name'],
            $r['job_title'] ?? '',
            $r['branch_name'] ?? '-',
            date('H:i:s', strtotime($r['checkin_time'])),
            $r['work_start_time'] ?? '-',
            $r['late_minutes'],
        ];
    }
}
echo 'const csvData = ' . json_encode($allCsvRows, JSON_UNESCAPED_UNICODE) . ';';
echo 'const csvHeaders = ["التاريخ","الموظف","الوظيفة","الفرع","وقت الحضور","بداية الدوام","التأخير (دقيقة)"];';
?>
function downloadCSV() {
    let bom = "\uFEFF";
    let csv = bom + csvHeaders.join(",") + "\n";
    csvData.forEach(row => {
        csv += row.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(",") + "\n";
    });
    let a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'}));
    a.download = 'late_report_<?= $dateFrom ?>_<?= $dateTo ?>.csv';
    a.click();
}
</script>
</body>
</html>
