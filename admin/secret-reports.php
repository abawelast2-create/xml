<?php
// =============================================================
// admin/secret-reports.php - استعراض التقارير السرية (للمشرف)
// يعرض اسم الموظف + النص + الصور + الصوت
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'التقارير السرية';
$activePage = 'secret-reports';

// معالجة تغيير حالة التقرير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: secret-reports.php?msg=خطأ+أمني&t=error');
    exit;
  }

  $reportId = (int)($_POST['report_id'] ?? 0);
  $action   = $_POST['action'];

  if ($action === 'update_status' && $reportId) {
    $newStatus = $_POST['new_status'] ?? 'reviewed';
    $allowed = ['new', 'reviewed', 'in_progress', 'resolved', 'dismissed'];
    if (in_array($newStatus, $allowed)) {
      $adminNotes = trim($_POST['admin_notes'] ?? '');
      $stmt = db()->prepare("UPDATE secret_reports SET status = ?, admin_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
      $stmt->execute([$newStatus, $adminNotes, $_SESSION['admin_id'] ?? 0, $reportId]);
      header('Location: secret-reports.php?msg=تم+تحديث+الحالة&t=success');
      exit;
    }
  }

  if ($action === 'delete' && $reportId) {
    // Delete files first
    $report = db()->prepare("SELECT image_paths, voice_path FROM secret_reports WHERE id = ?");
    $report->execute([$reportId]);
    $r = $report->fetch();
    if ($r) {
      if ($r['image_paths']) {
        $imgs = json_decode($r['image_paths'], true) ?: [];
        foreach ($imgs as $img) {
          $path = __DIR__ . '/../' . $img;
          if (file_exists($path)) @unlink($path);
        }
      }
      if ($r['voice_path']) {
        $vpath = __DIR__ . '/../' . $r['voice_path'];
        if (file_exists($vpath)) @unlink($vpath);
      }
    }
    $stmt = db()->prepare("DELETE FROM secret_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    header('Location: secret-reports.php?msg=تم+حذف+التقرير&t=success');
    exit;
  }
}

// فلاتر
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$dateFrom     = $_GET['date_from'] ?? '';
$dateTo       = $_GET['date_to']   ?? '';

$where  = ["1=1"];
$params = [];

if ($filterStatus) {
  $where[]  = "sr.status = ?";
  $params[] = $filterStatus;
}
if ($filterType) {
  $where[]  = "sr.report_type = ?";
  $params[] = $filterType;
}
if ($dateFrom) {
  $where[]  = "sr.created_at >= ?";
  $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
  $where[]  = "sr.created_at <= ?";
  $params[] = $dateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// جلب التقارير
$stmt = db()->prepare("
    SELECT sr.*, e.name AS employee_name, e.job_title, b.name AS branch_name
    FROM secret_reports sr
    JOIN employees e ON sr.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE {$whereClause}
    ORDER BY sr.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$reports = $stmt->fetchAll();

// إحصائيات
$statsStmt = db()->query("
    SELECT 
        COUNT(*) AS total,
        COUNT(CASE WHEN status = 'new' THEN 1 END) AS new_count,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) AS progress_count,
        COUNT(CASE WHEN status = 'resolved' THEN 1 END) AS resolved_count
    FROM secret_reports
");
$stats = $statsStmt->fetch();

$csrfToken = generateCsrfToken();

// أنواع التقارير
$reportTypes = [
  'general'    => ['label' => 'عام',      'icon' => '📝', 'color' => '#6B7280'],
  'violation'  => ['label' => 'مخالفة',   'icon' => '⚠️', 'color' => '#F59E0B'],
  'harassment' => ['label' => 'تحرش',     'icon' => '🚨', 'color' => '#EF4444'],
  'theft'      => ['label' => 'سرقة',     'icon' => '🔐', 'color' => '#DC2626'],
  'safety'     => ['label' => 'سلامة',    'icon' => '🛡️', 'color' => '#3B82F6'],
  'other'      => ['label' => 'أخرى',     'icon' => '📋', 'color' => '#8B5CF6'],
];

$statusLabels = [
  'new'         => ['label' => 'جديد',       'color' => '#EF4444', 'bg' => 'rgba(239,68,68,.1)'],
  'reviewed'    => ['label' => 'تمت المراجعة', 'color' => '#F59E0B', 'bg' => 'rgba(245,158,11,.1)'],
  'in_progress' => ['label' => 'قيد المعالجة', 'color' => '#3B82F6', 'bg' => 'rgba(59,130,246,.1)'],
  'resolved'    => ['label' => 'تم الحل',     'color' => '#22C55E', 'bg' => 'rgba(34,197,94,.1)'],
  'dismissed'   => ['label' => 'مرفوض',       'color' => '#6B7280', 'bg' => 'rgba(107,114,128,.1)'],
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

  .report-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 20px;
    margin-bottom: 16px;
    transition: all .2s;
    position: relative;
  }

  .report-card:hover {
    box-shadow: var(--shadow-md)
  }

  .report-card.new {
    border-right: 4px solid #EF4444
  }

  .report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap
  }

  .report-meta {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap
  }

  .report-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: .75rem;
    font-weight: 700
  }

  .report-text {
    font-size: .9rem;
    line-height: 1.8;
    color: var(--text);
    margin-bottom: 12px;
    white-space: pre-wrap;
    word-break: break-word
  }

  .report-images {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px
  }

  .report-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    border: 2px solid var(--border);
    transition: all .2s
  }

  .report-img:hover {
    transform: scale(1.05);
    border-color: var(--primary)
  }

  .report-voice {
    margin-bottom: 12px
  }

  .report-voice audio {
    width: 100%;
    max-width: 400px;
    height: 36px
  }

  .report-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid var(--border)
  }

  .report-emp-name {
    font-weight: 800;
    font-size: .95rem;
    color: var(--primary)
  }

  .report-time {
    font-size: .75rem;
    color: var(--text3)
  }

  .new-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #EF4444;
    display: inline-block;
    animation: blink 1.5s infinite
  }

  @keyframes blink {

    0%,
    100% {
      opacity: 1
    }

    50% {
      opacity: .3
    }
  }

  .img-lightbox {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, .9);
    display: none;
    align-items: center;
    justify-content: center;
    cursor: pointer;
  }

  .img-lightbox.show {
    display: flex
  }

  .img-lightbox img {
    max-width: 95vw;
    max-height: 95vh;
    object-fit: contain;
    border-radius: 8px
  }

  .status-select {
    padding: 6px 10px;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    font-family: inherit;
    font-size: .82rem;
    background: var(--surface)
  }

  .notes-input {
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    font-family: inherit;
    font-size: .82rem;
    background: var(--surface);
    margin-top: 6px
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

<?php if ($msg = $_GET['msg'] ?? ''): ?>
  <div class="alert alert-<?= ($_GET['t'] ?? 'success') === 'error' ? 'danger' : 'success' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- إحصائيات -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon-wrap orange"><?= svgIcon('attendance', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['total'] ?></div>
      <div class="stat-label">إجمالي التقارير</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-wrap red"><?= svgIcon('absent', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['new_count'] ?></div>
      <div class="stat-label">جديدة</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-wrap blue"><?= svgIcon('attendance', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['progress_count'] ?></div>
      <div class="stat-label">قيد المعالجة</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon-wrap green"><?= svgIcon('present', 26) ?></div>
    <div>
      <div class="stat-value"><?= $stats['resolved_count'] ?></div>
      <div class="stat-label">تم الحل</div>
    </div>
  </div>
</div>

<!-- فلاتر -->
<div class="filter-card">
  <div style="font-weight:700;font-size:.95rem;margin-bottom:8px">🔍 تصفية التقارير</div>
  <form method="GET">
    <div class="filter-grid">
      <div class="filter-group">
        <label>الحالة</label>
        <select name="status">
          <option value="">الكل</option>
          <?php foreach ($statusLabels as $k => $sl): ?>
            <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $sl['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>النوع</label>
        <select name="type">
          <option value="">الكل</option>
          <?php foreach ($reportTypes as $k => $rt): ?>
            <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= $rt['icon'] ?> <?= $rt['label'] ?></option>
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
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary">🔍 بحث</button>
      <a href="secret-reports.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
    </div>
  </form>
</div>

<!-- التقارير -->
<div class="section-title">📋 التقارير (<?= count($reports) ?>)</div>

<?php if (empty($reports)): ?>
  <div class="card" style="text-align:center;padding:50px">
    <div style="font-size:3rem;margin-bottom:12px">📭</div>
    <div style="font-size:1.05rem;font-weight:600;color:var(--text3)">لا توجد تقارير بالمعايير المحددة</div>
  </div>
<?php else: ?>
  <?php foreach ($reports as $report):
    $rt = $reportTypes[$report['report_type']] ?? $reportTypes['other'];
    $sl = $statusLabels[$report['status']] ?? $statusLabels['new'];
    $images = $report['image_paths'] ? (json_decode($report['image_paths'], true) ?: []) : [];
  ?>
    <div class="report-card <?= $report['status'] === 'new' ? 'new' : '' ?>">
      <div class="report-header">
        <div>
          <div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">
            <?php if ($report['status'] === 'new'): ?><span class="new-dot" title="جديد"></span><?php endif; ?>
            <span class="report-emp-name"><?= htmlspecialchars($report['employee_name']) ?></span>
            <span style="color:var(--text3);font-size:.78rem">— <?= htmlspecialchars($report['job_title']) ?></span>
          </div>
          <div class="report-meta">
            <span class="report-badge" style="background:<?= $rt['color'] ?>15;color:<?= $rt['color'] ?>;border:1px solid <?= $rt['color'] ?>30">
              <?= $rt['icon'] ?> <?= $rt['label'] ?>
            </span>
            <span class="report-badge" style="background:<?= $sl['bg'] ?>;color:<?= $sl['color'] ?>;border:1px solid <?= $sl['color'] ?>30">
              <?= $sl['label'] ?>
            </span>
            <?php if ($report['branch_name']): ?>
              <span style="font-size:.75rem;color:var(--text3)">🏢 <?= htmlspecialchars($report['branch_name']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="report-time">
          📅 <?= date('Y-m-d', strtotime($report['created_at'])) ?><br>
          🕐 <?= date('h:i A', strtotime($report['created_at'])) ?>
        </div>
      </div>

      <?php if ($report['report_text']): ?>
        <div class="report-text"><?= nl2br(htmlspecialchars($report['report_text'])) ?></div>
      <?php endif; ?>

      <?php if (!empty($images)): ?>
        <div class="report-images">
          <?php foreach ($images as $img): ?>
            <img class="report-img" src="<?= SITE_URL ?>/<?= htmlspecialchars($img) ?>" alt="صورة مرفقة" onclick="showLightbox(this.src)">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($report['voice_path']): ?>
        <div class="report-voice">
          <div style="font-size:.78rem;color:var(--text3);margin-bottom:4px">🎙️ رسالة صوتية
            <?php if ($report['voice_effect'] && $report['voice_effect'] !== 'none'): ?>
              <span style="color:var(--primary);">(مُغيّر: <?= htmlspecialchars($report['voice_effect']) ?>)</span>
            <?php endif; ?>
          </div>
          <audio controls preload="none">
            <source src="<?= SITE_URL ?>/<?= htmlspecialchars($report['voice_path']) ?>" type="audio/webm">
            متصفحك لا يدعم تشغيل الصوت
          </audio>
        </div>
      <?php endif; ?>

      <?php if ($report['admin_notes']): ?>
        <div style="background:rgba(59,130,246,.05);border:1px solid rgba(59,130,246,.15);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:.82rem">
          <strong style="color:var(--primary)">ملاحظات المشرف:</strong> <?= nl2br(htmlspecialchars($report['admin_notes'])) ?>
        </div>
      <?php endif; ?>

      <div class="report-actions">
        <form method="POST" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;flex:1">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
          <input type="hidden" name="action" value="update_status">
          <select name="new_status" class="status-select">
            <?php foreach ($statusLabels as $sk => $sv): ?>
              <option value="<?= $sk ?>" <?= $report['status'] === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="admin_notes" class="notes-input" placeholder="ملاحظات..." value="<?= htmlspecialchars($report['admin_notes'] ?? '') ?>" style="flex:1;min-width:120px;margin-top:0">
          <button type="submit" class="btn btn-primary btn-sm">💾 حفظ</button>
        </form>
        <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا التقرير؟')">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- Lightbox -->
<div class="img-lightbox" id="imgLightbox" onclick="this.classList.remove('show')">
  <img id="lightboxImg" src="" alt="">
</div>

<script>
  function showLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('imgLightbox').classList.add('show');
  }

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