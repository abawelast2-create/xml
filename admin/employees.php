<?php
// admin/employees.php - إدارة الموظفين (CRUD + WhatsApp)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'إدارة الموظفين';
$activePage = 'employees';
$message    = '';
$msgType    = '';

// =================== إجراءات POST ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'طلب غير صالح';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // --- إضافة موظف ---
        if ($action === 'add') {
            $name    = sanitize($_POST['name'] ?? '');
            $job     = sanitize($_POST['job_title'] ?? '');
            $pin     = sanitize($_POST['pin'] ?? '');
            $phone   = sanitize($_POST['phone'] ?? '');
            $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;

            // توليد PIN تلقائي إذا لم يُحدد
            if (empty($pin)) {
                $pin = generateUniquePin();
            }

            if ($name && $job) {
                // التحقق من وجود الفرع
                if ($branchId !== null) {
                    $branchCheck = db()->prepare("SELECT id FROM branches WHERE id = ? AND is_active = 1");
                    $branchCheck->execute([$branchId]);
                    if (!$branchCheck->fetch()) {
                        $message = 'الفرع المحدد غير موجود أو غير مفعل';
                        $msgType = 'error';
                        header('Location: employees.php?msg=' . urlencode($message) . '&t=' . $msgType);
                        exit;
                    }
                }
                try {
                    $token = generateUniqueToken();
                    $stmt  = db()->prepare("INSERT INTO employees (name, job_title, pin, phone, branch_id, unique_token) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$name, $job, $pin, $phone ?: null, $branchId, $token]);
                    $newId = (int)db()->lastInsertId();
                    auditLog('add_employee', "إضافة موظف: {$name}", $newId);
                    $message = "تم إضافة الموظف {$name} بنجاح — PIN: {$pin}";
                    $msgType = 'success';
                } catch (PDOException $e) {
                    $message = 'PIN أو بيانات مكررة: ' . $e->getMessage();
                    $msgType = 'error';
                }
            } else {
                $message = 'أدخل الاسم والوظيفة';
                $msgType = 'error';
            }
        }

        // --- تعديل موظف ---
        if ($action === 'edit') {
            $id    = (int)($_POST['emp_id'] ?? 0);
            $name  = sanitize($_POST['name'] ?? '');
            $job   = sanitize($_POST['job_title'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $active = (int)($_POST['is_active'] ?? 1);
            $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;

            if ($id && $name && $job) {
                $stmt = db()->prepare("UPDATE employees SET name=?, job_title=?, phone=?, branch_id=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $job, $phone ?: null, $branchId, $active, $id]);
                auditLog('edit_employee', "تعديل موظف: {$name}", $id);
                $message = "تم تحديث بيانات الموظف";
                $msgType = 'success';
            }
        }

        // --- حذف موظف (Soft Delete) ---
        if ($action === 'delete') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET deleted_at=NOW(), is_active=0 WHERE id=?")->execute([$id]);
                auditLog('delete_employee', "أرشفة موظف ID={$id}", $id);
                $message = "تم أرشفة الموظف (يمكن استعادته لاحقاً)";
                $msgType = 'success';
            }
        }

        // --- استعادة موظف محذوف ---
        if ($action === 'restore') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET deleted_at=NULL, is_active=1 WHERE id=?")->execute([$id]);
                auditLog('restore_employee', "استعادة موظف ID={$id}", $id);
                $message = "تم استعادة الموظف بنجاح";
                $msgType = 'success';
            }
        }

        // --- إعادة توليد Token ---
        if ($action === 'regen_token') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                $token = generateUniqueToken();
                db()->prepare("UPDATE employees SET unique_token=? WHERE id=?")->execute([$token, $id]);
                $message = "تم توليد رابط جديد للموظف";
                $msgType = 'success';
            }
        }

        // --- تفعيل/تعطيل موظف ---
        if ($action === 'toggle') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
                $message = "تم تغيير حالة الموظف";
                $msgType = 'success';
            }
        }

        // --- تغيير PIN ---
        if ($action === 'change_pin') {
            $id = (int)($_POST['emp_id'] ?? 0);
            $newPin = trim($_POST['new_pin'] ?? '');
            if ($id) {
                if (empty($newPin)) {
                    $newPin = generateUniquePin();
                }
                if (!preg_match('/^\d{4}$/', $newPin)) {
                    $message = 'PIN يجب أن يكون 4 أرقام';
                    $msgType = 'error';
                } else {
                    try {
                        db()->prepare("UPDATE employees SET pin=?, pin_changed_at=NOW() WHERE id=?")->execute([$newPin, $id]);
                        auditLog('change_pin', "تغيير PIN للموظف ID={$id}", $id);
                        $message = "تم تغيير PIN إلى: {$newPin}";
                        $msgType = 'success';
                    } catch (PDOException $e) {
                        $message = 'PIN مكرر، جرب رقماً مختلفاً';
                        $msgType = 'error';
                    }
                }
            }
        }

        // --- توليد PIN تلقائي لجميع الموظفين بدون PIN ---
        if ($action === 'auto_generate_pins') {
            $emps = db()->query("SELECT id FROM employees WHERE (pin IS NULL OR pin = '') AND deleted_at IS NULL")->fetchAll();
            $count = 0;
            foreach ($emps as $emp) {
                $pin = generateUniquePin();
                db()->prepare("UPDATE employees SET pin=?, pin_changed_at=NOW() WHERE id=?")->execute([$pin, $emp['id']]);
                $count++;
            }
            auditLog('auto_generate_pins', "توليد PIN تلقائي لـ {$count} موظف");
            $message = "تم توليد PIN لـ {$count} موظف";
            $msgType = 'success';
        }

        // --- إعادة تعيين بصمة الجهاز ---
        if ($action === 'reset_device') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET device_fingerprint=NULL, device_registered_at=NULL, device_bind_mode=0 WHERE id=?")->execute([$id]);
                $message = "تم إعادة تعيين الجهاز — الرابط الآن حر بدون ربط";
                $msgType = 'success';
            }
        }

        // --- تفعيل ربط صارم (يربط عند الدخول التالي + يمنع الأجهزة المختلفة) ---
        if ($action === 'enable_bind') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET device_bind_mode=1 WHERE id=?")->execute([$id]);
                auditLog('enable_bind', "تفعيل ربط صارم للموظف ID={$id}", $id);
                $message = "تم تفعيل الربط الصارم — سيُمنع أي جهاز مختلف";
                $msgType = 'success';
            }
        }

        // --- تفعيل ربط مراقبة (يربط لكن لا يمنع — يسجل التلاعب بصمت) ---
        if ($action === 'enable_silent_bind') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET device_bind_mode=2 WHERE id=?")->execute([$id]);
                auditLog('enable_silent_bind', "تفعيل ربط مراقبة للموظف ID={$id}", $id);
                $message = "تم تفعيل ربط المراقبة — سيُسجّل التلاعب بصمت دون منع الموظف";
                $msgType = 'success';
            }
        }

        // --- فك ربط جميع الأجهزة ---
        if ($action === 'reset_all_devices') {
            $result = db()->exec("UPDATE employees SET device_fingerprint=NULL, device_registered_at=NULL, device_bind_mode=0 WHERE deleted_at IS NULL");
            auditLog('reset_all_devices', "فك ربط جميع الأجهزة — {$result} موظف");
            $message = "تم فك ربط جميع الأجهزة — {$result} موظف";
            $msgType = 'success';
        }

        // --- تفعيل الربط الصارم لجميع الموظفين عند الدخول القادم ---
        if ($action === 'enable_bind_all') {
            $result = db()->exec("UPDATE employees SET device_bind_mode=1 WHERE is_active=1 AND deleted_at IS NULL AND device_fingerprint IS NULL");
            auditLog('enable_bind_all', "تفعيل ربط صارم لجميع الموظفين — {$result} موظف");
            $message = "تم تفعيل الربط الصارم للجميع — {$result} موظف";
            $msgType = 'success';
        }

        // --- تفعيل ربط المراقبة لجميع الموظفين ---
        if ($action === 'enable_silent_bind_all') {
            $result = db()->exec("UPDATE employees SET device_bind_mode=2 WHERE is_active=1 AND deleted_at IS NULL AND device_fingerprint IS NULL");
            auditLog('enable_silent_bind_all', "تفعيل ربط مراقبة لجميع الموظفين — {$result} موظف");
            $message = "تم تفعيل ربط المراقبة للجميع — {$result} موظف (يُسجّل التلاعب بصمت)";
            $msgType = 'success';
        }
    }
    header('Location: employees.php?msg=' . urlencode($message) . '&t=' . $msgType);
    exit;
}

