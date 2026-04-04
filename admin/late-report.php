<?php
// =============================================================
// admin/late-report.php - تقرير التأخير الكامل
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'تقرير التأخير';
$activePage = 'late-report';

// جلب الفلاتر
$filterType = $_GET['filter_type'] ?? 'all';
$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : null;
$branchId   = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

// الشهر يبدأ من يوم 4 — الفترة التراكمية من يوم 5 حتى يوم 4 الشهر التالي
// إذا تم الضغط على زر إعادة التعيين، اجعل التواريخ افتراضية
$resetRequested = isset($_GET['reset']) && $_GET['reset'] == '1';
if (!isset($_GET['date_from']) || $resetRequested) {
    $today = (int)date('j');
    if ($today >= 5) {
        $defaultFrom = date('Y-m-05');
    } else {
        $defaultFrom = date('Y-m-05', strtotime('first day of last month'));
    }
} else {
    $defaultFrom = null;
}
$dateFrom   = $_GET['date_from'] ?? $defaultFrom;
$dateTo     = $_GET['date_to']   ?? date('Y-m-d');
// التحقق من صحة التواريخ
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultFrom ?? date('Y-m-05');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// جلب قوائم الموظفين والفروع للفلتر
$employees = db()->query("SELECT id, name, branch_id FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();
$branches  = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// بناء الاستعلام حسب الفلتر
$whereConditions = ["a.type = 'in'", "a.late_minutes > 0", "a.attendance_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($filterType === 'employee' && $employeeId) {
    $whereConditions[] = "e.id = ?";
    $params[] = $employeeId;
} elseif ($filterType === 'branch' && $branchId) {
    $whereConditions[] = "e.branch_id = ?";
    $params[] = $branchId;
}

// فلتر الوردية
$filterShift = (int)($_GET['shift'] ?? 0);
$shiftTimeCond = '';
$shiftTimeParams = [];
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift);
    if ($sf) { $shiftTimeCond = "AND " . $sf['sql']; $shiftTimeParams = $sf['params']; }
}

$whereClause = implode(' AND ', $whereConditions);

