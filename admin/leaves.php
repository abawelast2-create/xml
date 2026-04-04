<?php
// =============================================================
// admin/leaves.php - إدارة الإجازات (v4.0)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'إدارة الإجازات';
$activePage = 'leaves';

// =================== Filters ===================
$statusFilter = $_GET['status'] ?? '';
$branchFilter = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// =================== Handle Actions (POST) ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'طلب غير صالح، يرجى المحاولة مرة أخرى';
        header('Location: leaves.php?' . http_build_query(array_filter($_GET)));
        exit;
    }
    $leaveId = (int)($_POST['leave_id'] ?? 0);
    $action  = $_POST['action'];

    if ($leaveId > 0 && in_array($action, ['approve', 'reject'])) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = db()->prepare("UPDATE leaves SET status = ?, approved_by = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$newStatus, $_SESSION['admin_id'], $leaveId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = $action === 'approve' ? 'تمت الموافقة على الإجازة' : 'تم رفض الإجازة';
        } else {
            $_SESSION['flash_error'] = 'لم يتم العثور على الإجازة أو تمت معالجتها مسبقاً';
        }
    }

    header('Location: leaves.php?' . http_build_query(array_filter($_GET)));
    exit;
}

// =================== Branches ===================
$branches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// =================== Count ===================
$countParams = [];
$countWhere  = '1=1';
if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
    $countWhere .= ' AND l.status = ?';
    $countParams[] = $statusFilter;
}
if ($branchFilter) {
    $countWhere .= ' AND e.branch_id = ?';
    $countParams[] = $branchFilter;
}