// عرض الرسالة من redirect
if (!empty($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $msgType = $_GET['t'] ?? 'success';
}

// =================== جلب الموظفين ===================
$search = trim($_GET['search'] ?? '');
$filterBranch = (int)($_GET['branch'] ?? 0);

$whereClause = '';
$params      = [];
$conditions  = ['e.deleted_at IS NULL'];
if ($search) {
    $conditions[] = "(e.name LIKE ? OR e.job_title LIKE ? OR e.pin LIKE ?)";
    $params       = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($filterBranch) {
    $conditions[] = "e.branch_id = ?";
    $params[]     = $filterBranch;
}
if ($conditions) {
    $whereClause = "WHERE " . implode(' AND ', $conditions);
}

$totalStmt = db()->prepare("SELECT COUNT(*) FROM employees e $whereClause");
$totalStmt->execute($params);
$total     = (int)$totalStmt->fetchColumn();

$empStmt = db()->prepare("SELECT e.*, b.name AS branch_name FROM employees e LEFT JOIN branches b ON e.branch_id = b.id $whereClause ORDER BY COALESCE(b.name, 'zzz') ASC, e.name ASC");
$empStmt->execute($params);
$employees = $empStmt->fetchAll();

// جلب ملكية الأجهزة: لكل بصمة، من هو أكثر مستخدم لها
$deviceOwners = [];
try {
    $ownerStmt = db()->query("
        SELECT kd.fingerprint, kd.employee_id, e.name AS owner_name, kd.usage_count
        FROM known_devices kd
        JOIN employees e ON kd.employee_id = e.id
        WHERE kd.id IN (
            SELECT MIN(sub.id) FROM (
                SELECT kd2.id, kd2.fingerprint, kd2.usage_count
                FROM known_devices kd2
                INNER JOIN (
                    SELECT fingerprint, MAX(usage_count) AS max_count
                    FROM known_devices
                    GROUP BY fingerprint
                ) best ON kd2.fingerprint = best.fingerprint AND kd2.usage_count = best.max_count
            ) sub GROUP BY sub.fingerprint
        )
    ");
    foreach ($ownerStmt as $row) {
        $deviceOwners[$row['fingerprint']] = [
            'employee_id' => (int)$row['employee_id'],
            'name' => $row['owner_name'],
            'count' => (int)$row['usage_count'],
        ];
    }
} catch (Exception $e) { /* الجدول قد لا يكون موجوداً بعد */
}

// جلب الفروع لعرضها في القوائم
$allBranches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// ألوان مميزة لكل فرع
$_bColors = ['#E74C3C', '#3498DB', '#2ECC71', '#9B59B6', '#F39C12', '#1ABC9C', '#E67E22', '#34495E', '#16A085', '#C0392B'];
$branchColorMap = [];
foreach ($allBranches as $_i => $br) {
    $branchColorMap[$br['id']] = $_bColors[$_i % count($_bColors)];
}

$csrf = generateCsrfToken();

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= $message ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:18px;padding:14px">
    <div class="top-actions" style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:flex-end">
        <!-- بحث -->
        <form method="GET" class="filter-bar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1;min-width:200px">
            <input class="form-control" name="search" placeholder="بحث بالاسم أو الوظيفة أو PIN..."
                value="<?= htmlspecialchars($search) ?>" style="max-width:240px">
            <select class="form-control" name="branch" style="max-width:180px">
                <option value="0">— كل الفروع —</option>
                <?php foreach ($allBranches as $br): ?>
                    <option value="<?= $br['id'] ?>" <?= $filterBranch == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">بحث</button>
            <?php if ($search || $filterBranch): ?><a href="employees.php" class="btn btn-secondary">إلغاء</a><?php endif; ?>
        </form>
        <div class="top-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-primary" onclick="openModal('addModal')">+ إضافة موظف</button>
            <a href="<?= SITE_URL ?>/employee/" target="_blank" class="btn btn-secondary" style="text-decoration:none">🔑 بوابة الحضور</a>
            <div class="dropdown-wrap" style="position:relative">
                <button class="btn btn-secondary" onclick="toggleBulkMenu(this)" type="button">
                    ⚙️ إجراءات جماعية ▾
                </button>
                <div class="dropdown-menu">
                    <button type="button" class="dropdown-item" onclick="regenerateAllTokens();this.closest('.dropdown-menu').classList.remove('show')">
                        <?= svgIcon('attendance', 16) ?> تجديد جميع الروابط
                    </button>
                    <button type="button" class="dropdown-item" onclick="copyAllLinks();this.closest('.dropdown-menu').classList.remove('show')" id="btnCopyAll">
                        <?= svgIcon('copy', 16) ?> نسخ جميع الروابط
                    </button>
                    <button type="button" class="dropdown-item" onclick="checkAllLinks();this.closest('.dropdown-menu').classList.remove('show')" id="btnCheckLinks">
                        🔍 فحص الروابط
                    </button>
                    <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                    <form method="POST" onsubmit="return confirm('فك ربط جميع الأجهزة؟')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="reset_all_devices">
                        <button type="submit" class="dropdown-item" style="color:var(--red)">
                            <?= svgIcon('lock', 16) ?> فك جميع الأجهزة
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('تفعيل الربط الصارم للجميع؟ سيُمنع أي جهاز مختلف.')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="enable_bind_all">
                        <button type="submit" class="dropdown-item" style="color:var(--red)">
                            🔒 ربط صارم للجميع
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('تفعيل ربط المراقبة للجميع؟ يُسجّل التلاعب بصمت دون منع.')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="enable_silent_bind_all">
                        <button type="submit" class="dropdown-item" style="color:var(--orange,#F59E0B)">
                            👁️ ربط مراقبة للجميع
                        </button>
                    </form>
                    <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                    <form method="POST" onsubmit="return confirm('توليد PIN تلقائي للموظفين بدون PIN؟')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="auto_generate_pins">
                        <button type="submit" class="dropdown-item" style="color:var(--green)">
                            🔑 توليد PIN تلقائي
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> قائمة الموظفين (<?= $total ?>)</span>
        <span class="badge badge-blue">جميع الموظفين</span>
    </div>
    <div style="overflow-x:auto">
        <table class="emp-table">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>الاسم</th>
                    <th>الوظيفة</th>
                    <th>الفرع</th>
                    <th>PIN</th>
                    <th>الحالة</th>
                    <th>الجهاز</th>
                    <th style="width:60px">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php $lastBranchName = null;
                $seq = 0;
                foreach ($employees as $i => $emp):
                    $curBranch = $emp['branch_name'] ?? 'بدون فرع';
                    $rowColor  = $branchColorMap[$emp['branch_id'] ?? 0] ?? '#94A3B8';
                    if ($curBranch !== $lastBranchName):
                        $lastBranchName = $curBranch;
                        // حساب عدد موظفي هذا الفرع
                        $brCount = 0;
                        foreach ($employees as $_e) {
                            if (($_e['branch_name'] ?? 'بدون فرع') === $curBranch) $brCount++;
                        }
                ?>
                        <tr class="branch-separator">
                            <td colspan="8" style="background:<?= $rowColor ?>12;border-right:4px solid <?= $rowColor ?>;padding:6px 14px;font-weight:700;font-size:.85rem;color:<?= $rowColor ?>">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $rowColor ?>;margin-left:5px;vertical-align:middle"></span>
                                <?= htmlspecialchars($curBranch) ?>
                                <span style="font-size:.7rem;font-weight:400;color:var(--text3);margin-right:4px">(<?= $brCount ?>)</span>
                                <button class="btn-copy-branch" onclick="copyBranchLinks('<?= htmlspecialchars(addslashes($curBranch), ENT_QUOTES) ?>')" title="نسخ روابط الفرع"><?= svgIcon('copy', 11) ?></button>
                            </td>
                        </tr>
                    <?php endif;
                    $seq++; ?>
                    <tr style="border-right:3px solid <?= $rowColor ?>">
                        <td style="color:var(--text3)"><?= $seq ?></td>
                        <td>
                            <strong><?= htmlspecialchars($emp['name']) ?></strong>
                            <?php if ($emp['phone']): ?>
                                <br><small style="color:var(--text3)"><?= htmlspecialchars($emp['phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($emp['job_title']) ?></td>
                        <td style="font-size:.8rem;font-weight:600;color:<?= $rowColor ?>"><?= htmlspecialchars($emp['branch_name'] ?? '—') ?></td>
                        <td style="font-family:monospace;font-size:.9rem;font-weight:700;letter-spacing:2px;text-align:center"><?= htmlspecialchars($emp['pin'] ?? '—') ?></td>
                        <td>
                            <?php if ($emp['is_active']): ?>
                                <span class="badge badge-green">مفعّل</span>
                            <?php else: ?>
                                <span class="badge badge-red">معطّل</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <?php
                            $bm = (int)($emp['device_bind_mode'] ?? 0);
                            $fp = $emp['device_fingerprint'] ?? '';
                            $devOwner = ($fp && isset($deviceOwners[$fp])) ? $deviceOwners[$fp] : null;
                            $ownerLabel = '';
                            if ($devOwner) {
                                if ($devOwner['employee_id'] === (int)$emp['id']) {
                                    $ownerLabel = 'جهازه';
                                } else {
                                    $ownerLabel = 'جهاز ' . $devOwner['name'];
                                }
                            }
                            if (!empty($fp) && $bm === 1): ?>
                                <span title="ربط صارم — <?= $emp['device_registered_at'] ? date('Y-m-d', strtotime($emp['device_registered_at'])) : '' ?>" style="color:var(--red);cursor:default"><?= svgIcon('lock', 18) ?></span>
                            <?php elseif (!empty($fp) && $bm === 2): ?>
                                <span title="ربط مراقبة — <?= $emp['device_registered_at'] ? date('Y-m-d', strtotime($emp['device_registered_at'])) : '' ?>" style="color:var(--orange,#F59E0B);cursor:default">👁️</span>
                            <?php elseif (!empty($fp)): ?>
                                <span title="مربوط — <?= $emp['device_registered_at'] ? date('Y-m-d', strtotime($emp['device_registered_at'])) : '' ?>" style="color:var(--green);cursor:default"><?= svgIcon('lock', 18) ?></span>
                            <?php elseif ($bm === 1): ?>
                                <span class="badge badge-yellow" style="font-size:.65rem" title="ينتظر ربط صارم">🔒 ينتظر</span>
                            <?php elseif ($bm === 2): ?>
                                <span class="badge badge-yellow" style="font-size:.65rem" title="ينتظر ربط مراقبة">👁️ ينتظر</span>
                            <?php else: ?>
                                <span class="badge badge-blue" style="font-size:.65rem" title="حر — لا يحتاج ربط جهاز">🔓 حر</span>
                            <?php endif; ?>
                            <?php if ($ownerLabel): ?>
                                <div style="font-size:.62rem;color:<?= ($devOwner && $devOwner['employee_id'] !== (int)$emp['id']) ? 'var(--orange,#F59E0B)' : 'var(--text3)' ?>;margin-top:2px;line-height:1.1" title="استخدام: <?= $devOwner['count'] ?? 0 ?> مرة">📱 <?= $ownerLabel ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $link = SITE_URL . '/employee/attendance.php?token=' . $emp['unique_token']; ?>
                            <div class="dropdown-wrap">
                                <button class="btn btn-secondary btn-sm" onclick="toggleEmpMenu(this)" type="button">⚙️ ▾</button>
                                <div class="dropdown-menu emp-actions-menu">
                                    <!-- روابط -->
                                    <a href="<?= $link ?>" target="_blank" class="dropdown-item">🔗 فتح الرابط</a>
                                    <button type="button" class="dropdown-item" onclick="copyLink('<?= $link ?>');this.closest('.dropdown-menu').classList.remove('show')">📋 نسخ الرابط</button>
                                    <?php if ($emp['phone']): ?>
                                        <a href="<?= generateWhatsAppLink($emp['phone'], $emp['unique_token']) ?>" target="_blank" class="dropdown-item" style="color:#25D366">💬 إرسال واتساب</a>
                                    <?php endif; ?>
                                    <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                                    <!-- تعديل -->
                                    <button type="button" class="dropdown-item" onclick='this.closest(".dropdown-menu").classList.remove("show");openEditModal(<?= json_encode($emp, JSON_UNESCAPED_UNICODE) ?>)'><?= svgIcon('settings', 14) ?> تعديل البيانات</button>
                                    <!-- تغيير PIN -->
                                    <button type="button" class="dropdown-item" onclick='this.closest(".dropdown-menu").classList.remove("show");openChangePinModal(<?= (int)$emp["id"] ?>, <?= json_encode($emp["name"], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($emp["pin"] ?? "", JSON_UNESCAPED_UNICODE) ?>)'><?= svgIcon('key', 14) ?> تغيير PIN</button>
                                    <!-- توليد رابط -->
                                    <form method="POST" onsubmit="return confirm('إعادة توليد رابط؟ الرابط القديم سيتوقف.')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="regen_token">
                                        <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                        <button type="submit" class="dropdown-item"><?= svgIcon('checkout', 14) ?> تجديد الرابط</button>
                                    </form>
                                    <!-- تفعيل/تعطيل -->
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                        <?php if ($emp['is_active']): ?>
                                            <button type="submit" class="dropdown-item" style="color:var(--red)"><?= svgIcon('absent', 14) ?> تعطيل</button>
                                        <?php else: ?>
                                            <button type="submit" class="dropdown-item" style="color:var(--green)"><?= svgIcon('checkin', 14) ?> تفعيل</button>
                                        <?php endif; ?>
                                    </form>
                                    <!-- جهاز -->
                                    <?php if (!empty($emp['device_fingerprint'])): ?>
                                        <form method="POST" onsubmit="return confirm('فك ربط الجهاز؟')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="reset_device">
                                            <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="dropdown-item"><?= svgIcon('lock', 14) ?> فك ربط الجهاز</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" onsubmit="return confirm('ربط صارم: سيُمنع أي جهاز مختلف من الدخول')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="enable_bind">
                                            <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="dropdown-item" style="color:var(--red)">🔒 ربط صارم</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('ربط مراقبة: يُسمح بالدخول من أي جهاز لكن يُسجّل التلاعب بصمت')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="enable_silent_bind">
                                            <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="dropdown-item" style="color:var(--orange,#F59E0B)">👁️ ربط مراقبة</button>
                                        </form>
                                    <?php endif; ?>
                                    <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                                    <!-- أرشفة -->
                                    <form method="POST" onsubmit="return confirm('أرشفة الموظف؟')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                        <button type="submit" class="dropdown-item" style="color:var(--red)"><?= svgIcon('absent', 14) ?> أرشفة الموظف</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:30px;color:var(--text3)">لا توجد نتائج</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- =================== Modal إضافة موظف =================== -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-title"><?= svgIcon('employees', 20) ?> إضافة موظف جديد</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">الاسم الكامل *</label>
                    <input class="form-control" name="name" required placeholder="محمد أحمد ...">
                </div>
                <div class="form-group">
                    <label class="form-label">المسمى الوظيفي *</label>
                    <input class="form-control" name="job_title" required placeholder="مهندس">
                </div>
                <div class="form-group">
                    <label class="form-label">PIN (رقم سري)</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input class="form-control" name="pin" placeholder="تلقائي" style="direction:ltr;max-width:140px" maxlength="4" pattern="\d{4}">
                        <small style="color:var(--text3);white-space:nowrap">اتركه فارغاً للتوليد التلقائي</small>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">الفرع</label>
                    <select class="form-control" name="branch_id">
                        <option value="">— بدون فرع —</option>
                        <?php foreach ($allBranches as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الواتساب (اختياري)</label>
                    <input class="form-control" name="phone" placeholder="966501234567" style="direction:ltr">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ →</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== Modal تعديل موظف =================== -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-title"><?= svgIcon('settings', 20) ?> تعديل بيانات الموظف</div>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="emp_id" id="editId">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">الاسم الكامل *</label>
                    <input class="form-control" name="name" id="editName" required>
                </div>
                <div class="form-group">
                    <label class="form-label">المسمى الوظيفي *</label>
                    <input class="form-control" name="job_title" id="editJob" required>
                </div>
                <div class="form-group">
                    <label class="form-label">الفرع</label>
                    <select class="form-control" name="branch_id" id="editBranch">
                        <option value="">— بدون فرع —</option>
                        <?php foreach ($allBranches as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الواتساب</label>
                    <input class="form-control" name="phone" id="editPhone" style="direction:ltr">
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة</label>
                    <select class="form-control" name="is_active" id="editActive">
                        <option value="1">مفعّل</option>
                        <option value="0">معطّل</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ →</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== Modal تغيير PIN =================== -->
<div class="modal-overlay" id="changePinModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-title"><?= svgIcon('key', 20) ?> تغيير PIN</div>
        <form method="POST" id="changePinForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="change_pin">
            <input type="hidden" name="emp_id" id="cpEmpId">
            <div style="margin-bottom:16px">
                <div style="font-weight:700;margin-bottom:4px" id="cpEmpName"></div>
                <div style="font-size:.85rem;color:var(--text3)">PIN الحالي: <code id="cpCurrentPin" style="font-size:1rem;font-weight:700;letter-spacing:2px"></code></div>
            </div>
            <div class="form-group">
                <label class="form-label">PIN الجديد (4 أرقام)</label>
                <div style="display:flex;gap:10px;align-items:center">
                    <input class="form-control" name="new_pin" id="cpNewPin" placeholder="اتركه فارغاً للتوليد التلقائي" style="direction:ltr;font-size:1.1rem;letter-spacing:2px;font-weight:700;max-width:200px" maxlength="4" pattern="\d{4}">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('cpNewPin').value=String(Math.floor(1000+Math.random()*9000))" style="white-space:nowrap">🎲 عشوائي</button>
                </div>
                <small style="color:var(--text3)">اتركه فارغاً لتوليد رقم فريد تلقائياً</small>
            </div>
            <div style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:8px;padding:10px 14px;margin:12px 0;font-size:.82rem;color:#92400E">
                ⚠️ تغيير PIN سيطلب من الموظف إدخال الرقم الجديد عند الدخول التالي
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('changePinModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ →</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    function openEditModal(emp) {
        document.getElementById('editId').value = emp.id;
        document.getElementById('editName').value = emp.name;
        document.getElementById('editJob').value = emp.job_title;
        document.getElementById('editPhone').value = emp.phone || '';
        document.getElementById('editBranch').value = emp.branch_id || '';
        document.getElementById('editActive').value = emp.is_active;
        openModal('editModal');
    }

    function openChangePinModal(empId, empName, currentPin) {
        document.getElementById('cpEmpId').value = empId;
        document.getElementById('cpEmpName').textContent = empName;
        document.getElementById('cpCurrentPin').textContent = currentPin || '—';
        document.getElementById('cpNewPin').value = '';
        openModal('changePinModal');
    }

    function copyLink(link) {
        navigator.clipboard.writeText(link).then(() => alert('تم نسخ الرابط!')).catch(() => {
            prompt('انسخ الرابط:', link);
        });
    }

    // تحديث جميع الروابط
    function regenerateAllTokens() {
        if (!confirm('هل أنت متأكد من تجديد جميع الروابط؟\n\nسيتم إنشاء روابط جديدة لجميع الموظفين النشطين.\nالروابط القديمة لن تعمل بعد ذلك.')) {
            return;
        }

        const btn = document.getElementById('btnRegenerate');
        btn.disabled = true;
        btn.innerHTML = '⏳ جاري التجديد...';

        const formData = new FormData();
        formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
        formData.append('action', 'all');

        fetch('../api/regenerate-tokens.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ خطأ: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = '<?= svgIcon('attendance', 16) ?> تجديد جميع الروابط';
                }
            })
            .catch(err => {
                alert('❌ حدث خطأ: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = '<?= svgIcon('attendance', 16) ?> تجديد جميع الروابط';
            });
    }

    // بيانات الروابط حسب الفرع — يُبنى من PHP
    const branchLinksData = <?php
                            // بناء بيانات الفروع والروابط
                            $branchLinks = [];
                            foreach ($employees as $emp) {
                                if (!$emp['is_active']) continue;
                                $bName = $emp['branch_name'] ?? 'بدون فرع';
                                if (!isset($branchLinks[$bName])) $branchLinks[$bName] = [];
                                $branchLinks[$bName][] = [
                                    'name' => $emp['name'],
                                    'link' => SITE_URL . '/employee/attendance.php?token=' . $emp['unique_token']
                                ];
                            }
                            echo json_encode($branchLinks, JSON_UNESCAPED_UNICODE);
                            ?>;

    function formatBranchMessage(branchName, emps) {
        const today = new Date().toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        let msg = '📋 *روابط تسجيل الحضور والانصراف*\n';
        msg += '🏢 *الفرع: ' + branchName + '*\n';
        msg += '📅 *التاريخ: ' + today + '*\n';
        msg += '━━━━━━━━━━━━━━━\n\n';
        emps.forEach((e, i) => {
            msg += (i + 1) + '. *' + e.name + '*\n';
            msg += '🔗 ' + e.link + '\n\n';
        });
        msg += '━━━━━━━━━━━━━━━\n';
        msg += '⚠️ _الرابط خاص بك، لا تشاركه مع أحد_';
        return msg;
    }

    function copyBranchLinks(branchName) {
        const emps = branchLinksData[branchName];
        if (!emps || emps.length === 0) {
            alert('لا يوجد موظفين نشطين في هذا الفرع');
            return;
        }
        const msg = formatBranchMessage(branchName, emps);
        navigator.clipboard.writeText(msg).then(() => {
            alert('✅ تم نسخ روابط فرع "' + branchName + '" (' + emps.length + ' موظف) — الصقها في مجموعة الواتساب');
        }).catch(() => {
            prompt('انسخ النص:', msg);
        });
    }

    function copyAllLinks() {
        const btn = document.getElementById('btnCopyAll');
        const today = new Date().toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        let msg = '📋 *جميع روابط تسجيل الحضور والانصراف*\n';
        msg += '📅 *التاريخ: ' + today + '*\n';
        msg += '━━━━━━━━━━━━━━━\n\n';
        const branches = Object.keys(branchLinksData);
        branches.forEach(branchName => {
            const emps = branchLinksData[branchName];
            if (emps.length === 0) return;
            msg += '🏢 *' + branchName + '* (' + emps.length + ' موظف)\n';
            msg += '───────────────\n';
            emps.forEach((e, i) => {
                msg += (i + 1) + '. *' + e.name + '*\n';
                msg += '🔗 ' + e.link + '\n\n';
            });
        });
        msg += '━━━━━━━━━━━━━━━\n';
        msg += '⚠️ _الرابط خاص بك، لا تشاركه مع أحد_';
        navigator.clipboard.writeText(msg).then(() => {
            const total = branches.reduce((s, b) => s + branchLinksData[b].length, 0);
            btn.innerHTML = '✅ تم النسخ (' + total + ' موظف)';
            setTimeout(() => {
                btn.innerHTML = '<?= svgIcon('copy', 16) ?> نسخ جميع الروابط';
            }, 3000);
        }).catch(() => {
            prompt('انسخ النص:', msg);
        });
    }

    // فحص جميع الروابط
    function checkAllLinks() {
        const btn = document.getElementById('btnCheckLinks');
        btn.disabled = true;
        btn.innerHTML = '⏳ جاري الفحص...';

        // تعيين كل الحالات لـ "جاري..."
        document.querySelectorAll('.link-status').forEach(el => {
            el.innerHTML = '<span style="color:var(--text3)">⏳</span>';
        });

        fetch('../api/check-links.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    data.results.forEach(r => {
                        const el = document.querySelector(`.link-status[data-emp-id="${r.id}"]`);
                        if (!el) return;
                        if (r.status === 'ok') {
                            el.innerHTML = '<span class="badge badge-green" style="font-size:.65rem">✅ يعمل</span>';
                        } else if (r.status === 'inactive') {
                            el.innerHTML = '<span class="badge badge-yellow" style="font-size:.65rem">⚠️ معطّل</span>';
                        } else {
                            el.innerHTML = '<span class="badge badge-red" style="font-size:.65rem">❌ خطأ ' + r.code + '</span>';
                        }
                    });
                    btn.innerHTML = '✅ تم الفحص (' + data.ok + '/' + data.total + ')';
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = '🔍 فحص الروابط';
                    }, 5000);
                } else {
                    alert('❌ خطأ: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = '🔍 فحص الروابط';
                }
            })
            .catch(err => {
                alert('❌ حدث خطأ: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = '🔍 فحص الروابط';
            });
    }

    // إغلاق modal عند الضغط خارجه
    document.querySelectorAll('.modal-overlay').forEach(o => {
        o.addEventListener('click', e => {
            if (e.target === o) o.classList.remove('show');
        });
    });

    // إغلاق dropdown عند الضغط خارجه
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-wrap')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    // فتح/إغلاق قائمة الإجراءات مع إغلاق أي قائمة أخرى مفتوحة
    function toggleEmpMenu(btn) {
        const menu = btn.nextElementSibling;
        const wasOpen = menu.classList.contains('show');
        // أغلق جميع القوائم أولاً
        closeAllMenus();
        if (!wasOpen) {
            menu.classList.add('show');
            const ov = document.getElementById('dropdownOverlay');
            if (ov && window.innerWidth <= 768) ov.classList.add('show');
        }
    }
    function closeAllMenus() {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
        const ov = document.getElementById('dropdownOverlay');
        if (ov) ov.classList.remove('show');
    }
    document.getElementById('dropdownOverlay')?.addEventListener('click', closeAllMenus);
    function toggleBulkMenu(btn) {
        const menu = btn.nextElementSibling;
        const wasOpen = menu.classList.contains('show');
        closeAllMenus();
        if (!wasOpen) {
            menu.classList.add('show');
            const ov = document.getElementById('dropdownOverlay');
            if (ov && window.innerWidth <= 768) ov.classList.add('show');
        }
    }
    // إغلاق القوائم عند النقر خارجها (desktop)
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-wrap')) closeAllMenus();
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