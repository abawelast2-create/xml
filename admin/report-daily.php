<?php
// =============================================================
// admin/report-daily.php - تقرير يومي مفصّل للطباعة
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$date         = $_GET['date']   ?? date('Y-m-d');
$filterBranch = (int)($_GET['branch'] ?? 0);
$filterShift  = (int)($_GET['shift'] ?? 0);

// التحقق من التاريخ
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// =========================================================
// جلب قائمة الفروع للفلتر
// =========================================================
$branchList = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// =========================================================
// فلتر الوردية
// =========================================================
$shiftTimeCond = '';
$shiftTimeParams = [];
if ($filterShift > 0) {
    $sf = buildShiftTimeFilter($filterShift, '');
    if ($sf) { $shiftTimeCond = "AND " . $sf['sql']; $shiftTimeParams = $sf['params']; }
}

// =========================================================
// جلب الموظفين مع سجلات الحضور الخاصة بهم
// =========================================================
$branchWhere = $filterBranch > 0 ? "AND e.branch_id = ?" : "";
$params = array_merge([$date], $shiftTimeParams, [$date], $shiftTimeParams);
if ($filterBranch > 0) { $params[] = $filterBranch; }

$sql = "
    SELECT
        e.id                  AS emp_id,
        e.name                AS emp_name,
        e.job_title,
        b.name                AS branch_name,
        ci.timestamp          AS check_in_ts,
        ci.late_minutes       AS late_min,
        co.timestamp          AS check_out_ts
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN (
        SELECT employee_id, MIN(timestamp) AS timestamp, MIN(late_minutes) AS late_minutes
        FROM attendances
        WHERE type = 'in' AND attendance_date = ?
        $shiftTimeCond
        GROUP BY employee_id
    ) ci ON ci.employee_id = e.id
    LEFT JOIN (
        SELECT employee_id, MAX(timestamp) AS timestamp
        FROM attendances
        WHERE type = 'out' AND attendance_date = ?
        $shiftTimeCond
        GROUP BY employee_id
    ) co ON co.employee_id = e.id
    WHERE e.is_active = 1 AND e.deleted_at IS NULL
    $branchWhere
    ORDER BY b.name, e.name
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// =========================================================
// احصائيات
// =========================================================
$totalEmp   = count($rows);
$totalIn    = 0;
$totalLate  = 0;
$totalEarly = 0;
$totalAbsent= 0;

foreach ($rows as $r) {
    if ($r['check_in_ts']) {
        $totalIn++;
        if ((int)($r['late_min'] ?? 0) > 0) $totalLate++;
    } else {
        $totalAbsent++;
    }
}

// اسم الفرع المحدد
$selectedBranchName = 'جميع الفروع';
foreach ($branchList as $b) {
    if ($b['id'] == $filterBranch) { $selectedBranchName = $b['name']; break; }
}

