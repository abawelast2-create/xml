<?php
// =============================================================
// employee/my-documents.php — وثائقي (قراءة فقط لِلموظف)
// =============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ─── التحقق من التوكن ───────────────────────────────────────
$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(403);
    echo '<div style="font-family:Tajawal,sans-serif;text-align:center;padding:60px;color:#666">رابط غير صالح</div>';
    exit;
}

$employee = getEmployeeByToken($token);
if (!$employee || !$employee['is_active']) {
    http_response_code(403);
    echo '<div style="font-family:Tajawal,sans-serif;text-align:center;padding:60px;color:#666">الحساب غير موجود أو مُعطَّل</div>';
    exit;
}
$empId = (int)$employee['id'];

// ─── جلب المجموعات والملفات ─────────────────────────────────
$groups = [];
try {
    $stmt = db()->prepare("
        SELECT g.id, g.group_name, g.expiry_date,
               DATEDIFF(g.expiry_date, CURDATE()) AS days_left
        FROM emp_document_groups g
        WHERE g.employee_id = ?
        ORDER BY g.expiry_date ASC
    ");
    $stmt->execute([$empId]);
    $rawGroups = $stmt->fetchAll();

    foreach ($rawGroups as $g) {
        $fStmt = db()->prepare("SELECT id, file_path, file_type, original_name FROM emp_document_files WHERE group_id = ? ORDER BY sort_order, id");
        $fStmt->execute([(int)$g['id']]);
        $g['files'] = $fStmt->fetchAll();
        $groups[] = $g;
    }
} catch (PDOException $e) {
    // الجداول غير موجودة بعد — اتركها فارغة
}

function myDocsBadgeClass(int $days): string {
    if ($days < 0)   return 'badge-expired';
    if ($days <= 10) return 'badge-critical';
    if ($days <= 30) return 'badge-warning';
    if ($days <= 90) return 'badge-caution';
    return 'badge-ok';
}

$profilePhotoUrl = !empty($employee['profile_photo'])
    ? SITE_URL . '/api/serve-file.php?f=' . urlencode($employee['profile_photo']) . '&t=' . urlencode($token)
    : '';
$initials = mb_substr($employee['name'] ?? '?', 0, 1);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>وثائقي — <?= htmlspecialchars($employee['name']) ?></title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/fonts/tajawal.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#F97316;--primary-d:#C2410C;--primary-l:#FFF7ED;
    --surface:#F8FAFC;--surface2:#F1F5F9;--surface3:#E2E8F0;
    --border:#E2E8F0;--text:#1E293B;--text2:#475569;--text3:#94A3B8;
    --red:#DC2626;--yellow:#D97706;--green:#059669;
    --radius:14px;--shadow:0 1px 4px rgba(0,0,0,.08);
}
body{font-family:'Tajawal',sans-serif;background:var(--surface);color:var(--text);min-height:100vh;padding-bottom:40px}

/* Header */
.page-header{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-d) 100%);padding:32px 20px 24px;color:#fff;text-align:center}
.page-header-title{font-size:1rem;font-weight:700;opacity:.9;margin-bottom:20px}

/* Profile Card */
.profile-card{max-width:480px;margin:0 auto 0;background:#fff;border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
.profile-hero{display:flex;flex-direction:column;align-items:center;padding:28px 20px 20px;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-d) 100%)}
.profile-photo{width:88px;height:88px;border-radius:50%;border:3px solid rgba(255,255,255,.5);object-fit:cover;margin-bottom:14px;background:rgba(255,255,255,.2)}
.profile-photo-init{width:88px;height:88px;border-radius:50%;border:3px solid rgba(255,255,255,.5);background:rgba(255,255,255,.25);color:#fff;display:flex;align-items:center;justify-content:center;font-size:2.2rem;font-weight:800;margin-bottom:14px}
.profile-name{color:#fff;font-size:1.2rem;font-weight:800;margin-bottom:4px;text-align:center}
.profile-sub{color:rgba(255,255,255,.8);font-size:.82rem;text-align:center}

/* Main content */
.content-wrap{max-width:480px;margin:0 auto;padding:20px 16px}
.section-title{font-size:.8rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)}

/* Group cards */
.group-card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 20px;margin-bottom:14px;border:1.5px solid var(--border)}
.group-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.group-name{font-weight:800;font-size:.95rem;color:var(--text)}
.group-expiry{font-size:.72rem;color:var(--text3);margin-top:2px}