$countStmt = db()->prepare("
    SELECT COUNT(*) FROM leaves l
    JOIN employees e ON l.employee_id = e.id
    WHERE {$countWhere}
");
$countStmt->execute($countParams);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

// =================== Fetch Leaves ===================
$leavesStmt = db()->prepare("
    SELECT l.*, e.name AS emp_name, e.job_title, b.name AS branch_name,
           a.username AS approved_by_name
    FROM leaves l
    JOIN employees e ON l.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN admins a ON l.approved_by = a.id
    WHERE {$countWhere}
    ORDER BY
        CASE l.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        l.created_at DESC
    LIMIT ? OFFSET ?
");
foreach ($countParams as $i => $v) { $leavesStmt->bindValue($i + 1, $v); }
$leavesStmt->bindValue(count($countParams) + 1, $perPage, PDO::PARAM_INT);
$leavesStmt->bindValue(count($countParams) + 2, $offset, PDO::PARAM_INT);
$leavesStmt->execute();
$leaves = $leavesStmt->fetchAll();

// =================== Pending Count ===================
$pendingCount = (int)db()->query("SELECT COUNT(*) FROM leaves WHERE status = 'pending'")->fetchColumn();

// =================== Employees for "Add Leave" ===================
$employees = db()->query("SELECT id, name FROM employees WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name")->fetchAll();

$leaveTypes = [
    'annual'  => 'سنوية',
    'sick'    => 'مرضية',
    'unpaid'  => 'بدون راتب',
    'other'   => 'أخرى',
];

$statusLabels = [
    'pending'  => ['label' => 'معلقة', 'class' => 'badge-warning'],
    'approved' => ['label' => 'معتمدة', 'class' => 'badge-success'],
    'rejected' => ['label' => 'مرفوضة', 'class' => 'badge-danger'],
];

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:var(--stat-amber-bg,#FEF3C7);color:var(--stat-amber,#D97706)">⏳</div>
        <div>
            <div class="stat-value"><?= $pendingCount ?></div>
            <div class="stat-label">طلبات معلقة</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon-wrap" style="background:var(--stat-blue-bg,#DBEAFE);color:var(--stat-blue,#2563EB)">📋</div>
        <div>
            <div class="stat-value"><?= $totalRecords ?></div>
            <div class="stat-label">إجمالي الطلبات</div>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="card" style="margin-bottom:16px;padding:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <select name="status" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
            <option value="">كل الحالات</option>
            <option value="pending"  <?= $statusFilter === 'pending'  ? 'selected' : '' ?>>معلقة</option>
            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>معتمدة</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>مرفوضة</option>
        </select>
        <select name="branch_id" style="padding:8px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.88rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
            <option value="">كل الفروع</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $branchFilter == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:8px 16px">تصفية</button>
    </form>
    <button class="btn btn-primary" onclick="openModal('addLeaveModal')" style="padding:8px 20px">
        ➕ إضافة إجازة
    </button>
</div>

<!-- Leaves Table -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:var(--surface2,#F8FAFC)">
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الموظف</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الفرع</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">النوع</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">من</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">إلى</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">المدة</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">الحالة</th>
                    <th style="padding:12px 14px;text-align:right;font-size:.82rem;color:var(--text3);font-weight:600">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaves)): ?>
                    <tr><td colspan="8" style="padding:40px;text-align:center;color:var(--text3)">لا توجد إجازات</td></tr>
                <?php endif; ?>
                <?php foreach ($leaves as $leave):
                    $days = (int)(new DateTime($leave['start_date']))->diff(new DateTime($leave['end_date']))->days + 1;
                    $sInfo = $statusLabels[$leave['status']] ?? $statusLabels['pending'];
                ?>
                <tr style="border-bottom:1px solid var(--border-color,#E2E8F0)">
                    <td style="padding:12px 14px">
                        <strong style="font-size:.9rem"><?= htmlspecialchars($leave['emp_name']) ?></strong>
                        <?php if ($leave['job_title']): ?>
                            <div style="font-size:.78rem;color:var(--text3)"><?= htmlspecialchars($leave['job_title']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 14px;font-size:.88rem"><?= htmlspecialchars($leave['branch_name'] ?? '-') ?></td>
                    <td style="padding:12px 14px;font-size:.88rem"><?= $leaveTypes[$leave['leave_type']] ?? $leave['leave_type'] ?></td>
                    <td style="padding:12px 14px;font-size:.85rem;direction:ltr;text-align:right"><?= $leave['start_date'] ?></td>
                    <td style="padding:12px 14px;font-size:.85rem;direction:ltr;text-align:right"><?= $leave['end_date'] ?></td>
                    <td style="padding:12px 14px;font-size:.88rem;font-weight:600"><?= $days ?> يوم</td>
                    <td style="padding:12px 14px">
                        <span class="badge <?= $sInfo['class'] ?>"><?= $sInfo['label'] ?></span>
                        <?php if ($leave['approved_by_name'] && $leave['status'] !== 'pending'): ?>
                            <div style="font-size:.72rem;color:var(--text3);margin-top:2px">بواسطة: <?= htmlspecialchars($leave['approved_by_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 14px">
                        <?php if ($leave['status'] === 'pending'): ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <form method="POST" style="margin:0" onsubmit="return confirm('تأكيد الموافقة؟')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                    <input type="hidden" name="leave_id" value="<?= $leave['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-sm" style="background:#10B981;color:#fff;border:none;padding:4px 12px;border-radius:6px;font-size:.8rem;cursor:pointer">✓ قبول</button>
                                </form>
                                <form method="POST" style="margin:0" onsubmit="return confirm('تأكيد الرفض؟')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                    <input type="hidden" name="leave_id" value="<?= $leave['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn btn-sm" style="background:#EF4444;color:#fff;border:none;padding:4px 12px;border-radius:6px;font-size:.8rem;cursor:pointer">✗ رفض</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span style="font-size:.78rem;color:var(--text3)">—</span>
                        <?php endif; ?>
                        <?php if ($leave['reason']): ?>
                            <button onclick="showReason(this)" data-reason="<?= htmlspecialchars($leave['reason']) ?>"
                                    style="margin-top:4px;background:none;border:none;color:var(--accent-gold,#D4A841);cursor:pointer;font-size:.78rem;padding:0">📝 السبب</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
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

<!-- Add Leave Modal -->
<div class="modal-overlay" id="addLeaveModal">
    <div class="modal-content" style="max-width:480px">
        <div class="modal-header">
            <h3>➕ إضافة إجازة</h3>
            <button class="modal-close" onclick="closeModal('addLeaveModal')">&times;</button>
        </div>
        <form method="POST" action="<?= SITE_URL ?>/api/leave-add.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <div style="padding:20px;display:grid;gap:14px">
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">الموظف</label>
                    <select name="employee_id" required
                            style="width:100%;padding:10px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.9rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                        <option value="">اختر الموظف</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">نوع الإجازة</label>
                    <select name="leave_type" required
                            style="width:100%;padding:10px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.9rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                        <?php foreach ($leaveTypes as $k => $v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">من تاريخ</label>
                        <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"
                               style="width:100%;padding:10px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.9rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                    </div>
                    <div>
                        <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">إلى تاريخ</label>
                        <input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"
                               style="width:100%;padding:10px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.9rem;background:var(--surface2,#F8FAFC);color:var(--text-primary)">
                    </div>
                </div>
                <div>
                    <label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:4px">السبب (اختياري)</label>
                    <textarea name="reason" rows="3"
                              style="width:100%;padding:10px 12px;border:1px solid var(--border-color,#E2E8F0);border-radius:8px;font-size:.9rem;resize:vertical;background:var(--surface2,#F8FAFC);color:var(--text-primary)"></textarea>
                </div>
            </div>
            <div class="modal-actions" style="padding:16px 20px;border-top:1px solid var(--border-color,#E2E8F0);display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addLeaveModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ الإجازة</button>
            </div>
        </form>
    </div>
</div>

<!-- Reason Modal -->
<div class="modal-overlay" id="reasonModal">
    <div class="modal-content" style="max-width:400px">
        <div class="modal-header">
            <h3>📝 سبب الإجازة</h3>
            <button class="modal-close" onclick="closeModal('reasonModal')">&times;</button>
        </div>
        <div style="padding:20px">
            <p id="reasonText" style="font-size:.92rem;line-height:1.7;white-space:pre-wrap"></p>
        </div>
        <div class="modal-actions" style="padding:12px 20px;border-top:1px solid var(--border-color,#E2E8F0)">
            <button type="button" class="btn btn-secondary" onclick="closeModal('reasonModal')">إغلاق</button>
        </div>
    </div>
</div>

<style>
    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: .78rem;
        font-weight: 600;
    }
    .badge-warning { background: #FEF3C7; color: #92400E; }
    .badge-success { background: #D1FAE5; color: #065F46; }
    .badge-danger  { background: #FEE2E2; color: #991B1B; }

    html.dark .badge-warning { background: rgba(245,158,11,.15); color: #FBBF24; }
    html.dark .badge-success { background: rgba(16,185,129,.15); color: #34D399; }
    html.dark .badge-danger  { background: rgba(239,68,68,.15); color: #F87171; }
</style>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
function showReason(btn) {
    document.getElementById('reasonText').textContent = btn.dataset.reason;
    openModal('reasonModal');
}
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('show'); });
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

</div></div>
</body></html>
