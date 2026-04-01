<?php
// =============================================================
// admin/tampering.php - حالات التلاعب والمخالفات
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'حالات التلاعب';
$activePage = 'tampering';

// فلاتر
$dateFrom     = $_GET['date_from'] ?? date('Y-m-01');
$dateTo       = $_GET['date_to']   ?? date('Y-m-d');
$caseType     = $_GET['case_type'] ?? '';
$filterBranch = (int)($_GET['branch'] ?? 0);

$whereConditions = ["tc.created_at BETWEEN ? AND ?"];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($caseType) {
  $whereConditions[] = "tc.case_type = ?";
  $params[] = $caseType;
}
if ($filterBranch) {
  $whereConditions[] = "e.branch_id = ?";
  $params[] = $filterBranch;
}

$whereClause = implode(' AND ', $whereConditions);

// جلب الحالات
$stmt = db()->prepare("
    SELECT tc.*, e.name AS employee_name, e.job_title, b.name AS branch_name
    FROM tampering_cases tc
    JOIN employees e ON tc.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$whereClause}
    ORDER BY tc.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$cases = $stmt->fetchAll();

// إحصائيات
$statsStmt = db()->prepare("
    SELECT 
        COUNT(*) AS total,
        COUNT(CASE WHEN tc.case_type = 'different_device' THEN 1 END) AS device_mismatch,
        COUNT(CASE WHEN tc.case_type = 'location_spoof' THEN 1 END) AS location_spoof,
        COUNT(CASE WHEN tc.case_type = 'proxy_checkin' THEN 1 END) AS proxy_checkin,
        COUNT(CASE WHEN tc.severity = 'high' THEN 1 END) AS high_severity,
        COUNT(DISTINCT tc.employee_id) AS unique_employees
    FROM tampering_cases tc
    JOIN employees e ON tc.employee_id = e.id
    WHERE {$whereClause}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

// أكثر الموظفين تلاعبا
$topStmt = db()->prepare("
    SELECT e.id, e.name, e.job_title, b.name AS branch_name, COUNT(*) AS case_count,
           SUM(CASE WHEN tc.severity = 'high' THEN 1 ELSE 0 END) AS high_count
    FROM tampering_cases tc
    JOIN employees e ON tc.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$whereClause}
    GROUP BY e.id, e.name, e.job_title, b.name
    ORDER BY case_count DESC
    LIMIT 20
");
$topStmt->execute($params);
$topEmployees = $topStmt->fetchAll();

// قائمة الفروع للفلتر
$branchList = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// أنواع التلاعب
$caseTypes = [
  'different_device' => ['label' => 'جهاز مختلف',   'icon' => '📱', 'color' => '#F59E0B'],
  'location_spoof'   => ['label' => 'تزوير موقع',   'icon' => '📍', 'color' => '#EF4444'],
  'proxy_checkin'    => ['label' => 'تسجيل بالنيابة', 'icon' => '👥', 'color' => '#8B5CF6'],
  'outside_geofence' => ['label' => 'خارج النطاق',   'icon' => '🚫', 'color' => '#EC4899'],
  'rapid_succession' => ['label' => 'تسجيل متكرر',   'icon' => '⚡', 'color' => '#06B6D4'],
  'other'            => ['label' => 'أخرى',          'icon' => '⚠️', 'color' => '#6B7280'],
];

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
  .filter-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border)
  }

  .filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 14px;
    margin-top: 12px
  }

  .filter-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--text2);
    font-size: .82rem
  }

  .filter-group select,
  .filter-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: inherit;
    font-size: .88rem;
    background: var(--surface)
  }

  .filter-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
    flex-wrap: wrap
  }

  .case-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: .78rem;
    font-weight: 600
  }

  .severity-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block
  }

  .severity-low {
    background: #22C55E
  }

  .severity-medium {
    background: #F59E0B
  }

  .severity-high {
    background: #EF4444;
    animation: blink 1.5s infinite
  }

  @keyframes blink {

    0%,
    100% {
      opacity: 1
    }

    50% {
      opacity: .4
    }
  }

  .top-emp-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 12px 16px;
    transition: all .2s
  }

  .top-emp-card:hover {
    box-shadow: var(--shadow)
  }

  .emp-rank {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: .85rem;
    color: #fff;
    flex-shrink: 0
  }

  .section-title {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 24px 0 12px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px
  }
</style>