/* Badges */
.grp-badge{padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:700;white-space:nowrap}
.badge-expired{background:#F1F5F9;color:#64748B}
.badge-critical{background:#FEE2E2;color:#991B1B;animation:pulse-red 1.4s ease-in-out infinite}
.badge-warning{background:#FEE2E2;color:#B91C1C}
.badge-caution{background:#FEF3C7;color:#92400E}
.badge-ok{background:#D1FAE5;color:#065F46}
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 2px rgba(220,38,38,.4)}50%{box-shadow:0 0 0 6px rgba(220,38,38,.05)}}

/* File grid */
.file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(70px,1fr));gap:8px}
.file-thumb{border-radius:10px;overflow:hidden;border:1.5px solid var(--border);background:var(--surface3);aspect-ratio:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:border-color .2s}
.file-thumb:hover{border-color:var(--primary)}
.file-thumb img{width:100%;height:100%;object-fit:cover}
.file-thumb-pdf{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;height:100%;padding:8px;color:var(--red)}
.file-thumb-pdf span{font-size:.6rem;color:var(--text3);word-break:break-all;text-align:center}
.no-files{color:var(--text3);font-size:.8rem;font-style:italic}
.no-groups{text-align:center;padding:40px 20px;color:var(--text3)}
.no-groups-icon{font-size:2.5rem;margin-bottom:12px}

/* Lightbox */
.lb{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .2s}
.lb.open{opacity:1;pointer-events:all}
.lb-img{max-width:92vw;max-height:88vh;border-radius:10px;object-fit:contain}
.lb-iframe{width:88vw;height:85vh;border:none;border-radius:10px}
.lb-close{position:fixed;top:16px;left:20px;background:rgba(255,255,255,.15);border:none;border-radius:10px;padding:10px 18px;color:#fff;font-size:.9rem;cursor:pointer;font-family:'Tajawal',sans-serif}
.lb-close:hover{background:rgba(255,255,255,.3)}
</style>
</head>
<body>

<div class="profile-hero" style="background:linear-gradient(135deg,var(--primary) 0%,var(--primary-d) 100%)">
    <?php if ($profilePhotoUrl): ?>
        <img src="<?= htmlspecialchars($profilePhotoUrl) ?>" class="profile-photo" alt="صورة الموظف">
    <?php else: ?>
        <div class="profile-photo-init"><?= htmlspecialchars($initials) ?></div>
    <?php endif; ?>
    <div class="profile-name"><?= htmlspecialchars($employee['name']) ?></div>
    <div class="profile-sub"><?= htmlspecialchars($employee['job_title'] ?? '') ?></div>
</div>

<div class="content-wrap">

<?php if (empty($groups)): ?>
    <div class="no-groups">
        <div class="no-groups-icon">📂</div>
        <div style="font-weight:700;font-size:.95rem;color:var(--text2);margin-bottom:6px">لا توجد وثائق مسجّلة</div>
        <div style="font-size:.82rem">لم يقم المدير بإضافة أي مجموعات وثائق حتى الآن</div>
    </div>
<?php else: ?>
    <div class="section-title">وثائقي ومستنداتي</div>
    <?php foreach ($groups as $g):
        $days = (int)$g['days_left'];
        $bc   = myDocsBadgeClass($days);
        $bl   = $days < 0 ? 'منتهية' : ($days . ' يوم');
    ?>
    <div class="group-card">
        <div class="group-header">
            <div>
                <div class="group-name"><?= $g['group_name'] ? htmlspecialchars($g['group_name']) : '<span style="color:var(--text3);font-style:italic">مجموعة بدون اسم</span>' ?></div>
                <div class="group-expiry">انتهاء: <?= htmlspecialchars($g['expiry_date']) ?></div>
            </div>
            <span class="grp-badge <?= $bc ?>"><?= htmlspecialchars($bl) ?></span>
        </div>
        <?php if (empty($g['files'])): ?>
            <div class="no-files">لا توجد ملفات في هذه المجموعة</div>
        <?php else: ?>
            <div class="file-grid">
            <?php foreach ($g['files'] as $f):
                $fUrl = SITE_URL . '/api/serve-file.php?f=' . urlencode($f['file_path']) . '&t=' . urlencode($token);
            ?>
                <?php if ($f['file_type'] === 'image'): ?>
                    <div class="file-thumb" onclick="openLb('<?= htmlspecialchars($fUrl) ?>','image')">
                        <img src="<?= htmlspecialchars($fUrl) ?>" loading="lazy" alt="">
                    </div>
                <?php else: ?>
                    <div class="file-thumb" onclick="openLb('<?= htmlspecialchars($fUrl) ?>','pdf')">
                        <div class="file-thumb-pdf">
                            <svg width="30" height="30" viewBox="0 0 24 24" fill="#DC2626"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg>
                            <span><?= htmlspecialchars(mb_substr($f['original_name'] ?? 'PDF', 0, 12)) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- Lightbox -->
<div class="lb" id="lb" onclick="if(event.target===this)closeLb()">
    <div id="lbContent"></div>
    <button class="lb-close" onclick="closeLb()">✕ إغلاق</button>
</div>

<script>
function openLb(url, type) {
    document.getElementById('lbContent').innerHTML = type === 'image'
        ? '<img class="lb-img" src="' + url + '" alt="">'
        : '<iframe class="lb-iframe" src="' + url + '" title="PDF"></iframe>';
    document.getElementById('lb').classList.add('open');
}
function closeLb() {
    document.getElementById('lb').classList.remove('open');
    document.getElementById('lbContent').innerHTML = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLb(); });
</script>
</body>
</html>
