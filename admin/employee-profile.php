<?php
// =============================================================
// admin/employee-profile.php — بروفايل الموظف (إدارة)
// =============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();

// ─── Auto-migrate جداول البروفايل ───────────────────────────────────
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS emp_document_groups (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            group_name  VARCHAR(200) NOT NULL DEFAULT '',
            expiry_date DATE         NOT NULL,
            sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_emp  (employee_id),
            INDEX idx_exp  (expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    db()->exec("
        CREATE TABLE IF NOT EXISTS emp_document_files (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id      INT UNSIGNED NOT NULL,
            file_path     VARCHAR(500) NOT NULL,
            file_type     ENUM('image','pdf') NOT NULL DEFAULT 'image',
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            file_size     INT UNSIGNED NOT NULL DEFAULT 0,
            sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // إضافة عمود الصورة الشخصية إن لم يكن موجوداً
    try {
        db()->exec("ALTER TABLE employees ADD COLUMN profile_photo VARCHAR(500) NULL AFTER phone");
    } catch (PDOException $e) { /* العمود موجود بالفعل */ }
} catch (PDOException $e) {
    error_log('Profile migration error: ' . $e->getMessage());
}

// ─── التحقق من وجود الموظف ──────────────────────────────────────────
$empId = (int)($_GET['id'] ?? 0);
if (!$empId) { header('Location: employees.php'); exit; }

$empStmt = db()->prepare("
    SELECT e.*, b.name AS branch_name
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.id = ? AND e.deleted_at IS NULL
");
$empStmt->execute([$empId]);
$employee = $empStmt->fetch();
if (!$employee) { header('Location: employees.php'); exit; }

// ─── جلب المجموعات والملفات ─────────────────────────────────────────
$gStmt = db()->prepare("
    SELECT g.*,
           DATEDIFF(g.expiry_date, CURDATE()) AS days_left
    FROM emp_document_groups g
    WHERE g.employee_id = ?
    ORDER BY g.sort_order, g.created_at
");
$gStmt->execute([$empId]);
$groups = $gStmt->fetchAll();

foreach ($groups as &$g) {
    $fStmt = db()->prepare("SELECT * FROM emp_document_files WHERE group_id = ? ORDER BY sort_order, id");
    $fStmt->execute([$g['id']]);
    $g['files'] = $fStmt->fetchAll();
}
unset($g);

$pageTitle  = 'بروفايل: ' . $employee['name'];
$activePage = 'employees';

// ─── Helper: badge CSS class لعدد الأيام ─────────────────────────────
function daysBadgeClass(int $days): string {
    if ($days <= 10)              return 'badge-critical';
    if ($days <= 30)              return 'badge-warning';
    if ($days <= 90)              return 'badge-caution';
    return 'badge-ok';
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
/* ─── Profile Hero ───────────────────────────── */
.profile-hero{display:flex;align-items:center;gap:24px;background:#fff;border-radius:var(--radius);padding:28px 32px;margin-bottom:24px;box-shadow:var(--shadow);flex-wrap:wrap}
.photo-wrap{position:relative;flex-shrink:0}
.profile-avatar{width:110px;height:110px;border-radius:50%;object-fit:cover;border:3px solid var(--primary-l);background:var(--surface3);display:block}
.photo-placeholder{width:110px;height:110px;border-radius:50%;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:2.8rem;color:var(--primary);font-weight:800;border:3px solid var(--primary-l)}
.photo-overlay{position:absolute;bottom:0;right:0;display:flex;gap:4px}
.photo-btn{width:32px;height:32px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem;transition:all .2s}
.photo-btn-edit{background:var(--primary);color:#fff}
.photo-btn-del{background:var(--red);color:#fff}
.photo-btn:hover{transform:scale(1.1)}
.profile-meta{flex:1;min-width:0}
.profile-name{font-size:1.6rem;font-weight:800;color:var(--text);margin-bottom:4px}
.profile-job{font-size:.95rem;color:var(--text2);margin-bottom:2px}
.profile-branch{font-size:.85rem;color:var(--text3)}
.profile-phone{font-size:.85rem;color:var(--text3);margin-top:2px;direction:ltr;text-align:right}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--primary);font-size:.85rem;font-weight:600;text-decoration:none;margin-bottom:16px;transition:color .2s}
.back-link:hover{color:var(--primary-d)}

/* ─── Groups Section ─────────────────────────── */
.section-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px}
.section-bar h2{font-size:1.1rem;font-weight:700;color:var(--text)}
.group-counter{font-size:.8rem;color:var(--text3);font-weight:600}
.btn-add-group{padding:9px 18px;background:var(--primary);color:#fff;border:none;border-radius:10px;font-family:'Tajawal',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:6px}
.btn-add-group:hover{background:var(--primary-d);transform:translateY(-1px)}
.btn-add-group:disabled{opacity:.5;cursor:not-allowed;transform:none}

/* ─── Group Card ─────────────────────────────── */
.group-card{background:#fff;border-radius:var(--radius);padding:20px;margin-bottom:16px;box-shadow:var(--shadow);border:1px solid var(--border);transition:all .2s}
.group-card:hover{box-shadow:var(--shadow-md)}
.group-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.group-name-input{flex:1;min-width:150px;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:'Tajawal',sans-serif;font-size:.92rem;color:var(--text);background:var(--surface2);transition:border-color .2s}
.group-name-input:focus{border-color:var(--primary);background:#fff;outline:none}
.group-expiry-input{padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:'Tajawal',sans-serif;font-size:.88rem;color:var(--text);background:var(--surface2);cursor:pointer;transition:border-color .2s}
.group-expiry-input:focus{border-color:var(--primary);background:#fff;outline:none}
.save-indicator{font-size:.72rem;color:var(--green);opacity:0;transition:opacity .4s;white-space:nowrap}
.save-indicator.show{opacity:1}
.btn-del-group{margin-right:auto;padding:7px 13px;background:var(--red-l);color:var(--red);border:none;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:4px}
.btn-del-group:hover{background:var(--red);color:#fff}

/* ─── Days Badge ─────────────────────────────── */
.days-badge{padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:700;white-space:nowrap;flex-shrink:0}
.badge-critical{background:#FEE2E2;color:#991B1B;box-shadow:0 0 0 3px rgba(220,38,38,.3);animation:pulse-red 1.4s ease-in-out infinite}
.badge-warning{background:#FEE2E2;color:#B91C1C}
.badge-caution{background:#FEF3C7;color:#92400E}
.badge-ok{background:#D1FAE5;color:#065F46}
.badge-expired{background:#F1F5F9;color:#64748B}
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 3px rgba(220,38,38,.35)}50%{box-shadow:0 0 0 7px rgba(220,38,38,.06)}}

/* ─── Files Grid ─────────────────────────────── */
.files-grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;min-height:56px;align-items:flex-start}
.file-thumb{position:relative;width:72px;height:72px;border-radius:10px;overflow:hidden;border:1.5px solid var(--border);background:var(--surface3);cursor:pointer;transition:all .2s;flex-shrink:0}
.file-thumb:hover{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-l)}
.file-thumb img{width:100%;height:100%;object-fit:cover}
.file-thumb-pdf{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:2px;color:var(--red)}
.file-thumb-pdf span{font-size:.6rem;font-weight:700;color:var(--text3)}
.thumb-del{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:rgba(220,38,38,.9);color:#fff;border:none;cursor:pointer;font-size:.7rem;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s}
.file-thumb:hover .thumb-del{opacity:1}
.btn-add-doc{padding:8px 14px;border:2px dashed var(--border2);background:var(--surface3);color:var(--text2);border-radius:10px;font-family:'Tajawal',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:6px}
.btn-add-doc:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-xl)}
.files-count{font-size:.72rem;color:var(--text3);margin-top:6px}

/* ─── Modals ─────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(3px)}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{background:#fff;border-radius:20px;padding:28px;width:100%;max-width:460px;transform:translateY(20px);transition:transform .25s;position:relative}
.modal-overlay.open .modal-box{transform:translateY(0)}
.modal-box.modal-lg{max-width:700px}
.modal-title{font-size:1.1rem;font-weight:800;color:var(--text);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.modal-close{position:absolute;top:16px;left:20px;background:var(--surface3);border:none;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1rem;color:var(--text2);display:flex;align-items:center;justify-content:center;transition:all .2s}
.modal-close:hover{background:var(--red-l);color:var(--red)}
.upload-options{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:8px}
.upload-opt{padding:20px 16px;border:2px solid var(--border);border-radius:14px;background:var(--surface2);cursor:pointer;text-align:center;transition:all .2s}
.upload-opt:hover{border-color:var(--primary);background:var(--primary-xl)}
.upload-opt-icon{font-size:2rem;margin-bottom:8px}
.upload-opt-label{font-size:.88rem;font-weight:700;color:var(--text)}
.upload-opt-sub{font-size:.75rem;color:var(--text3);margin-top:3px}

/* Camera modal */
.camera-modal .modal-box{max-width:520px}
#cameraVideo{width:100%;border-radius:12px;background:#000;max-height:340px;object-fit:cover;aspect-ratio:4/3}
.camera-controls{display:flex;justify-content:center;gap:12px;margin-top:14px;flex-wrap:wrap}
.btn-capture{padding:11px 28px;background:var(--primary);color:#fff;border:none;border-radius:12px;font-family:'Tajawal',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .2s}
.btn-capture:hover{background:var(--primary-d)}
.btn-cancel-camera{padding:11px 20px;background:var(--surface3);color:var(--text2);border:none;border-radius:12px;font-family:'Tajawal',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-cancel-camera:hover{background:var(--border)}
#cameraCanvas{display:none}
.preview-wrap{position:relative;width:100%}
.preview-wrap img{width:100%;border-radius:12px;max-height:340px;object-fit:cover}
.preview-actions{display:flex;gap:12px;margin-top:12px;justify-content:center}
.btn-retake{padding:10px 20px;background:var(--surface3);color:var(--text2);border:none;border-radius:12px;font-family:'Tajawal',sans-serif;font-size:.88rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-retake:hover{background:var(--border)}
.btn-use-photo{padding:10px 24px;background:var(--primary);color:#fff;border:none;border-radius:12px;font-family:'Tajawal',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:all .2s}
.btn-use-photo:hover{background:var(--primary-d)}

/* Lightbox */
.lightbox-overlay{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .2s}
.lightbox-overlay.open{opacity:1;pointer-events:all}
.lightbox-content{position:relative;max-width:90vw;max-height:90vh;display:flex;align-items:center;justify-content:center}
.lightbox-content img{max-width:90vw;max-height:85vh;border-radius:10px;object-fit:contain}
.lightbox-content iframe{width:80vw;height:85vh;border:none;border-radius:10px}
.lightbox-close{position:fixed;top:16px;left:20px;background:rgba(255,255,255,.15);border:none;border-radius:10px;padding:10px 16px;color:#fff;font-size:.9rem;cursor:pointer;transition:all .2s}
.lightbox-close:hover{background:rgba(255,255,255,.3)}
.lightbox-nav{position:fixed;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.12);border:none;color:#fff;font-size:1.5rem;padding:14px 10px;cursor:pointer;border-radius:8px;transition:all .2s}
.lightbox-nav:hover{background:rgba(255,255,255,.25)}
#lightboxPrev{right:12px}#lightboxNext{left:12px}
.lightbox-counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.5);color:#fff;padding:6px 14px;border-radius:20px;font-size:.82rem}

/* Loading spinner */
.upload-spinner{width:24px;height:24px;border:2.5px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.toast{position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-60px);background:#1E293B;color:#fff;padding:12px 24px;border-radius:12px;font-size:.88rem;font-weight:600;z-index:9999;transition:all .3s;opacity:0;pointer-events:none}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.success{background:#059669}
.toast.error{background:var(--red)}
</style>

<div class="toast" id="toast"></div>

<a href="employees.php" class="back-link">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
    العودة إلى قائمة الموظفين
</a>

<!-- ─── Profile Hero ─────────────────────────────────────────────── -->
<div class="profile-hero">
    <div class="photo-wrap">
        <?php
        $photoUrl = '';
        if (!empty($employee['profile_photo'])) {
            $photoUrl = SITE_URL . '/api/serve-file.php?f=' . urlencode($employee['profile_photo']);
        }
        $initials = mb_substr($employee['name'], 0, 1);
        ?>
        <?php if ($photoUrl): ?>
            <img src="<?= htmlspecialchars($photoUrl) ?>" class="profile-avatar" id="profilePhotoImg" alt="">
        <?php else: ?>
            <div class="photo-placeholder" id="profilePhotoPlaceholder"><?= htmlspecialchars($initials) ?></div>
        <?php endif; ?>
        <div class="photo-overlay">
            <button class="photo-btn photo-btn-edit" onclick="openPhotoUpload()" title="تغيير الصورة">✏️</button>
            <?php if ($photoUrl): ?>
            <button class="photo-btn photo-btn-del" id="photoDelBtn" onclick="deletePhoto()" title="حذف الصورة">🗑️</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="profile-meta">
        <div class="profile-name"><?= htmlspecialchars($employee['name']) ?></div>
        <div class="profile-job"><?= htmlspecialchars($employee['job_title']) ?></div>
        <?php if ($employee['branch_name']): ?>
        <div class="profile-branch">🏢 <?= htmlspecialchars($employee['branch_name']) ?></div>
        <?php endif; ?>
        <?php if ($employee['phone']): ?>
        <div class="profile-phone">📞 <?= htmlspecialchars($employee['phone']) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Document Groups ──────────────────────────────────────────── -->
<div class="section-bar">
    <h2>📂 مجموعات الوثائق</h2>
    <div style="display:flex;align-items:center;gap:12px">
        <span class="group-counter" id="groupCounter"><?= count($groups) ?>/10 مجموعات</span>
        <button class="btn-add-group" id="addGroupBtn"
            <?= count($groups) >= 10 ? 'disabled' : '' ?>
            onclick="addGroup()">
            ＋ إضافة مجموعة
        </button>
    </div>
</div>

<div id="groupsContainer">
<?php foreach ($groups as $g):
    $days     = (int)$g['days_left'];
    $badgeClass = daysBadgeClass($days);
    $badgeLabel = $days >= 0 ? $days . ' يوم' : 'منتهية';
    if ($days < 0) $badgeClass = 'badge-expired';
?>
<div class="group-card" data-gid="<?= $g['id'] ?>">
    <div class="group-header">
        <input class="group-name-input"
               placeholder="اسم المجموعة (اختياري)"
               value="<?= htmlspecialchars($g['group_name']) ?>"
               data-gid="<?= $g['id'] ?>"
               onblur="saveGroup(<?= $g['id'] ?>, this.value, this.closest('.group-card').querySelector('.group-expiry-input').value)"
               maxlength="200">
        <input type="date" class="group-expiry-input"
               value="<?= htmlspecialchars($g['expiry_date']) ?>"
               data-gid="<?= $g['id'] ?>"
               onchange="saveGroup(<?= $g['id'] ?>, this.closest('.group-card').querySelector('.group-name-input').value, this.value)">
        <span class="days-badge <?= $badgeClass ?>" data-badge="<?= $g['id'] ?>"><?= $badgeLabel ?></span>
        <span class="save-indicator" id="saved_<?= $g['id'] ?>">✓ حُفظ</span>
        <button class="btn-del-group" onclick="deleteGroup(<?= $g['id'] ?>, <?= $empId ?>)">🗑️ حذف</button>
    </div>
    <div class="files-grid" id="grid_<?= $g['id'] ?>">
        <?php foreach ($g['files'] as $f): ?>
        <?php
        $fileUrl = SITE_URL . '/api/serve-file.php?f=' . urlencode($f['file_path']);
        ?>
        <div class="file-thumb" data-fid="<?= $f['id'] ?>" data-url="<?= htmlspecialchars($fileUrl) ?>" data-type="<?= $f['file_type'] ?>" onclick="openLightbox(<?= $g['id'] ?>, <?= $f['id'] ?>)">
            <?php if ($f['file_type'] === 'image'): ?>
                <img src="<?= htmlspecialchars($fileUrl) ?>" alt="" loading="lazy">
            <?php else: ?>
                <div class="file-thumb-pdf">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="#DC2626"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg>
                    <span><?= htmlspecialchars(mb_substr($f['original_name'] ?: 'PDF', 0, 8)) ?></span>
                </div>
            <?php endif; ?>
            <button class="thumb-del" onclick="deleteDoc(event, <?= $f['id'] ?>, <?= $empId ?>, <?= $g['id'] ?>)" title="حذف">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
        <button class="btn-add-doc" onclick="openDocUpload(<?= $g['id'] ?>)"
            <?= count($g['files']) >= 10 ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>
            ＋ إضافة وثيقة
        </button>
        <span class="files-count" id="cnt_<?= $g['id'] ?>"><?= count($g['files']) ?>/10 ملفات</span>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ─── Modal: اختيار مصدر الرفع (صورة بروفايل) ──────────────────── -->
<div class="modal-overlay" id="photoUploadModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('photoUploadModal')">✕</button>
        <div class="modal-title">📷 تغيير صورة البروفايل</div>
        <div class="upload-options">
            <div class="upload-opt" onclick="openCamera('photo')">
                <div class="upload-opt-icon">📷</div>
                <div class="upload-opt-label">من الكاميرا</div>
                <div class="upload-opt-sub">التقط صورة الآن</div>
            </div>
            <div class="upload-opt" onclick="document.getElementById('photoFileInput').click()">
                <div class="upload-opt-icon">🖼️</div>
                <div class="upload-opt-label">من الملفات</div>
                <div class="upload-opt-sub">jpg / png / webp</div>
            </div>
        </div>
        <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="uploadPhotoFile(this)">
    </div>
</div>

<!-- ─── Modal: اختيار مصدر الرفع (وثيقة) ────────────────────────── -->
<div class="modal-overlay" id="docUploadModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('docUploadModal')">✕</button>
        <div class="modal-title">📎 إضافة وثيقة</div>
        <div class="upload-options">
            <div class="upload-opt" onclick="openCamera('document')">
                <div class="upload-opt-icon">📷</div>
                <div class="upload-opt-label">من الكاميرا</div>
                <div class="upload-opt-sub">التقط صورة للوثيقة</div>
            </div>
            <div class="upload-opt" onclick="document.getElementById('docFileInput').click()">
                <div class="upload-opt-icon">📁</div>
                <div class="upload-opt-label">من الملفات</div>
                <div class="upload-opt-sub">صورة أو PDF</div>
            </div>
        </div>
        <input type="file" id="docFileInput" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none" onchange="uploadDocFile(this)">
    </div>
</div>

<!-- ─── Modal: الكاميرا ───────────────────────────────────────────── -->
<div class="modal-overlay camera-modal" id="cameraModal">
    <div class="modal-box">
        <button class="modal-close" onclick="stopCamera()">✕</button>
        <div class="modal-title" id="cameraTitle">📷 التقاط صورة</div>
        <div id="cameraViewWrap">
            <video id="cameraVideo" autoplay playsinline muted></video>
            <div class="camera-controls">
                <button class="btn-capture" onclick="capturePhoto()">📸 التقاط</button>
                <button class="btn-cancel-camera" onclick="stopCamera()">إلغاء</button>
            </div>
        </div>
        <div id="previewWrap" style="display:none">
            <img id="capturedPreview" src="" alt="">
            <div class="preview-actions">
                <button class="btn-retake" onclick="retakePhoto()">↺ إعادة</button>
                <button class="btn-use-photo" onclick="uploadCaptured()">✓ استخدام</button>
            </div>
        </div>
        <canvas id="cameraCanvas" style="display:none"></canvas>
    </div>
</div>

<!-- ─── Lightbox ──────────────────────────────────────────────────── -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox(event)">
    <div class="lightbox-content" id="lightboxContent"></div>
    <button class="lightbox-close" onclick="closeLightboxBtn()">✕ إغلاق</button>
    <button class="lightbox-nav" id="lightboxPrev" onclick="lightboxNav(-1)" style="display:none">›</button>
    <button class="lightbox-nav" id="lightboxNext" onclick="lightboxNav(1)" style="display:none">‹</button>
    <div class="lightbox-counter" id="lightboxCounter" style="display:none"></div>
</div>

<script>
const SITE_URL  = '<?= SITE_URL ?>';
const EMP_ID    = <?= $empId ?>;
let   csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// ── Toast ──────────────────────────────────────────────────────────
function showToast(msg, type = 'success', ms = 2800) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.className = 'toast', ms);
}

// ── CSRF helper ───────────────────────────────────────────────────
async function apiFetch(url, body) {
    body.append('csrf_token', csrfToken);
    const res  = await fetch(url, { method: 'POST', body });
    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;
    return data;
}

// ── Modals ────────────────────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openModal(id)  { document.getElementById(id).classList.add('open'); }

// ── Group counter ─────────────────────────────────────────────────
function updateGroupCounter() {
    const n = document.querySelectorAll('#groupsContainer .group-card').length;
    document.getElementById('groupCounter').textContent = n + '/10 مجموعات';
    document.getElementById('addGroupBtn').disabled = (n >= 10);
}

// ─── Days Badge ───────────────────────────────────────────────────
function daysClass(d) {
    if (d < 0)  return 'badge-expired';
    if (d <= 10) return 'badge-critical';
    if (d <= 30) return 'badge-warning';
    if (d <= 90) return 'badge-caution';
    return 'badge-ok';
}
function daysLabel(d) { return d >= 0 ? d + ' يوم' : 'منتهية'; }

// ─── Add Group ────────────────────────────────────────────────────
async function addGroup() {
    const btn = document.getElementById('addGroupBtn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'add_group');
    fd.append('employee_id', EMP_ID);
    const data = await apiFetch(SITE_URL + '/api/profile-action.php', fd);
    if (!data.success) { showToast(data.message, 'error'); btn.disabled = false; return; }
    appendGroupCard(data.group);
    updateGroupCounter();
    showToast('تمت إضافة المجموعة');
}

function appendGroupCard(g) {
    const bClass = daysClass(g.days_left);
    const bLabel = daysLabel(g.days_left);
    const html = `
    <div class="group-card" data-gid="${g.id}">
        <div class="group-header">
            <input class="group-name-input" placeholder="اسم المجموعة (اختياري)"
                   value="${escHtml(g.group_name)}"
                   onblur="saveGroup(${g.id}, this.value, this.closest('.group-card').querySelector('.group-expiry-input').value)"
                   maxlength="200">
            <input type="date" class="group-expiry-input" value="${escHtml(g.expiry_date)}"
                   onchange="saveGroup(${g.id}, this.closest('.group-card').querySelector('.group-name-input').value, this.value)">
            <span class="days-badge ${bClass}" data-badge="${g.id}">${bLabel}</span>
            <span class="save-indicator" id="saved_${g.id}">✓ حُفظ</span>
            <button class="btn-del-group" onclick="deleteGroup(${g.id}, ${EMP_ID})">🗑️ حذف</button>
        </div>
        <div class="files-grid" id="grid_${g.id}"></div>
        <div style="display:flex;align-items:center;gap:10px">
            <button class="btn-add-doc" onclick="openDocUpload(${g.id})">＋ إضافة وثيقة</button>
            <span class="files-count" id="cnt_${g.id}">0/10 ملفات</span>
        </div>
    </div>`;
    document.getElementById('groupsContainer').insertAdjacentHTML('beforeend', html);
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

// ─── Save Group ───────────────────────────────────────────────────
async function saveGroup(groupId, name, expiry) {
    if (!expiry) return;
    const fd = new FormData();
    fd.append('action', 'save_group');
    fd.append('group_id', groupId);
    fd.append('employee_id', EMP_ID);
    fd.append('group_name', name);
    fd.append('expiry_date', expiry);
    const data = await apiFetch(SITE_URL + '/api/profile-action.php', fd);
    if (!data.success) { showToast(data.message, 'error'); return; }
    // تحديث badge
    const badge = document.querySelector(`[data-badge="${groupId}"]`);
    if (badge) {
        badge.textContent = daysLabel(data.days_left);
        badge.className   = 'days-badge ' + daysClass(data.days_left);
    }
    const ind = document.getElementById('saved_' + groupId);
    if (ind) { ind.classList.add('show'); setTimeout(() => ind.classList.remove('show'), 2000); }
}

// ─── Delete Group ─────────────────────────────────────────────────
async function deleteGroup(groupId, empId) {
    if (!confirm('حذف هذه المجموعة وجميع وثائقها؟ لا يمكن التراجع.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_group');
    fd.append('group_id', groupId);
    fd.append('employee_id', empId);
    const data = await apiFetch(SITE_URL + '/api/profile-action.php', fd);
    if (!data.success) { showToast(data.message, 'error'); return; }
    document.querySelector(`[data-gid="${groupId}"]`)?.remove();
    updateGroupCounter();
    showToast('تم حذف المجموعة');
}

// ─── Delete Document ──────────────────────────────────────────────
async function deleteDoc(evt, docId, empId, groupId) {
    evt.stopPropagation();
    if (!confirm('حذف هذه الوثيقة؟')) return;
    const fd = new FormData();
    fd.append('action', 'delete_document');
    fd.append('doc_id', docId);
    fd.append('employee_id', empId);
    const data = await apiFetch(SITE_URL + '/api/profile-action.php', fd);
    if (!data.success) { showToast(data.message, 'error'); return; }
    document.querySelector(`[data-fid="${docId}"]`)?.remove();
    updateDocCount(groupId);
    showToast('تم حذف الوثيقة');
}

function updateDocCount(groupId) {
    const cnt  = document.querySelectorAll(`#grid_${groupId} .file-thumb`).length;
    const span = document.getElementById(`cnt_${groupId}`);
    if (span) span.textContent = cnt + '/10 ملفات';
    const addBtn = document.querySelector(`#grid_${groupId}`)?.nextElementSibling?.querySelector('.btn-add-doc');
    if (addBtn) addBtn.disabled = (cnt >= 10);
}

// ─── Photo Upload ─────────────────────────────────────────────────
function openPhotoUpload() { openModal('photoUploadModal'); }

async function uploadPhotoFile(input) {
    closeModal('photoUploadModal');
    if (!input.files[0]) return;
    const fd = new FormData();
    fd.append('action', 'photo');
    fd.append('employee_id', EMP_ID);
    fd.append('file', input.files[0]);
    showToast('جار الرفع…', 'success', 60000);
    const data = await apiFetch(SITE_URL + '/api/upload-profile.php', fd);
    input.value = '';
    if (!data.success) { showToast(data.message, 'error'); return; }
    refreshPhoto(data.path);
    showToast('تم تحديث الصورة الشخصية ✓');
}

async function deletePhoto() {
    if (!confirm('حذف الصورة الشخصية؟')) return;
    const fd = new FormData();
    fd.append('action', 'delete_photo');
    fd.append('employee_id', EMP_ID);
    const data = await apiFetch(SITE_URL + '/api/profile-action.php', fd);
    if (!data.success) { showToast(data.message, 'error'); return; }
    const img = document.getElementById('profilePhotoImg');
    if (img) {
        const init = '<?= htmlspecialchars($initials) ?>';
        img.outerHTML = `<div class="photo-placeholder" id="profilePhotoPlaceholder">${init}</div>`;
    }
    document.getElementById('photoDelBtn')?.remove();
    showToast('تم حذف الصورة');
}

function refreshPhoto(relPath) {
    const url = SITE_URL + '/api/serve-file.php?f=' + encodeURIComponent(relPath) + '&_t=' + Date.now();
    let img = document.getElementById('profilePhotoImg');
    if (img) {
        img.src = url;
    } else {
        const ph = document.getElementById('profilePhotoPlaceholder');
        if (ph) ph.outerHTML = `<img src="${url}" class="profile-avatar" id="profilePhotoImg" alt="">`;
    }
    // show delete button if not present
    if (!document.getElementById('photoDelBtn')) {
        const overlay = document.querySelector('.photo-overlay');
        overlay.insertAdjacentHTML('beforeend', `<button class="photo-btn photo-btn-del" id="photoDelBtn" onclick="deletePhoto()" title="حذف الصورة">🗑️</button>`);
    }
}

// ─── Document Upload ──────────────────────────────────────────────
let currentGroupId = null;
function openDocUpload(groupId) {
    currentGroupId = groupId;
    openModal('docUploadModal');
}

async function uploadDocFile(input) {
    closeModal('docUploadModal');
    if (!input.files[0] || !currentGroupId) return;
    const fd = new FormData();
    fd.append('action', 'document');
    fd.append('employee_id', EMP_ID);
    fd.append('group_id', currentGroupId);
    fd.append('file', input.files[0]);
    showToast('جار الرفع…', 'success', 60000);
    const data = await apiFetch(SITE_URL + '/api/upload-profile.php', fd);
    input.value = '';
    if (!data.success) { showToast(data.message, 'error'); return; }
    appendFileThumb(currentGroupId, data.id, data.path, data.type);
    updateDocCount(currentGroupId);
    showToast('تم رفع الوثيقة ✓');
}

function appendFileThumb(groupId, fileId, relPath, type) {
    const url  = SITE_URL + '/api/serve-file.php?f=' + encodeURIComponent(relPath);
    let inner  = '';
    if (type === 'image') {
        inner = `<img src="${url}" alt="" loading="lazy">`;
    } else {
        inner = `<div class="file-thumb-pdf"><svg width="28" height="28" viewBox="0 0 24 24" fill="#DC2626"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg><span>PDF</span></div>`;
    }
    const html = `<div class="file-thumb" data-fid="${fileId}" data-url="${url}" data-type="${type}" onclick="openLightbox(${groupId}, ${fileId})">${inner}<button class="thumb-del" onclick="deleteDoc(event,${fileId},${EMP_ID},${groupId})" title="حذف">✕</button></div>`;
    document.getElementById('grid_' + groupId).insertAdjacentHTML('beforeend', html);
}

// ─── Camera ───────────────────────────────────────────────────────
let cameraStream   = null;
let cameraMode     = null; // 'photo' or 'document'
let capturedBlob   = null;

async function openCamera(mode) {
    cameraMode = mode;
    closeModal(mode === 'photo' ? 'photoUploadModal' : 'docUploadModal');
    document.getElementById('cameraTitle').textContent = mode === 'photo' ? '📷 صورة البروفايل' : '📷 التقاط وثيقة';
    document.getElementById('cameraViewWrap').style.display = '';
    document.getElementById('previewWrap').style.display    = 'none';
    openModal('cameraModal');
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 960 } } });
        document.getElementById('cameraVideo').srcObject = cameraStream;
    } catch(e) {
        showToast('تعذّر فتح الكاميرا: ' + e.message, 'error');
        stopCamera();
    }
}

function capturePhoto() {
    const video  = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(blob => {
        capturedBlob = blob;
        const previewUrl = URL.createObjectURL(blob);
        document.getElementById('capturedPreview').src = previewUrl;
        document.getElementById('cameraViewWrap').style.display = 'none';
        document.getElementById('previewWrap').style.display    = '';
        if (cameraStream) cameraStream.getTracks().forEach(t => t.stop());
    }, 'image/jpeg', 0.92);
}

function retakePhoto() {
    capturedBlob = null;
    document.getElementById('previewWrap').style.display    = 'none';
    document.getElementById('cameraViewWrap').style.display = '';
    openCamera(cameraMode);
}

async function uploadCaptured() {
    if (!capturedBlob) return;
    closeModal('cameraModal');
    const filename = 'capture_' + Date.now() + '.jpg';
    if (cameraMode === 'photo') {
        const fd = new FormData();
        fd.append('action', 'photo');
        fd.append('employee_id', EMP_ID);
        fd.append('file', capturedBlob, filename);
        showToast('جار الرفع…', 'success', 60000);
        const data = await apiFetch(SITE_URL + '/api/upload-profile.php', fd);
        if (!data.success) { showToast(data.message, 'error'); return; }
        refreshPhoto(data.path);
        showToast('تم تحديث الصورة الشخصية ✓');
    } else {
        if (!currentGroupId) return;
        const fd = new FormData();
        fd.append('action', 'document');
        fd.append('employee_id', EMP_ID);
        fd.append('group_id', currentGroupId);
        fd.append('file', capturedBlob, filename);
        showToast('جار الرفع…', 'success', 60000);
        const data = await apiFetch(SITE_URL + '/api/upload-profile.php', fd);
        if (!data.success) { showToast(data.message, 'error'); return; }
        appendFileThumb(currentGroupId, data.id, data.path, data.type);
        updateDocCount(currentGroupId);
        showToast('تم رفع الوثيقة ✓');
    }
    capturedBlob = null;
}

function stopCamera() {
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
    closeModal('cameraModal');
}

// ─── Lightbox ────────────────────────────────────────────────────
let lbFiles = []; // [{url, type}]
let lbIdx   = 0;

function openLightbox(groupId, fileId) {
    const grid  = document.getElementById('grid_' + groupId);
    const thumbs = grid.querySelectorAll('.file-thumb');
    lbFiles = [];
    let startIdx = 0;
    thumbs.forEach((th, i) => {
        lbFiles.push({ url: th.dataset.url, type: th.dataset.type });
        if (parseInt(th.dataset.fid) === fileId) startIdx = i;
    });
    lbIdx = startIdx;
    showLightboxItem();
    document.getElementById('lightbox').classList.add('open');
}

function showLightboxItem() {
    const f   = lbFiles[lbIdx];
    const cnt = document.getElementById('lightboxContent');
    if (f.type === 'image') {
        cnt.innerHTML = `<img src="${f.url}" alt="">`;
    } else {
        cnt.innerHTML = `<iframe src="${f.url}" title="PDF" allowfullscreen></iframe>`;
    }
    const n = lbFiles.length;
    document.getElementById('lightboxCounter').textContent = (lbIdx + 1) + ' / ' + n;
    document.getElementById('lightboxCounter').style.display = n > 1 ? '' : 'none';
    document.getElementById('lightboxPrev').style.display = n > 1 ? '' : 'none';
    document.getElementById('lightboxNext').style.display = n > 1 ? '' : 'none';
}

function lightboxNav(dir) {
    lbIdx = (lbIdx + dir + lbFiles.length) % lbFiles.length;
    showLightboxItem();
}

function closeLightbox(evt) {
    if (evt.target === document.getElementById('lightbox')) closeLightboxBtn();
}
function closeLightboxBtn() {
    document.getElementById('lightbox').classList.remove('open');
    document.getElementById('lightboxContent').innerHTML = '';
    lbFiles = [];
}

// keyboard nav
document.addEventListener('keydown', e => {
    if (document.getElementById('lightbox').classList.contains('open')) {
        if (e.key === 'Escape') closeLightboxBtn();
        if (e.key === 'ArrowLeft')  lightboxNav(1);
        if (e.key === 'ArrowRight') lightboxNav(-1);
    }
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
            if (m.id === 'cameraModal') stopCamera(); else m.classList.remove('open');
        });
    }
});
</script>