<!-- فلاتر -->
<div class="filter-card">
  <div style="font-weight:700;font-size:.95rem;margin-bottom:8px">🔍 تصفية الحالات</div>
  <form method="GET">
    <div class="filter-grid">
      <div class="filter-group">
        <label>من تاريخ</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="filter-group">
        <label>إلى تاريخ</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <div class="filter-group">
        <label>نوع التلاعب</label>
        <select name="case_type">
          <option value="">الكل</option>
          <?php foreach ($caseTypes as $key => $ct): ?>
            <option value="<?= $key ?>" <?= $caseType === $key ? 'selected' : '' ?>><?= $ct['icon'] ?> <?= $ct['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>الفرع</label>
        <select name="branch">
          <option value="0">الكل</option>
          <?php foreach ($branchList as $br): ?>
            <option value="<?= $br['id'] ?>" <?= $filterBranch == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary">🔍 بحث</button>
      <a href="tampering.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
    </div>
  </form>
</div>

<!-- إحصائيات -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon-wrap orange"><?= svgIcon('attendance', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['total'] ?></div>
      <div class="stat-label">إجمالي الحالات</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-wrap blue"><?= svgIcon('employees', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['unique_employees'] ?></div>
      <div class="stat-label">موظفون مشتبه بهم</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-wrap red"><?= svgIcon('absent', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['high_severity'] ?></div>
      <div class="stat-label">حالات خطيرة</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-wrap purple"><?= svgIcon('lock', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['device_mismatch'] ?></div>
      <div class="stat-label">أجهزة مختلفة</div>
    </div>
  </div>
</div>

<?php if (!empty($topEmployees)): ?>
  <div class="section-title">🏆 أكثر الموظفين حالات (ترتيب تنازلي)</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;margin-bottom:20px">
    <?php foreach ($topEmployees as $i => $emp):
      $rankColors = ['#EF4444', '#F59E0B', '#F97316', '#8B5CF6', '#6366F1'];
      $rankColor = $rankColors[min($i, count($rankColors) - 1)];
    ?>
      <div class="top-emp-card">
        <div class="emp-rank" style="background:<?= $rankColor ?>"><?= $i + 1 ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:.88rem"><?= htmlspecialchars($emp['name']) ?></div>
          <div style="font-size:.72rem;color:var(--text3)"><?= htmlspecialchars($emp['job_title']) ?> — <?= htmlspecialchars($emp['branch_name'] ?? '-') ?></div>
        </div>
        <div style="text-align:center">
          <div style="font-size:1.2rem;font-weight:800;color:<?= $rankColor ?>"><?= $emp['case_count'] ?></div>
          <div style="font-size:.62rem;color:var(--text3)">حالة</div>
        </div>
        <?php if ($emp['high_count'] > 0): ?>
          <span class="badge badge-red" style="font-size:.65rem"><?= $emp['high_count'] ?> خطيرة</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- سجل الحالات -->
<div class="section-title"><?= svgIcon('attendance', 20) ?> سجل الحالات (<?= count($cases) ?>)</div>
<div class="card">
  <?php if (empty($cases)): ?>
    <div style="text-align:center;padding:50px;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:12px">✅</div>
      <div style="font-size:1.05rem;font-weight:600">لا توجد حالات تلاعب في الفترة المحددة</div>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="att-table">
        <thead>
          <tr>
            <th>#</th>
            <th>التاريخ</th>
            <th>الموظف</th>
            <th>الفرع</th>
            <th>النوع</th>
            <th>الخطورة</th>
            <th>الوصف</th>
            <th>تفاصيل</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cases as $i => $c):
            $ct = $caseTypes[$c['case_type']] ?? $caseTypes['other'];
          ?>
            <tr>
              <td style="color:var(--text3)"><?= $i + 1 ?></td>
              <td style="font-size:.8rem;white-space:nowrap"><?= date('Y-m-d H:i', strtotime($c['created_at'])) ?></td>
              <td>
                <strong><?= htmlspecialchars($c['employee_name']) ?></strong><br>
                <small style="color:var(--text3)"><?= htmlspecialchars($c['job_title']) ?></small>
              </td>
              <td style="font-size:.8rem"><?= htmlspecialchars($c['branch_name'] ?? '-') ?></td>
              <td>
                <span class="case-type-badge" style="background:<?= $ct['color'] ?>15;color:<?= $ct['color'] ?>;border:1px solid <?= $ct['color'] ?>30">
                  <?= $ct['icon'] ?> <?= $ct['label'] ?>
                </span>
              </td>
              <td style="text-align:center">
                <span class="severity-dot severity-<?= $c['severity'] ?>" title="<?= $c['severity'] ?>"></span>
                <span style="font-size:.7rem;color:var(--text3);margin-right:4px">
                  <?= $c['severity'] === 'high' ? 'خطيرة' : ($c['severity'] === 'medium' ? 'متوسطة' : 'منخفضة') ?>
                </span>
              </td>
              <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($c['description'] ?? '') ?></td>
              <td>
                <?php if ($c['details_json']): ?>
                  <button class="btn btn-secondary btn-sm" onclick="showDetails(<?= htmlspecialchars(json_encode($c['details_json'])) ?>)" title="عرض التفاصيل">🔎</button>
                <?php else: ?>
                  <span style="color:var(--text3)">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Modal تفاصيل -->
<div class="modal-overlay" id="detailsModal">
  <div class="modal">
    <div class="modal-title"><?= svgIcon('attendance', 20) ?> تفاصيل الحالة</div>
    <pre id="detailsContent" style="background:var(--surface2);padding:16px;border-radius:8px;font-size:.82rem;direction:ltr;text-align:left;overflow-x:auto;max-height:400px;white-space:pre-wrap;word-break:break-all"></pre>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" onclick="closeModal('detailsModal')">إغلاق</button>
    </div>
  </div>
</div>

<script>
  function openModal(id) {
    document.getElementById(id).classList.add('show');
  }

  function closeModal(id) {
    document.getElementById(id).classList.remove('show');
  }

  function showDetails(json) {
    try {
      const obj = typeof json === 'string' ? JSON.parse(json) : json;
      document.getElementById('detailsContent').textContent = JSON.stringify(obj, null, 2);
    } catch (e) {
      document.getElementById('detailsContent').textContent = json;
    }
    openModal('detailsModal');
  }

  document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => {
      if (e.target === o) o.classList.remove('show');
    });
  });

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