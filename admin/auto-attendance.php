<?php
// =============================================================
// admin/auto-attendance.php - إدارة استثناءات الحضور التلقائي
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'الحضور التلقائي';
$activePage = 'auto-attendance';

$message = '';
$msgType = '';

// =================== حفظ/تعديل/حذف ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'طلب غير صالح'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_rule') {
            $empId    = (int)($_POST['employee_id'] ?? 0);
            $timeFrom = $_POST['time_from'] ?? '08:00';
            $timeTo   = $_POST['time_to'] ?? '08:05';

            if ($empId <= 0) {
                $message = 'يرجى اختيار موظف'; $msgType = 'error';
            } else {
                // التحقق من عدم وجود قاعدة مسبقة
                $exists = db()->prepare("SELECT id FROM auto_attendance_rules WHERE employee_id = ?");
                $exists->execute([$empId]);
                if ($exists->fetch()) {
                    // تحديث القاعدة الموجودة
                    db()->prepare("UPDATE auto_attendance_rules SET auto_time_from = ?, auto_time_to = ?, is_active = 1 WHERE employee_id = ?")
                        ->execute([$timeFrom, $timeTo, $empId]);
                    $message = 'تم تحديث القاعدة'; $msgType = 'success';
                } else {
                    db()->prepare("INSERT INTO auto_attendance_rules (employee_id, auto_time_from, auto_time_to) VALUES (?, ?, ?)")
                        ->execute([$empId, $timeFrom, $timeTo]);
                    $message = 'تم إضافة القاعدة بنجاح'; $msgType = 'success';
                }
                auditLog('auto_attendance', "إضافة/تحديث حضور تلقائي: emp={$empId}, from={$timeFrom}, to={$timeTo}", $empId);
            }
        }

        if ($action === 'toggle_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0);
            db()->prepare("UPDATE auto_attendance_rules SET is_active = ? WHERE id = ?")->execute([$active, $ruleId]);
            auditLog('auto_attendance', "تغيير حالة قاعدة حضور تلقائي ID={$ruleId} إلى " . ($active ? 'مفعّل' : 'معطّل'));
            $message = $active ? 'تم تفعيل القاعدة' : 'تم تعطيل القاعدة'; $msgType = 'success';
        }

        if ($action === 'delete_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            db()->prepare("DELETE FROM auto_attendance_rules WHERE id = ?")->execute([$ruleId]);
            auditLog('auto_attendance', "حذف قاعدة حضور تلقائي ID={$ruleId}");
            $message = 'تم حذف القاعدة'; $msgType = 'success';
        }
    }
}

$csrf = generateCsrfToken();

// جلب القواعد الحالية
$rules = db()->query("
    SELECT r.*, e.name AS employee_name, e.job_title, b.name AS branch_name
    FROM auto_attendance_rules r
    INNER JOIN employees e ON r.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    ORDER BY r.is_active DESC, e.name ASC
")->fetchAll();

// قائمة الموظفين
$employees = db()->query("SELECT id, name, job_title FROM employees WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name")->fetchAll();

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
.rule-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; transition:all .2s }
.rule-card.inactive { opacity:.5; background:var(--surface2,#f5f5f5) }
.rule-info { flex:1; min-width:200px }
.rule-name { font-weight:700; font-size:.95rem; color:var(--text-primary) }
.rule-detail { font-size:.82rem; color:var(--text3); margin-top:4px }
.rule-time { display:inline-flex; align-items:center; gap:6px; background:var(--primary-xl,#FFF7ED); color:var(--primary); padding:4px 12px; border-radius:8px; font-weight:700; font-size:.85rem; direction:ltr }
.rule-actions { display:flex; gap:8px; align-items:center }
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= $message ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> ➕ إضافة استثناء حضور تلقائي</span>
    </div>
    <form method="POST" style="padding:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_rule">
        <div>
            <label class="form-label">الموظف</label>
            <select name="employee_id" class="form-control" required style="min-width:200px">
                <option value="">اختر موظف...</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?> — <?= htmlspecialchars($e['job_title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">من الساعة</label>
            <input type="time" name="time_from" class="form-control" value="08:00" required style="width:120px">
        </div>
        <div>
            <label class="form-label">إلى الساعة</label>
            <input type="time" name="time_to" class="form-control" value="08:05" required style="width:120px">
        </div>
        <button type="submit" class="btn btn-primary">إضافة</button>
    </form>
    <div style="padding:0 20px 16px;font-size:.82rem;color:var(--text3)">
        ⏰ سيتم تسجيل حضور الموظف تلقائياً كل يوم بوقت عشوائي بين الوقتين المحددين.
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> 🤖 القواعد الحالية</span>
        <span class="badge badge-blue"><?= count($rules) ?> قاعدة</span>
    </div>
    <div style="padding:16px">
        <?php if (empty($rules)): ?>
            <div style="text-align:center;padding:30px;color:var(--text3)">لا توجد استثناءات حضور تلقائي بعد</div>
        <?php else: ?>
            <?php foreach ($rules as $r): ?>
            <div class="rule-card <?= $r['is_active'] ? '' : 'inactive' ?>">
                <div class="rule-info">
                    <div class="rule-name"><?= htmlspecialchars($r['employee_name']) ?></div>
                    <div class="rule-detail">
                        <?= htmlspecialchars($r['job_title']) ?>
                        <?php if ($r['branch_name']): ?> • <?= htmlspecialchars($r['branch_name']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="rule-time">
                    🕐 <?= substr($r['auto_time_from'], 0, 5) ?> — <?= substr($r['auto_time_to'], 0, 5) ?>
                </div>
                <div class="rule-actions">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="toggle_rule">
                        <input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= $r['is_active'] ? 0 : 1 ?>">
                        <button type="submit" class="btn btn-sm <?= $r['is_active'] ? 'btn-secondary' : 'btn-primary' ?>" title="<?= $r['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                            <?= $r['is_active'] ? '⏸️ تعطيل' : '▶️ تفعيل' ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="delete_rule">
                        <input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</div></div></body></html>