// تنسيق التاريخ بالعربية
$dateObj   = new DateTime($date);
$dayNames  = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$dayOfWeek = $dayNames[(int)$dateObj->format('w')];
$dateAr    = $dayOfWeek . '، ' . $dateObj->format('j') . ' / ' . $dateObj->format('n') . ' / ' . $dateObj->format('Y');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تقرير الحضور - <?= htmlspecialchars($date) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --navy: #1a2744;
    --navy-light: #243454;
    --gold: #c9a84c;
    --gold-dark: #b8962e;
    --gold-light: #e8d9a0;
    --gold-bg: #fdf8ed;
    --cream: #faf7f0;
  }

  body {
    font-family: 'Tajawal', 'Arial', sans-serif;
    background: var(--cream);
    color: #1a1a2e;
    direction: rtl;
    font-size: 13px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  /* ===== شريط الأدوات (لا يطبع) ===== */
  .toolbar {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
    color: #fff;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    border-bottom: 3px solid var(--gold);
  }
  @media print { .toolbar { display: none !important; } }

  .toolbar .tb-title { font-size: 1rem; font-weight: 700; }
  .toolbar .tb-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .tb-controls input[type=date],
  .tb-controls select {
    padding: 6px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,.2);
    font-family: 'Tajawal', sans-serif; font-size: .85rem;
    background: rgba(255,255,255,.1); color: #fff;
  }
  .tb-controls select option { background: var(--navy); color: #fff; }
  .btn-print {
    background: var(--gold); color: var(--navy); border: none; border-radius: 8px;
    padding: 8px 20px; cursor: pointer; font-family: 'Tajawal', sans-serif;
    font-size: .9rem; font-weight: 700;
    display: flex; align-items: center; gap: 6px;
    transition: all .2s;
  }
  .btn-print:hover { background: var(--gold-dark); }
  .btn-back {
    background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.2);
    border-radius: 8px; padding: 8px 16px; cursor: pointer;
    font-family: 'Tajawal', sans-serif; font-size: .85rem; text-decoration: none;
    display: flex; align-items: center; gap: 6px;
    transition: all .2s;
  }
  .btn-back:hover { background: rgba(255,255,255,.2); }

  /* ===== الصفحة الرئيسية ===== */
  .page {
    max-width: 960px;
    margin: 24px auto;
    background: #fff;
    border-radius: 2px;
    box-shadow: 0 2px 40px rgba(26,39,68,.12);
    overflow: hidden;
    border: 1px solid #e5dcc8;
    position: relative;
  }

  /* ===== العلامة المائية ===== */
  .page::before {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 400px; height: 400px;
    background: url('../assets/images/loogo.png') center/contain no-repeat;
    opacity: .04;
    pointer-events: none;
    z-index: 0;
  }
  .page > * { position: relative; z-index: 1; }

  @media print {
    body { background: #fff; font-size: 11px; }
    .page {
      max-width: 100%; margin: 0;
      border-radius: 0; box-shadow: none; border: none;
    }
    .page::before { opacity: .035; width: 350px; height: 350px; }
  }

  /* ===== رأس التقرير ===== */
  .report-header {
    background: linear-gradient(135deg, var(--navy) 0%, #1f3554 50%, var(--navy-light) 100%);
    color: #fff;
    padding: 0;
    position: relative;
    overflow: hidden;
  }
  .report-header::before {
    content: '';
    position: absolute;
    top: 0; right: 0; left: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--gold-dark), var(--gold), var(--gold-light), var(--gold), var(--gold-dark));
  }
  .report-header::after {
    content: '';
    position: absolute;
    bottom: 0; right: 0; left: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--gold-dark), var(--gold), var(--gold-light), var(--gold), var(--gold-dark));
  }

  .rh-inner {
    padding: 28px 32px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
  }

  .rh-logo {
    width: 70px; height: 70px;
    object-fit: contain;
    filter: drop-shadow(0 2px 8px rgba(201,168,76,.4));
  }

  .rh-center {
    text-align: center;
    flex: 1;
  }
  .rh-system-name {
    font-size: 1.6rem; font-weight: 900;
    letter-spacing: .5px;
    color: var(--gold-light);
    text-shadow: 0 1px 4px rgba(0,0,0,.3);
  }
  .rh-subtitle {
    font-size: .88rem;
    color: rgba(255,255,255,.7);
    margin-top: 4px;
    font-weight: 300;
    letter-spacing: .3px;
  }
  .rh-divider {
    width: 80px; height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    margin: 10px auto 0;
  }

  .rh-date-box {
    text-align: center;
    background: rgba(201,168,76,.12);
    border: 1px solid rgba(201,168,76,.3);
    border-radius: 10px;
    padding: 10px 20px;
  }
  .rh-date-big {
    font-size: 1.2rem; font-weight: 800;
    color: var(--gold-light);
    letter-spacing: 1px;
  }
  .rh-date-day {
    font-size: .8rem;
    color: rgba(255,255,255,.65);
    margin-top: 2px;
  }

  .rh-meta {
    background: rgba(0,0,0,.15);
    padding: 10px 32px;
    display: flex;
    gap: 28px;
    flex-wrap: wrap;
    font-size: .8rem;
    color: rgba(255,255,255,.75);
    justify-content: center;
  }
  .rh-meta span { display: inline-flex; align-items: center; gap: 6px; }
  .rh-meta span::before {
    content: '';
    width: 5px; height: 5px;
    background: var(--gold);
    border-radius: 50%;
    flex-shrink: 0;
  }

  /* ===== بطاقات الإحصائيات ===== */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    border-bottom: 2px solid var(--gold);
  }
  .stat-box {
    background: #fff;
    padding: 18px 12px;
    text-align: center;
    border-left: 1px solid #f0ead8;
    position: relative;
  }
  .stat-box:last-child { border-left: none; }
  .stat-num {
    font-size: 2rem;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 4px;
  }
  .stat-lbl {
    font-size: .76rem;
    color: #8b8778;
    font-weight: 500;
    letter-spacing: .3px;
  }
  .stat-box.blue   .stat-num { color: var(--navy); }
  .stat-box.green  .stat-num { color: #1a7a3a; }
  .stat-box.red    .stat-num { color: #b91c1c; }
  .stat-box.amber  .stat-num { color: var(--gold-dark); }

  /* ===== الجدول ===== */
  .table-wrap { overflow-x: auto; padding: 0 16px 16px; }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
  }
  thead tr {
    background: linear-gradient(180deg, #f7f3e8 0%, #f0ead8 100%);
  }
  thead th {
    padding: 12px 10px;
    text-align: right;
    font-weight: 700;
    color: var(--navy);
    border-bottom: 2px solid var(--gold);
    white-space: nowrap;
    font-size: .8rem;
    letter-spacing: .3px;
  }
  tbody tr {
    border-bottom: 1px solid #f0ead8;
  }
  tbody tr:nth-child(even) { background: #fdfbf6; }
  tbody tr:hover { background: var(--gold-bg); }
  tbody tr.absent-row { background: #fef5f5; }
  @media print {
    tbody tr:hover { background: transparent; }
    tbody tr:nth-child(even) { background: #fdfbf6 !important; }
    tbody tr.absent-row { background: #fef5f5 !important; }
  }
  tbody td {
    padding: 10px 10px;
    vertical-align: middle;
  }
  .num-cell {
    color: var(--gold-dark);
    font-size: .8rem;
    width: 32px;
    text-align: center;
    font-weight: 700;
  }

  /* ===== الحالة (بادج) ===== */
  .badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 4px;
    font-size: .76rem;
    font-weight: 700;
    letter-spacing: .3px;
    border: 1px solid transparent;
  }
  .badge-present {
    background: #edf7f0;
    color: #15803d;
    border-color: #bbf0c9;
  }
  .badge-late {
    background: #fdf6e3;
    color: #92650a;
    border-color: #f0dfa0;
  }
  .badge-absent {
    background: #fef2f2;
    color: #b91c1c;
    border-color: #fecaca;
  }

  /* الوقت */
  .time-val {
    font-weight: 700;
    color: var(--navy);
    font-size: .95rem;
    font-feature-settings: 'tnum';
  }
  .time-absent { color: #d1d5db; font-size: .85rem; }
  .duration-val { color: #4a4637; font-size: .85rem; font-weight: 500; }

  /* ===== تذييل التقرير ===== */
  .report-footer {
    border-top: 2px solid var(--gold);
    background: linear-gradient(180deg, #fdfbf6 0%, #f7f3e8 100%);
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    flex-wrap: wrap;
    font-size: .82rem;
    color: #6b6556;
  }
  .sig-box {
    text-align: center;
    min-width: 170px;
  }
  .sig-line {
    border-top: 2px solid var(--navy);
    margin-top: 50px;
    padding-top: 8px;
    font-weight: 700;
    color: var(--navy);
    font-size: .78rem;
  }
  .report-footer .gen-info { font-size: .78rem; line-height: 1.8; }
  .report-footer .gen-info p { margin-bottom: 2px; }
  .report-footer .gen-info strong { color: var(--navy); }

  .footer-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
  }
  .footer-brand img {
    width: 28px; height: 28px;
    object-fit: contain;
  }
  .footer-brand span {
    font-weight: 800;
    color: var(--navy);
    font-size: .88rem;
  }

  /* row branch separator */
  tr.branch-header td {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
    color: var(--gold-light);
    font-weight: 700;
    font-size: .85rem;
    padding: 8px 14px;
    text-align: right;
    letter-spacing: .3px;
  }

  /* ===== طباعة ===== */
  @page {
    size: A4;
    margin: 10mm 8mm;
  }
</style>
</head>
<body>

<!-- شريط الأدوات (لا يطبع) -->
<div class="toolbar">
  <span class="tb-title">تقرير الحضور اليومي</span>
  <div class="tb-controls">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
      <select name="branch" id="branchSelect">
        <option value="0" <?= !$filterBranch ? 'selected' : '' ?>>جميع الفروع</option>
        <?php foreach ($branchList as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="shift" id="shiftSelect">
        <option value="0">كل الورديات</option>
      </select>
      <button type="submit" class="btn-print" style="background:#475569">عرض</button>
    </form>
    <button class="btn-print" onclick="window.print()">🖨️ طباعة / PDF</button>
    <a href="attendance.php" class="btn-back">← العودة</a>
  </div>
</div>

<!-- الصفحة الرئيسية -->
<div class="page">

  <!-- رأس التقرير -->
  <div class="report-header">
    <div class="rh-inner">
      <div class="rh-date-box">
        <div class="rh-date-big"><?= $dateObj->format('Y/m/d') ?></div>
        <div class="rh-date-day"><?= $dayOfWeek ?></div>
      </div>
      <div class="rh-center">
        <img src="../assets/images/loogo.png" alt="Logo" class="rh-logo">
        <div class="rh-system-name">نظام الحضور والانصراف</div>
        <div class="rh-subtitle">سجل الحضور اليومي الرسمي</div>
        <div class="rh-divider"></div>
      </div>
      <div style="width:70px"></div>
    </div>
    <div class="rh-meta">
      <span>الفرع: <?= htmlspecialchars($selectedBranchName) ?></span>
      <?php if ($filterShift > 0): 
          $sfInfo = db()->prepare("SELECT shift_number, shift_start, shift_end FROM branch_shifts WHERE id = ?");
          $sfInfo->execute([$filterShift]);
          $sfRow = $sfInfo->fetch();
          if ($sfRow): ?>
          <span>الوردية: <?= $sfRow['shift_number'] ?> (<?= substr($sfRow['shift_start'],0,5) ?> - <?= substr($sfRow['shift_end'],0,5) ?>)</span>
          <?php endif; endif; ?>
      <span>إجمالي الموظفين: <?= $totalEmp ?></span>
      <span>صدر بتاريخ: <?= date('Y/m/d H:i') ?></span>
    </div>
  </div>

  <!-- إحصائيات -->
  <div class="stats-row">
    <div class="stat-box blue">
      <div class="stat-num"><?= $totalEmp ?></div>
      <div class="stat-lbl">إجمالي الموظفين</div>
    </div>
    <div class="stat-box green">
      <div class="stat-num"><?= $totalIn ?></div>
      <div class="stat-lbl">حاضرون</div>
    </div>
    <div class="stat-box red">
      <div class="stat-num"><?= $totalAbsent ?></div>
      <div class="stat-lbl">غائبون</div>
    </div>
    <div class="stat-box amber">
      <div class="stat-num"><?= $totalLate ?></div>
      <div class="stat-lbl">متأخرون</div>
    </div>
  </div>

  <!-- الجدول الرئيسي -->
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th class="num-cell">#</th>
        <th>اسم الموظف</th>
        <th>المسمى الوظيفي</th>
        <th>الفرع</th>
        <th>وقت الحضور</th>
        <th>وقت الانصراف</th>
        <th>مدة العمل</th>
        <th>التأخير</th>
        <th>الحالة</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $serial = 0;
    $lastBranch = null;
    foreach ($rows as $r):
        $serial++;
        $isAbsent   = !$r['check_in_ts'];
        $lateMin    = (int)($r['late_min'] ?? 0);
        $isLate     = !$isAbsent && $lateMin > 0;
        $isPresent  = !$isAbsent;

        // فاصل الفرع
        if ($r['branch_name'] !== $lastBranch) {
            $lastBranch = $r['branch_name'];
    ?>
    <tr class="branch-header">
      <td colspan="9">فرع: <?= htmlspecialchars($r['branch_name'] ?? 'بدون فرع') ?></td>
    </tr>
    <?php } ?>

    <tr class="<?= $isAbsent ? 'absent-row' : '' ?>">
      <td class="num-cell"><?= $serial ?></td>
      <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong></td>
      <td style="color:#6b7280;font-size:.82rem"><?= htmlspecialchars($r['job_title'] ?? '') ?></td>
      <td style="color:#374151;font-size:.82rem"><?= htmlspecialchars($r['branch_name'] ?? '-') ?></td>
      <td>
        <?php if ($r['check_in_ts']): ?>
          <span class="time-val"><?= date('h:i A', strtotime($r['check_in_ts'])) ?></span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($r['check_out_ts']): ?>
          <span class="time-val" style="color:#7c3aed"><?= date('h:i A', strtotime($r['check_out_ts'])) ?></span>
        <?php elseif (!$isAbsent): ?>
          <span style="color:#f59e0b;font-size:.82rem">لم ينصرف</span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($r['check_in_ts'] && $r['check_out_ts']): ?>
          <?php
            $inTs  = strtotime($r['check_in_ts']);
            $outTs = strtotime($r['check_out_ts']);
            // إذا كان الانصراف في اليوم التالي (وردية تتجاوز منتصف الليل)
            if ($outTs < $inTs) $outTs += 86400;
            $diff  = $outTs - $inTs;
            $hrs   = floor($diff / 3600);
            $mins  = floor(($diff % 3600) / 60);
          ?>
          <span class="duration-val"><?= $hrs ?>س <?= $mins ?>د</span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isLate): ?>
          <span style="color:#d97706;font-size:.82rem;font-weight:700">
            <?= $lateMin >= 60
              ? floor($lateMin/60).'س '.($lateMin%60).'د'
              : $lateMin.'د' ?>
          </span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isAbsent): ?>
          <span class="badge badge-absent">غائب</span>
        <?php elseif ($isLate): ?>
          <span class="badge badge-late">متأخر</span>
        <?php else: ?>
          <span class="badge badge-present">حاضر</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="9" style="text-align:center;padding:32px;color:#9ca3af">لا يوجد موظفون</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <!-- تذييل التقرير -->
  <div class="report-footer">
    <div class="gen-info">
      <div class="footer-brand">
        <img src="../assets/images/loogo.png" alt="">
        <span>نظام الحضور والانصراف</span>
      </div>
      <p>تاريخ التقرير: <?= $dateAr ?></p>
      <p>الفرع: <?= htmlspecialchars($selectedBranchName) ?></p>
      <p>وقت الإصدار: <?= date('Y/m/d — H:i:s') ?></p>
    </div>
    <div style="display:flex;gap:60px">
      <div class="sig-box">
        <div class="sig-line">توقيع المدير</div>
      </div>
      <div class="sig-box">
        <div class="sig-line">توقيع مسؤول الموارد البشرية</div>
      </div>
    </div>
  </div>

</div><!-- /.page -->

<script>
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
// طباعة تلقائية إذا طُلب ذلك من URL
if (new URLSearchParams(location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
