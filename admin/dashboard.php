<?php
// =============================================================
// admin/dashboard.php - لوحة التحكم الرئيسية
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'لوحة التحكم';
$activePage = 'dashboard';

// إحصائيات اليوم
$stats = getTodayStats();
$today = date('Y-m-d');

// عدد الفروع
$branchCount = (int)db()->query("SELECT COUNT(*) FROM branches WHERE is_active = 1")->fetchColumn();

// المتأخرون اليوم
$lateStmt = db()->prepare("SELECT COUNT(DISTINCT employee_id) FROM attendances WHERE attendance_date = ? AND type = 'in' AND late_minutes > 0");
$lateStmt->execute([$today]);
$lateCount = (int)$lateStmt->fetchColumn();

// الإجازات المعلقة
$pendingLeaves = 0;
try { $pendingLeaves = (int)db()->query("SELECT COUNT(*) FROM leaves WHERE status = 'pending'")->fetchColumn(); } catch(PDOException $e) {}

// الإشعارات غير المقروءة
$unreadNotifs = 0;
try { $unreadNotifs = (int)db()->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn(); } catch(PDOException $e) {}

// الفروع مع إحداثيات للخريطة
$branchesMap = db()->query("
    SELECT b.id, b.name, b.latitude, b.longitude, b.geofence_radius,
           (SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id AND e.is_active = 1 AND e.deleted_at IS NULL) AS emp_count,
           (SELECT COUNT(DISTINCT a.employee_id) FROM attendances a JOIN employees e2 ON a.employee_id = e2.id WHERE e2.branch_id = b.id AND a.attendance_date = CURDATE() AND a.type = 'in') AS present_today
    FROM branches b WHERE b.is_active = 1
")->fetchAll();

// آخر تسجيلات اليوم مع الإحداثيات (للرادارات الحية)
$liveCheckins = db()->prepare("
    SELECT a.latitude, a.longitude, a.type, a.timestamp,
           e.name AS employee_name, e.branch_id
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date = ? AND a.type = 'in'
      AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
      AND e.is_active = 1 AND e.deleted_at IS NULL
    ORDER BY a.timestamp DESC
")->execute([$today]) ? [] : [];
$liveStmt = db()->prepare("
    SELECT a.latitude, a.longitude, a.type, a.timestamp,
           e.name AS employee_name, e.branch_id
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date = ? AND a.type = 'in'
      AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
      AND e.is_active = 1 AND e.deleted_at IS NULL
    ORDER BY a.timestamp DESC
");
$liveStmt->execute([$today]);
$liveCheckins = $liveStmt->fetchAll();

// آخر 15 تسجيل
$recentStmt = db()->prepare("
    SELECT a.type, a.timestamp, a.latitude, a.longitude,
           e.name AS employee_name, e.job_title,
           b.name AS branch_name
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.is_active = 1 AND e.deleted_at IS NULL
    ORDER BY a.timestamp DESC
    LIMIT 15
");
$recentStmt->execute();
$recentRecords = $recentStmt->fetchAll();

// غائبون اليوم
$absentStmt = db()->prepare("
    SELECT e.name, e.job_title, b.name AS branch_name FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.is_active = 1 AND e.deleted_at IS NULL
      AND e.id NOT IN (
          SELECT DISTINCT employee_id FROM attendances
          WHERE attendance_date = ?
      )
    ORDER BY e.name
    LIMIT 10
");
$absentStmt->execute([$today]);
$absentList = $absentStmt->fetchAll();

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- Stats -->
<div class="stats-grid" id="statsGrid">
    <div class="stat-card">
        <div class="stat-icon-wrap orange"><?= svgIcon('branch', 26) ?></div>
        <div>
            <div class="stat-value" data-live="branches"><?= $branchCount ?></div>
            <div class="stat-label">الفروع النشطة</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap blue"><?= svgIcon('employees', 26) ?></div>
        <div>
            <div class="stat-value" data-live="total_employees"><?= $stats['total_employees'] ?></div>
            <div class="stat-label">إجمالي الموظفين</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap green"><?= svgIcon('checkin', 26) ?></div>
        <div>
            <div class="stat-value" data-live="checked_in"><?= $stats['checked_in'] ?></div>
            <div class="stat-label">حضروا اليوم</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap purple"><?= svgIcon('checkout', 26) ?></div>
        <div>
            <div class="stat-value" data-live="checked_out"><?= $stats['checked_out'] ?></div>
            <div class="stat-label">انصرفوا اليوم</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap red"><?= svgIcon('absent', 26) ?></div>
        <div>
            <div class="stat-value" data-live="absent"><?= $stats['total_employees'] - $stats['checked_in'] ?></div>
            <div class="stat-label">غائبون اليوم</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#FEF3C7;color:#D97706">⏰</div>
        <div>
            <div class="stat-value" data-live="late"><?= $lateCount ?></div>
            <div class="stat-label">متأخرون اليوم</div>
        </div>
    </div>
    <?php if ($pendingLeaves > 0): ?>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#EDE9FE;color:#7C3AED">📋</div>
        <div>
            <div class="stat-value"><?= $pendingLeaves ?></div>
            <div class="stat-label"><a href="leaves.php?status=pending" style="color:inherit;text-decoration:none">إجازات معلقة</a></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- خريطة الفروع — الرادارات الحية -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> رادارات الفروع — البث المباشر</span>
        <div class="live-indicator" id="mapLiveIndicator">
            <span class="live-dot"></span>
            <span>مباشر</span>
        </div>
    </div>
    <div id="dashboardMap" style="height:420px;border-radius:8px;z-index:1;min-height:250px"></div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
/* رادار متحرك */
@keyframes radarSweep {
    0%   { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@keyframes radarPulse {
    0%   { transform: scale(1); opacity: 0.6; }
    50%  { transform: scale(1.15); opacity: 0.3; }
    100% { transform: scale(1); opacity: 0.6; }
}
@keyframes empDotPulse {
    0%, 100% { transform: translate(-50%,-50%) scale(1); }
    50%      { transform: translate(-50%,-50%) scale(1.4); }
}
.radar-marker {
    position: relative;
    width: 80px; height: 80px;
}
.radar-bg {
    position: absolute; inset: 0;
    border-radius: 50%;
    border: 2px solid rgba(16,185,129,.5);
    background: radial-gradient(circle, rgba(16,185,129,.15) 0%, rgba(16,185,129,.03) 70%, transparent 100%);
}
.radar-bg.warn { border-color: rgba(245,158,11,.5); background: radial-gradient(circle, rgba(245,158,11,.15) 0%, rgba(245,158,11,.03) 70%, transparent 100%); }
.radar-bg.danger { border-color: rgba(239,68,68,.5); background: radial-gradient(circle, rgba(239,68,68,.15) 0%, rgba(239,68,68,.03) 70%, transparent 100%); }
.radar-ring {
    position: absolute; inset: 8px;
    border-radius: 50%;
    border: 1px dashed rgba(255,255,255,.2);
}
.radar-ring2 {
    position: absolute; inset: 18px;
    border-radius: 50%;
    border: 1px dashed rgba(255,255,255,.12);
}
.radar-sweep {
    position: absolute; top: 50%; left: 50%;
    width: 50%; height: 2px;
    transform-origin: 0% 50%;
    animation: radarSweep 3s linear infinite;
}
.radar-sweep::after {
    content: '';
    position: absolute; top: -15px; left: 0;
    width: 100%; height: 30px;
    background: linear-gradient(90deg, rgba(16,185,129,.5), transparent);
    border-radius: 0 50% 50% 0;
}
.radar-sweep.warn::after { background: linear-gradient(90deg, rgba(245,158,11,.5), transparent); }
.radar-sweep.danger::after { background: linear-gradient(90deg, rgba(239,68,68,.5), transparent); }
.radar-cross-h, .radar-cross-v {
    position: absolute;
    background: rgba(255,255,255,.08);
}
.radar-cross-h { top: 50%; left: 10%; right: 10%; height: 1px; transform: translateY(-50%); }
.radar-cross-v { left: 50%; top: 10%; bottom: 10%; width: 1px; transform: translateX(-50%); }
.radar-center {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%,-50%);
    font-size: 11px; font-weight: 800;
    color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.7);
    z-index: 5; white-space: nowrap;
    text-align: center; line-height: 1.2;
}
.radar-emp-dot {
    position: absolute;
    width: 6px; height: 6px;
    background: #60A5FA;
    border-radius: 50%;
    border: 1px solid #fff;
    transform: translate(-50%,-50%);
    animation: empDotPulse 2s ease-in-out infinite;
    z-index: 3;
}
.radar-outer-pulse {
    position: absolute; inset: -4px;
    border-radius: 50%;
    border: 1.5px solid rgba(16,185,129,.3);
    animation: radarPulse 2.5s ease-in-out infinite;
}
.radar-outer-pulse.warn { border-color: rgba(245,158,11,.3); }
.radar-outer-pulse.danger { border-color: rgba(239,68,68,.3); }
.emp-name-label {
    background: rgba(15,23,42,.85) !important;
    color: #fff !important;
    border: 1px solid rgba(96,165,250,.5) !important;
    border-radius: 6px !important;
    padding: 2px 8px !important;
    font-size: 10px !important;
    font-weight: 600 !important;
    font-family: Tajawal, sans-serif !important;
    white-space: nowrap !important;
    box-shadow: 0 2px 8px rgba(0,0,0,.3) !important;
}
.emp-name-label::before {
    border-top-color: rgba(15,23,42,.85) !important;
}
@media (max-width: 768px) {
    .radar-marker { width:60px !important; height:60px !important; }
    .radar-center span:first-child { font-size:12px !important; }
    .radar-center span:last-child { font-size:8px !important; }
    .emp-name-label { font-size:8px !important; padding:1px 5px !important; }
}
</style>
<script>
(function(){
    const map = L.map('dashboardMap', {zoomControl: true, scrollWheelZoom: true, dragging: true, tap: true}).setView([24.7136, 46.6753], 12);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Esri', maxZoom: 19
    }).addTo(map);

    const branches = <?= json_encode($branchesMap) ?>;
    const liveCheckins = <?= json_encode($liveCheckins) ?>;

    branches.forEach(b => {
        if (!b.latitude || !b.longitude) return;
        const pct = b.emp_count > 0 ? Math.round((b.present_today / b.emp_count) * 100) : 0;
        const cls = pct >= 80 ? '' : (pct >= 50 ? 'warn' : 'danger');
        const color = pct >= 80 ? '#10B981' : (pct >= 50 ? '#F59E0B' : '#EF4444');

        // نقاط الموظفين الحية داخل هذا الفرع (يجب تعريفها قبل استخدامها)
        const branchCheckins = liveCheckins.filter(c => c.branch_id == b.id);

        // نطاق الجيوفينس الفعلي
        const geoRadius = b.geofence_radius || 500;
        L.circle([b.latitude, b.longitude], {
            radius: geoRadius,
            color: color,
            weight: 1,
            opacity: 0.4,
            fillColor: color,
            fillOpacity: 0.06,
            dashArray: '6 4'
        }).addTo(map);

        // الرادار المتحرك
        const size = 80;
        const radarIcon = L.divIcon({
            className: '',
            iconSize: [size, size],
            iconAnchor: [size/2, size/2],
            html: `<div class="radar-marker">
                <div class="radar-outer-pulse ${cls}"></div>
                <div class="radar-bg ${cls}"></div>
                <div class="radar-ring"></div>
                <div class="radar-ring2"></div>
                <div class="radar-cross-h"></div>
                <div class="radar-cross-v"></div>
                <div class="radar-sweep ${cls}"></div>
                <div class="radar-center">
                    <span style="font-size:14px;display:block">${b.present_today}/${b.emp_count}</span>
                    <span style="font-size:9px;opacity:.8">${pct}%</span>
                </div>
            </div>`
        });
        const marker = L.marker([b.latitude, b.longitude], {icon: radarIcon}).addTo(map);
        marker.bindPopup(`<div style="text-align:center;font-family:Tajawal;min-width:180px;padding:6px">
            <strong style="font-size:1.05rem">${b.name}</strong><br>
            <div style="margin:8px 0;padding:8px;background:rgba(0,0,0,.05);border-radius:10px">
                <span style="color:${color};font-size:1.4rem;font-weight:800">${pct}%</span><br>
                <div style="background:rgba(0,0,0,.1);border-radius:6px;height:8px;margin:6px 0;overflow:hidden">
                    <div style="background:${color};height:100%;width:${pct}%;border-radius:6px;transition:width .5s"></div>
                </div>
                <small>الحضور: <strong>${b.present_today}</strong> من <strong>${b.emp_count}</strong></small>
            </div>
            <small style="color:#888">📏 نطاق: ${geoRadius}م</small><br>
            <small style="color:#888">👥 موظفون حاضرون: ${branchCheckins.length}</small>
        </div>`);

        // نقاط الموظفين الحية داخل هذا الفرع
        branchCheckins.forEach((c, idx) => {
            const empMarker = L.circleMarker([c.latitude, c.longitude], {
                radius: 5, fillColor: '#60A5FA', color: '#fff',
                weight: 1.5, fillOpacity: 0.9
            }).addTo(map);
            // عرض اسم الموظف بشكل دائم
            empMarker.bindTooltip(c.employee_name, {
                permanent: true,
                direction: 'top',
                offset: [0, -8],
                className: 'emp-name-label'
            });
        });
    });

    if (branches.length > 0) {
        const bounds = branches.filter(b => b.latitude && b.longitude).map(b => [b.latitude, b.longitude]);
        if (bounds.length === 1) {
            map.setView(bounds[0], 15);
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, {padding: [50,50], maxZoom: 16});
        }
    }

    window._dashMap = map;
})();
</script>

<!-- Quick Links -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:20px">
    <a href="report-monthly.php" class="card" style="padding:14px;text-align:center;text-decoration:none;color:var(--text-primary);transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
        <div style="font-size:1.5rem;margin-bottom:4px">📊</div>
        <div style="font-size:.82rem;font-weight:600">التقرير الشهري</div>
    </a>
    <a href="report-absence.php" class="card" style="padding:14px;text-align:center;text-decoration:none;color:var(--text-primary);transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
        <div style="font-size:1.5rem;margin-bottom:4px">❌</div>
        <div style="font-size:.82rem;font-weight:600">تقرير الغياب</div>
    </a>
    <a href="report-branches.php" class="card" style="padding:14px;text-align:center;text-decoration:none;color:var(--text-primary);transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
        <div style="font-size:1.5rem;margin-bottom:4px">🏢</div>
        <div style="font-size:.82rem;font-weight:600">مقارنة الفروع</div>
    </a>
    <a href="notifications.php" class="card" style="padding:14px;text-align:center;text-decoration:none;color:var(--text-primary);transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
        <div style="font-size:1.5rem;margin-bottom:4px">🔔</div>
        <div style="font-size:.82rem;font-weight:600">الإشعارات<?= $unreadNotifs ? " ({$unreadNotifs})" : '' ?></div>
    </a>
    <a href="backups.php" class="card" style="padding:14px;text-align:center;text-decoration:none;color:var(--text-primary);transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
        <div style="font-size:1.5rem;margin-bottom:4px">💾</div>
        <div style="font-size:.82rem;font-weight:600">النسخ الاحتياطي</div>
    </a>
    <a href="employee-transfer.php" class="card" style="padding:14px;text-align:center;text-decoration:none;color:var(--text-primary);transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
        <div style="font-size:1.5rem;margin-bottom:4px">🔄</div>
        <div style="font-size:.82rem;font-weight:600">نقل الموظفين</div>
    </a>
</div>

<div class="dashboard-grid">

    <!-- آخر التسجيلات -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><span class="card-title-bar"></span> آخر التسجيلات</span>
            <div style="display:flex;align-items:center;gap:10px">
                <div class="live-indicator" id="liveIndicator">
                    <span class="live-dot"></span>
                    <span id="liveText">مباشر</span>
                </div>
                <a href="attendance.php" class="btn btn-secondary btn-sm">عرض الكل</a>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>الموظف</th><th>الفرع</th><th>النوع</th><th>الوقت</th></tr></thead>
            <tbody id="recentTableBody">
            <?php if (empty($recentRecords)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--text3);padding:20px">لا توجد تسجيلات اليوم</td></tr>
            <?php else: ?>
                <?php foreach ($recentRecords as $rec): ?>
                <tr data-ts="<?= htmlspecialchars($rec['timestamp']) ?>">
                    <td><strong><?= htmlspecialchars($rec['employee_name']) ?></strong><br><small style="color:var(--text3)"><?= htmlspecialchars($rec['job_title']) ?></small></td>
                    <td style="font-size:.78rem;color:var(--text2)"><?= htmlspecialchars($rec['branch_name'] ?? '-') ?></td>
                    <td><span class="badge <?= $rec['type'] === 'in' ? 'badge-green' : 'badge-red' ?>"><?= $rec['type'] === 'in' ? 'دخول' : 'انصراف' ?></span></td>
                    <td style="color:var(--text3);font-size:.8rem"><?= date('h:i A', strtotime($rec['timestamp'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- الغائبون اليوم -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><span class="card-title-bar"></span> غائبون اليوم</span>
            <span class="badge badge-red" id="absentBadge"><?= count($absentList) ?></span>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>الاسم</th><th>الوظيفة</th><th>الفرع</th></tr></thead>
            <tbody id="absentTableBody">
            <?php if (empty($absentList)): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--green);padding:20px">جميع الموظفين حضروا!</td></tr>
            <?php else: ?>
                <?php foreach ($absentList as $emp): ?>
                <tr>
                    <td><?= htmlspecialchars($emp['name']) ?></td>
                    <td style="color:var(--text3)"><?= htmlspecialchars($emp['job_title']) ?></td>
                    <td style="font-size:.78rem;color:var(--text2)"><?= htmlspecialchars($emp['branch_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($absentList) >= 10): ?>
                <tr><td colspan="3" style="text-align:center;font-size:.8rem;color:var(--text3);padding:12px">
                    يُعرض أول 10 فقط. <a href="attendance.php" style="color:var(--primary)">عرض الكل</a>
                </td></tr>
                <?php endif; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
    // =================== التحديث بالوقت الفعلي (جزئي) ===================
    const REFRESH_INTERVAL = 15000;
    let refreshTimer = null;
    let failCount = 0;
    let lastAbsentCount = <?= count($absentList) ?>;

    // آخر timestamp لأحدث سجل
    let lastRecordTs = <?= json_encode(!empty($recentRecords) ? $recentRecords[0]['timestamp'] : null) ?>;

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    // تحديث قيمة إحصائية واحدة فقط عند تغيّرها
    function updateStatValue(key, newValue) {
        const el = document.querySelector(`[data-live="${key}"]`);
        if (!el) return;
        const nv = String(newValue);
        if (el.textContent.trim() !== nv) {
            el.textContent = nv;
            el.classList.add('updated');
            setTimeout(() => el.classList.remove('updated'), 600);
        }
    }

    // إضافة صفوف جديدة أعلى جدول التسجيلات (بدون إعادة بناء الجدول)
    function prependNewRecords(records) {
        const tbody = document.getElementById('recentTableBody');
        if (!tbody || records.length === 0) return;

        // تصفية السجلات الأحدث فقط
        const newRecords = lastRecordTs
            ? records.filter(r => r.timestamp > lastRecordTs)
            : [];

        if (newRecords.length === 0) return;

        // إزالة رسالة "لا توجد تسجيلات" إن وُجدت
        const emptyRow = tbody.querySelector('td[colspan="4"]');
        if (emptyRow) emptyRow.closest('tr').remove();

        // إنشاء صفوف جديدة وإدراجها في الأعلى
        newRecords.reverse().forEach(rec => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-ts', rec.timestamp);
            tr.classList.add('new-row');
            const badgeClass = rec.type === 'in' ? 'badge-green' : 'badge-red';
            const badgeText  = rec.type === 'in' ? 'دخول' : 'انصراف';
            tr.innerHTML = `
                <td><strong>${escapeHtml(rec.employee_name)}</strong><br><small style="color:var(--text3)">${escapeHtml(rec.job_title)}</small></td>
                <td style="font-size:.78rem;color:var(--text2)">${escapeHtml(rec.branch_name)}</td>
                <td><span class="badge ${badgeClass}">${badgeText}</span></td>
                <td style="color:var(--text3);font-size:.8rem">${escapeHtml(rec.time)}</td>`;
            tbody.insertBefore(tr, tbody.firstChild);
        });

        // الاحتفاظ بأحدث 15 فقط
        while (tbody.children.length > 15) {
            tbody.removeChild(tbody.lastChild);
        }

        lastRecordTs = records[0].timestamp;
    }

    // تحديث جدول الغائبين فقط عند تغيّر العدد
    function updateAbsentTable(absents, count) {
        const badge = document.getElementById('absentBadge');
        if (badge) badge.textContent = count;

        // لا حاجة لإعادة بناء الجدول إن لم يتغيّر العدد
        if (count === lastAbsentCount) return;
        lastAbsentCount = count;

        const tbody = document.getElementById('absentTableBody');
        if (!tbody) return;

        if (absents.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--green);padding:20px">جميع الموظفين حضروا!</td></tr>';
            return;
        }
        let html = '';
        absents.forEach(emp => {
            html += `<tr>
                <td>${escapeHtml(emp.name)}</td>
                <td style="color:var(--text3)">${escapeHtml(emp.job_title)}</td>
                <td style="font-size:.78rem;color:var(--text2)">${escapeHtml(emp.branch_name)}</td>
            </tr>`;
        });
        if (count >= 10) {
            html += `<tr><td colspan="3" style="text-align:center;font-size:.8rem;color:var(--text3);padding:12px">
                يُعرض أول 10 فقط. <a href="attendance.php" style="color:var(--primary)">عرض الكل</a>
            </td></tr>`;
        }
        tbody.innerHTML = html;
    }

    function setLiveStatus(status) {
        const indicator = document.getElementById('liveIndicator');
        const text = document.getElementById('liveText');
        if (!indicator) return;
        indicator.classList.remove('paused', 'error');
        if (status === 'live') {
            text.textContent = 'مباشر';
        } else if (status === 'paused') {
            indicator.classList.add('paused');
            text.textContent = 'متوقف';
        } else if (status === 'error') {
            indicator.classList.add('error');
            text.textContent = 'خطأ';
        }
    }

    async function fetchDashboardData() {
        try {
            const resp = await fetch('../api/realtime-dashboard.php', {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || 'خطأ');

            // تحديث جزئي: الإحصائيات فقط
            updateStatValue('branches', data.stats.branches);
            updateStatValue('total_employees', data.stats.total_employees);
            updateStatValue('checked_in', data.stats.checked_in);
            updateStatValue('checked_out', data.stats.checked_out);
            updateStatValue('absent', data.stats.absent);

            // إضافة السجلات الجديدة فقط (لا إعادة بناء)
            prependNewRecords(data.recent_records);

            // تحديث الغائبين عند تغيّر العدد فقط
            updateAbsentTable(data.absent_list, data.absent_count);

            setLiveStatus('live');
            failCount = 0;
        } catch (e) {
            failCount++;
            console.warn('Real-time fetch failed:', e.message);
            setLiveStatus(failCount >= 3 ? 'error' : 'paused');
        }
    }

    // بدء التحديث الدوري
    refreshTimer = setInterval(fetchDashboardData, REFRESH_INTERVAL);

    // إيقاف عند إخفاء الصفحة
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(refreshTimer);
            setLiveStatus('paused');
        } else {
            fetchDashboardData();
            refreshTimer = setInterval(fetchDashboardData, REFRESH_INTERVAL);
        }
    });
</script>

</div></div><!-- end .content .main-content -->
</body></html>