// استعلام حساب التأخير
$stmt = db()->prepare("
    SELECT 
        e.id AS employee_id,
        e.name AS employee_name,
        e.job_title,
        b.name AS branch_name,
        COALESCE(
            DATE_FORMAT(bs.shift_start, '%H:%i'),
            DATE_FORMAT(DATE_SUB(a.timestamp, INTERVAL a.late_minutes MINUTE), '%H:%i')
        ) AS work_start_time,
        a.attendance_date,
        a.timestamp AS checkin_time,
        a.late_minutes
    FROM attendances a
    INNER JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN branch_shifts bs ON bs.branch_id = e.branch_id AND bs.is_active = 1
        AND bs.shift_number = (
            SELECT bs2.shift_number FROM branch_shifts bs2 
            WHERE bs2.branch_id = e.branch_id AND bs2.is_active = 1
            ORDER BY ABS(TIMESTAMPDIFF(MINUTE, 
                CONCAT(a.attendance_date, ' ', bs2.shift_start), 
                a.timestamp)) 
            LIMIT 1
        )
    WHERE e.is_active = 1 AND e.deleted_at IS NULL AND {$whereClause}
    {$shiftTimeCond}
    ORDER BY a.attendance_date DESC, a.late_minutes DESC, e.name ASC
");
$stmt->execute(array_merge($params, $shiftTimeParams));
$lateRecords = $stmt->fetchAll();

// حساب الإحصائيات
$totalLateMinutes = array_sum(array_column($lateRecords, 'late_minutes'));
$totalLateDays = count($lateRecords);
$uniqueEmployees = count(array_unique(array_column($lateRecords, 'employee_id')));

// تجميع حسب الموظف
$employeeStats = [];
foreach ($lateRecords as $record) {
    $empId = $record['employee_id'];
    if (!isset($employeeStats[$empId])) {
        $employeeStats[$empId] = [
            'employee_name' => $record['employee_name'],
            'job_title' => $record['job_title'],
            'branch_name' => $record['branch_name'],
            'total_late_days' => 0,
            'total_late_minutes' => 0,
            'max_late' => 0,
        ];
    }
    $employeeStats[$empId]['total_late_days']++;
    $employeeStats[$empId]['total_late_minutes'] += $record['late_minutes'];
    if ($record['late_minutes'] > $employeeStats[$empId]['max_late']) {
        $employeeStats[$empId]['max_late'] = $record['late_minutes'];
    }
}

uasort($employeeStats, function ($a, $b) {
    return $b['total_late_minutes'] <=> $a['total_late_minutes'];
});

// تصدير CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="late_report_' . $dateFrom . '_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['التاريخ', 'الموظف', 'الوظيفة', 'الفرع', 'وقت الحضور', 'بداية الدوام', 'التأخير (دقيقة)']);
    foreach ($lateRecords as $rec) {
        fputcsv($out, [
            $rec['attendance_date'],
            $rec['employee_name'],
            $rec['job_title'],
            $rec['branch_name'] ?? '-',
            date('H:i', strtotime($rec['checkin_time'])),
            $rec['work_start_time'] ?? '-',
            $rec['late_minutes'],
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 14px;
        margin-top: 12px
    }

    .filter-group label {
        display: block;
        font-weight: 700;
        margin-bottom: 4px;
        color: var(--text3);
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .04em
    }

    .filter-group select,
    .filter-group input {
        width: 100%;
        padding: 9px 12px;
        border: 1.5px solid var(--border);
        border-radius: 8px;
        font-family: inherit;
        font-size: .85rem;
        background: var(--surface2);
        color: var(--text);
        transition: border-color .2s, box-shadow .2s
    }
    .filter-group select:focus,
    .filter-group input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(232,114,42,.1);
        background: var(--surface);
        outline: none
    }

    .late-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: .82rem;
        font-weight: 600
    }

    .late-low {
        background: #FEF3C7;
        color: #92400E
    }

    .late-medium {
        background: #FED7AA;
        color: #9A3412
    }

    .late-high {
        background: #FECACA;
        color: #991B1B
    }

    .late-critical {
        background: #EF4444;
        color: #fff
    }
</style>

<!-- فلاتر -->
<div class="report-filter">
    <form method="GET">
        <div class="filter-grid">
            <div class="filter-group">
                <label>نوع الفلتر</label>
                <select name="filter_type" id="filterType" onchange="toggleFilterInputs()">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>جميع الموظفين</option>
                    <option value="employee" <?= $filterType === 'employee' ? 'selected' : '' ?>>موظف محدد</option>
                    <option value="branch" <?= $filterType === 'branch' ? 'selected' : '' ?>>فرع محدد</option>
                </select>
            </div>
            <div class="filter-group" id="employeeFilter" style="display:<?= $filterType === 'employee' ? 'block' : 'none' ?>">
                <label>الموظف</label>
                <select name="employee_id">
                    <option value="">اختر موظف</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $employeeId == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" id="branchFilter" style="display:<?= $filterType === 'branch' ? 'block' : 'none' ?>">
                <label>الفرع</label>
                <select name="branch_id">
                    <option value="">اختر فرع</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>من تاريخ</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label>إلى تاريخ</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="filter-group">
                <label>الوردية</label>
                <select name="shift" id="shiftSelect">
                    <option value="0">كل الورديات</option>
                </select>
            </div>
        </div>
        <div class="filter-actions" style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary"><?= svgIcon('chart', 16) ?> عرض التقرير</button>
            <a href="late-report.php?reset=1" class="btn btn-secondary"><?= svgIcon('leave', 16) ?> إعادة تعيين</a>
            <?php if (!empty($lateRecords)): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-export"><?= svgIcon('backup', 16) ?> تصدير CSV</a>
                <?php
                $pdfBase = array_filter(['date_from'=>$dateFrom,'date_to'=>$dateTo,'filter_type'=>$filterType,'employee_id'=>$employeeId,'branch_id'=>$branchId]);
                ?>
                <a href="late-report-pdf.php?<?= http_build_query($pdfBase) ?>" target="_blank"
                   class="btn" style="background:#7C3AED;color:#fff">🖨️ PDF</a>
                <a href="late-report-pdf.php?<?= http_build_query(array_merge($pdfBase,['bulk_mode'=>'employees'])) ?>" target="_blank"
                   class="btn" style="background:#0891B2;color:#fff;font-size:.82rem">📄 PDF لكل موظف</a>
                <a href="late-report-pdf.php?<?= http_build_query(array_merge($pdfBase,['bulk_mode'=>'branches'])) ?>" target="_blank"
                   class="btn" style="background:#0F766E;color:#fff;font-size:.82rem">🏢 PDF لكل فرع</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($lateRecords)): ?>
    <div class="report-empty" style="background:var(--surface);border-radius:var(--radius);border:1px solid var(--border)">
        <?= svgIcon('checkin', 64) ?>
        <p style="font-size:1.1rem;font-weight:600;margin-top:12px">لا توجد حالات تأخير في الفترة المحددة</p>
        <p>جميع الموظفين ملتزمون بأوقات الحضور</p>
    </div>
