<?php
// =============================================================
// admin/notifications.php - إدارة الإشعارات
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'الإشعارات';
$activePage = 'notifications';

// =================== إجراءات POST ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'طلب غير صالح';
        header('Location: notifications.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // تعليم كقراءة
    if ($action === 'mark_read') {
        $id = (int)($_POST['notif_id'] ?? 0);
        if ($id) {
            db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$id]);
        }
    }

    // تعليم الكل كقراءة
    if ($action === 'mark_all_read') {
        db()->prepare("UPDATE notifications SET is_read = 1 WHERE admin_id IS NULL OR admin_id = ?")->execute([$_SESSION['admin_id']]);
        $_SESSION['flash_success'] = 'تم تعليم جميع الإشعارات كمقروءة';
    }

    // حذف القديمة (أكثر من 30 يوم)
    if ($action === 'clear_old') {
        $stmt = db()->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $_SESSION['flash_success'] = 'تم حذف الإشعارات القديمة (' . $stmt->rowCount() . ')';
    }

    // فحص التأخير وإنشاء إشعارات
    if ($action === 'check_late') {
        $today = date('Y-m-d');
        $lateStmt = db()->prepare("
            SELECT a.employee_id, e.name, a.late_minutes, b.name AS branch_name
            FROM attendances a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE a.attendance_date = ? AND a.type = 'in' AND a.late_minutes > 0
              AND e.is_active = 1 AND e.deleted_at IS NULL
            ORDER BY a.late_minutes DESC
        ");
        $lateStmt->execute([$today]);
        $lateList = $lateStmt->fetchAll();

        $count = 0;
        foreach ($lateList as $l) {
            // تحقق من عدم وجود إشعار مكرر لنفس اليوم
            $check = db()->prepare("SELECT id FROM notifications WHERE category = 'late' AND link = ? AND DATE(created_at) = ?");
            $check->execute(['employee-profile.php?id=' . $l['employee_id'], $today]);
            if (!$check->fetch()) {
                $stmt = db()->prepare("INSERT INTO notifications (title, message, type, category, link) VALUES (?, ?, 'warning', 'late', ?)");
                $stmt->execute([
                    'تأخير: ' . $l['name'],
                    'تأخر ' . $l['name'] . ' بمقدار ' . $l['late_minutes'] . ' دقيقة في فرع ' . ($l['branch_name'] ?? 'غير محدد'),
                    'employee-profile.php?id=' . $l['employee_id']
                ]);
                $count++;
            }
        }
        $_SESSION['flash_success'] = "تم فحص التأخير — {$count} إشعار جديد";
    }

    header('Location: notifications.php');
    exit;
}

