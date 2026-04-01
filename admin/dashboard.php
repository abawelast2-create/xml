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
    // =================== الساعة ===================
    function tick(){
        const el = document.getElementById('topbarClock');
        if(el) el.textContent = new Date().toLocaleString('ar-SA');
    }
    tick(); setInterval(tick,1000);

    function toggleSidebar(){
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    }
    document.getElementById('sidebarOverlay')?.addEventListener('click', toggleSidebar);

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