<?php else: ?>

    <!-- الإحصائيات -->
    <div class="report-stats">
        <div class="report-stat accent-orange">
            <div class="report-stat-icon is-orange"><?= svgIcon('clock', 24) ?></div>
            <div>
                <div class="report-stat-value"><?= number_format($totalLateMinutes) ?></div>
                <div class="report-stat-label">إجمالي دقائق التأخير</div>
            </div>
        </div>
        <div class="report-stat accent-red">
            <div class="report-stat-icon is-red"><?= svgIcon('attendance', 24) ?></div>
            <div>
                <div class="report-stat-value"><?= $totalLateDays ?></div>
                <div class="report-stat-label">عدد أيام التأخير</div>
            </div>
        </div>
        <div class="report-stat accent-blue">
            <div class="report-stat-icon is-blue"><?= svgIcon('employees', 24) ?></div>
            <div>
                <div class="report-stat-value"><?= $uniqueEmployees ?></div>
                <div class="report-stat-label">موظفون متأخرون</div>
            </div>
        </div>
        <div class="report-stat accent-purple">
            <div class="report-stat-icon is-purple"><?= svgIcon('late', 24) ?></div>
            <div>
                <div class="report-stat-value"><?= $totalLateDays ? round($totalLateMinutes / $totalLateDays) : 0 ?></div>
                <div class="report-stat-label">متوسط التأخير (دقيقة/يوم)</div>
            </div>
        </div>
    </div>

    <?php
    $totalHours = floor($totalLateMinutes / 60);
    $remainMins = $totalLateMinutes % 60;
    ?>
    <div class="card" style="margin-bottom:20px;text-align:center;padding:16px;background:linear-gradient(135deg,var(--surface),var(--surface2))">
        <span style="font-size:.85rem;color:var(--text2)">الإجمالي الكلي: </span>
        <strong style="font-size:1.1rem;color:var(--primary)"><?= $totalHours ?> ساعة و <?= $remainMins ?> دقيقة</strong>
    </div>

    <!-- ملخص حسب الموظف -->
    <div class="report-table-wrap" style="margin-bottom:20px">
        <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
            <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('employees', 18) ?> ملخص التأخير حسب الموظف</span>
        </div>
        <div style="overflow-x:auto">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>الوظيفة</th>
                        <th>الفرع</th>
                        <th>أيام التأخير</th>
                        <th>إجمالي (دقيقة)</th>
                        <th>أقصى تأخير</th>
                        <th>المتوسط</th>
                        <th>التقييم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $seq = 0;
                    foreach ($employeeStats as $empId => $stat): $seq++; ?>
                        <tr>
                            <td style="color:var(--text3)"><?= $seq ?></td>
                            <td><strong><?= htmlspecialchars($stat['employee_name']) ?></strong></td>
                            <td style="font-size:.8rem;color:var(--text2)"><?= htmlspecialchars($stat['job_title']) ?></td>
                            <td style="font-size:.8rem"><?= htmlspecialchars($stat['branch_name'] ?? '-') ?></td>
                            <td style="font-weight:700"><?= $stat['total_late_days'] ?></td>
                            <td>
                                <?php
                                $mins = $stat['total_late_minutes'];
                                $cls = $mins < 30 ? 'late-low' : ($mins < 120 ? 'late-medium' : ($mins < 300 ? 'late-high' : 'late-critical'));
                                ?>
                                <span class="late-badge <?= $cls ?>"><?= $mins ?> دقيقة</span>
                            </td>
                            <td style="font-weight:600"><?= $stat['max_late'] ?> د</td>
                            <td><?= round($stat['total_late_minutes'] / $stat['total_late_days']) ?> د/يوم</td>
                            <td>
                                <?php
                                $avg = $stat['total_late_minutes'] / max(1, $stat['total_late_days']);
                                if ($avg < 10) echo '<span class="badge badge-green">جيد</span>';
                                elseif ($avg < 30) echo '<span class="badge badge-yellow">متوسط</span>';
                                elseif ($avg < 60) echo '<span class="badge badge-red">ضعيف</span>';
                                else echo '<span class="badge badge-red" style="background:#EF4444;color:#fff">سيء</span>';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- التفاصيل اليومية -->
    <div class="report-table-wrap">
        <div class="card-header" style="padding:16px 20px;margin:0;border-bottom:2px solid var(--surface3)">
            <span class="card-title"><span class="card-title-bar"></span> <?= svgIcon('calendar', 18) ?> التفاصيل اليومية</span>
        </div>
        <div style="overflow-x:auto">
            <table class="att-table">
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
                    <?php $seq = 0;
                    foreach ($lateRecords as $rec): $seq++; ?>
                        <tr>
                            <td style="color:var(--text3)"><?= $seq ?></td>
                            <td style="font-weight:600"><?= $rec['attendance_date'] ?></td>
                            <td style="font-size:.8rem;color:var(--text2)"><?php
                                                                            $days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
                                                                            echo $days[date('w', strtotime($rec['attendance_date']))] ?? '';
                                                                            ?></td>
                            <td><strong><?= htmlspecialchars($rec['employee_name']) ?></strong></td>
                            <td style="font-size:.8rem"><?= htmlspecialchars($rec['branch_name'] ?? '-') ?></td>
                            <td style="color:var(--text2);font-family:monospace"><?= $rec['work_start_time'] ?? '-' ?></td>
                            <td style="color:var(--primary);font-weight:700;font-family:monospace"><?= date('H:i:s', strtotime($rec['checkin_time'])) ?></td>
                            <td>
                                <?php
                                $lm = $rec['late_minutes'];
                                $cls = $lm < 15 ? 'late-low' : ($lm < 45 ? 'late-medium' : ($lm < 90 ? 'late-high' : 'late-critical'));
                                if ($lm >= 60) {
                                    $h = floor($lm / 60);
                                    $m = $lm % 60;
                                    $txt = "{$h} س {$m} د";
                                } else {
                                    $txt = "{$lm} دقيقة";
                                }
                                ?>
                                <span class="late-badge <?= $cls ?>"><?= $txt ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
    function toggleFilterInputs() {
        const type = document.getElementById('filterType').value;
        document.getElementById('employeeFilter').style.display = type === 'employee' ? 'block' : 'none';
        document.getElementById('branchFilter').style.display = type === 'branch' ? 'block' : 'none';
    }

    // فلتر الورديات الديناميكي
    (function(){
        const branchShifts = <?= json_encode(getAllBranchShifts(), JSON_UNESCAPED_UNICODE) ?>;
        const branchSel = document.querySelector('select[name="branch_id"]');
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

    function tick() {
        const el = document.getElementById('topbarClock');
        if (el) el.textContent = new Date().toLocaleString('ar-SA');
    }
    tick();
    setInterval(tick, 1000);

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    }
    document.getElementById('sidebarOverlay')?.addEventListener('click', toggleSidebar);
</script>

</div>
</div>
</body>

</html>