// =================== فلاتر ===================
$categoryFilter = $_GET['category'] ?? '';
$readFilter = $_GET['read'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($categoryFilter) {
    $where .= ' AND n.category = ?';
    $params[] = $categoryFilter;
}
if ($readFilter !== '') {
    $where .= ' AND n.is_read = ?';
    $params[] = (int)$readFilter;
}

// =================== إحصائيات ===================
$unreadCount = (int)db()->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
$todayCount  = (int)db()->query("SELECT COUNT(*) FROM notifications WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// =================== الإشعارات ===================
$countStmt = db()->prepare("SELECT COUNT(*) FROM notifications n WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

$notifStmt = db()->prepare("
    SELECT n.* FROM notifications n
    WHERE {$where}
    ORDER BY n.is_read ASC, n.created_at DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $v) { $notifStmt->bindValue($i + 1, $v); }
$notifStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$notifStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$notifStmt->execute();
$notifications = $notifStmt->fetchAll();

$typeIcons = [
    'info'    => ['icon' => 'ℹ️', 'bg' => '#DBEAFE', 'color' => '#2563EB'],
    'warning' => ['icon' => '⚠️', 'bg' => '#FEF3C7', 'color' => '#D97706'],
    'success' => ['icon' => '✅', 'bg' => '#D1FAE5', 'color' => '#065F46'],
    'danger'  => ['icon' => '🚨', 'bg' => '#FEE2E2', 'color' => '#991B1B'],
];

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- إحصائيات -->
<div class="stats-grid" style="margin-bottom:16px">
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#FEF3C7;color:#D97706">🔔</div>
        <div><div class="stat-value"><?= $unreadCount ?></div><div class="stat-label">غير مقروءة</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#DBEAFE;color:#2563EB">📋</div>
        <div><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">إشعارات اليوم</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:#F1F5F9;color:var(--text-primary)">📊</div>
        <div><div class="stat-value"><?= $totalRecords ?></div><div class="stat-label">الإجمالي</div></div>
    </div>
</div>

<!-- الأدوات -->
<div class="card" style="margin-bottom:16px;padding:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <select name="category" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                <option value="">كل الأنواع</option>
                <option value="late" <?= $categoryFilter === 'late' ? 'selected' : '' ?>>تأخير</option>
                <option value="absence" <?= $categoryFilter === 'absence' ? 'selected' : '' ?>>غياب</option>
                <option value="leave" <?= $categoryFilter === 'leave' ? 'selected' : '' ?>>إجازات</option>
                <option value="system" <?= $categoryFilter === 'system' ? 'selected' : '' ?>>نظام</option>
                <option value="general" <?= $categoryFilter === 'general' ? 'selected' : '' ?>>عام</option>
            </select>
            <select name="read" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                <option value="">الكل</option>
                <option value="0" <?= $readFilter === '0' ? 'selected' : '' ?>>غير مقروءة</option>
                <option value="1" <?= $readFilter === '1' ? 'selected' : '' ?>>مقروءة</option>
            </select>
            <button type="submit" class="btn btn-primary" style="padding:8px 16px">تصفية</button>
        </form>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="action" value="check_late">
            <button type="submit" class="btn btn-primary" style="padding:8px 16px;background:#D97706">⏰ فحص التأخير</button>
        </form>
        <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-secondary" style="padding:8px 16px">✓ تعليم الكل كمقروءة</button>
        </form>
        <form method="POST" style="margin:0" onsubmit="return confirm('حذف الإشعارات الأقدم من 30 يوم؟')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="action" value="clear_old">
            <button type="submit" class="btn btn-secondary" style="padding:8px 16px;color:#EF4444">🗑️ حذف القديمة</button>
        </form>
    </div>
</div>

<!-- الإشعارات -->
<div class="card" style="padding:0;overflow:hidden">
    <?php if (empty($notifications)): ?>
        <div style="padding:40px;text-align:center;color:var(--text3)">لا توجد إشعارات</div>
    <?php endif; ?>
    <?php foreach ($notifications as $n):
        $ti = $typeIcons[$n['type']] ?? $typeIcons['info'];
    ?>
    <div style="display:flex;gap:14px;padding:14px 16px;border-bottom:1px solid var(--border-color,#E2E8F0);align-items:flex-start;<?= !$n['is_read'] ? 'background:var(--surface2,#F8FAFC)' : '' ?>">
        <div style="width:40px;height:40px;border-radius:10px;background:<?= $ti['bg'] ?>;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0"><?= $ti['icon'] ?></div>
        <div style="flex:1;min-width:0">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                <strong style="font-size:.9rem;<?= !$n['is_read'] ? 'color:var(--text-primary)' : 'color:var(--text2)' ?>"><?= htmlspecialchars($n['title']) ?></strong>
                <span style="font-size:.72rem;color:var(--text3);white-space:nowrap;direction:ltr"><?= date('m/d h:i A', strtotime($n['created_at'])) ?></span>
            </div>
            <p style="font-size:.84rem;color:var(--text3);margin:4px 0 0;line-height:1.5"><?= htmlspecialchars($n['message']) ?></p>
            <div style="display:flex;gap:8px;margin-top:6px">
                <?php if ($n['link']): ?>
                    <a href="<?= htmlspecialchars($n['link']) ?>" style="font-size:.78rem;color:var(--primary);text-decoration:none">عرض التفاصيل →</a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                    <form method="POST" style="margin:0;display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:var(--text3);font-size:.75rem;cursor:pointer;padding:0">✓ قراءة</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$n['is_read']): ?>
            <div style="width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:6px"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">
    <?php for ($p = 1; $p <= $totalPages; $p++):
        $qp = array_merge($_GET, ['page' => $p]);
    ?>
        <a href="?<?= http_build_query($qp) ?>"
           style="padding:6px 14px;border-radius:8px;font-size:.85rem;text-decoration:none;
                  <?= $p == $page ? 'background:var(--accent-gold,#D4A841);color:#0F172A;font-weight:700' : 'background:var(--surface2,#F8FAFC);color:var(--text-primary)' ?>">
            <?= $p ?>
        </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div>
</body></html>
