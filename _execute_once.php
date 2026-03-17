<?php
// =============================================================
// _execute_once.php — تنفيذ مرة واحدة: فرع موبار + موظفيه
// هذا الملف يحذف نفسه تلقائياً بعد التنفيذ الناجح
// =============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$errors  = [];
$results = [];

// ══════════════════════════════════════════════════════════════
// الخطوة 1: إضافة الفرع
// ══════════════════════════════════════════════════════════════
$branchName = 'الدهانات والبوية + موبار';
$branchId   = null;

try {
    $check = db()->prepare("SELECT id FROM branches WHERE name = ?");
    $check->execute([$branchName]);
    $existing = $check->fetch();

    if ($existing) {
        $branchId = (int)$existing['id'];
        $results[] = ['type' => 'warn', 'msg' => "الفرع موجود مسبقاً (ID: {$branchId}) — تم تخطي الإضافة"];
    } else {
        $stmt = db()->prepare("INSERT INTO branches
            (name, latitude, longitude, geofence_radius,
             work_start_time, work_end_time,
             check_in_start_time, check_in_end_time,
             check_out_start_time, check_out_end_time,
             checkout_show_before, allow_overtime,
             overtime_start_after, overtime_min_duration, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
        $stmt->execute([
            $branchName,
            24.569472108456402,  // latitude
            46.61440423213129,   // longitude
            25,                  // geofence_radius (متر)
            '12:00', '00:00',    // work_start / work_end
            '11:30', '13:00',    // check_in_start / check_in_end
            '23:45', '00:30',    // check_out_start / check_out_end
            15,                  // checkout_show_before
            1,                   // allow_overtime
            30,                  // overtime_start_after
            30,                  // overtime_min_duration
        ]);
        $branchId = (int)db()->lastInsertId();
        $results[] = ['type' => 'ok',  'msg' => "✅ تم إضافة الفرع «{$branchName}» — ID: {$branchId}"];
    }
} catch (PDOException $e) {
    $errors[] = "خطأ إضافة الفرع: " . $e->getMessage();
}

// ══════════════════════════════════════════════════════════════
// الخطوة 2: إضافة الموظفين
// ══════════════════════════════════════════════════════════════
// إذا لم نحصل على branchId من الخطوة الأولى، ابحث عنه
if (!$branchId) {
    try {
        $row = db()->query("SELECT id FROM branches WHERE name LIKE '%موبار%' AND is_active=1 LIMIT 1")->fetch();
        $branchId = $row ? (int)$row['id'] : null;
    } catch (PDOException $e) {}
}

if (!$branchId) {
    $errors[] = "لم يُعثر على فرع موبار — تعذّر إضافة الموظفين";
} else {
    // [الاسم، الهاتف، PIN (آخر 4 أرقام)]
    $employees = [
        ['هيثم',       '966551400542', '0542'],
        ['معتز',       '9660475938',   '5938'],
        ['صهيب',       '966558109975', '9975'],
        ['خيري',       '966535115401', '5401'],
        ['عبدو بوية',  '966549601820', '1820'],
        ['احمد',       '966558602267', '2267'],
        ['حسن',        '2614655050',   '5050'],
        ['ابو حازم',   '966560077593', '7593'],
        ['ابو يحيى',   '966500047631', '7631'],
        ['حمادة',      '966560671407', '1407'],
        ['ابراهيم',    '966508873427', '3427'],
    ];

    foreach ($employees as [$name, $phone, $pin]) {
        try {
            // تحقق من تكرار الـ PIN
            $chkPin = db()->prepare("SELECT id FROM employees WHERE pin = ?");
            $chkPin->execute([$pin]);
            if ($chkPin->fetch()) {
                $pinAlt = $pin . '1';
                $chkPin2 = db()->prepare("SELECT id FROM employees WHERE pin = ?");
                $chkPin2->execute([$pinAlt]);
                if ($chkPin2->fetch()) {
                    $results[] = ['type' => 'warn', 'msg' => "⚠️ {$name}: PIN «{$pin}» مكرر حتى بعد التعديل — تم التخطي"];
                    continue;
                }
                $pin = $pinAlt;
            }

            // توليد unique_token فريد
            do {
                $token = bin2hex(random_bytes(16));
                $chkTok = db()->prepare("SELECT id FROM employees WHERE unique_token = ?");
                $chkTok->execute([$token]);
            } while ($chkTok->fetch());

            $ins = db()->prepare("INSERT INTO employees (name, job_title, pin, phone, branch_id, unique_token) VALUES (?,?,?,?,?,?)");
            $ins->execute([$name, 'موظف', $pin, $phone, $branchId, $token]);
            $newId = (int)db()->lastInsertId();
            $results[] = ['type' => 'ok', 'msg' => "✅ {$name} — ID: {$newId} | PIN: {$pin}"];

        } catch (PDOException $e) {
            $results[] = ['type' => 'err', 'msg' => "❌ {$name}: " . $e->getMessage()];
        }
    }
}

// ══════════════════════════════════════════════════════════════
// الخطوة 3: حذف الملفات الثلاثة عند النجاح
// ══════════════════════════════════════════════════════════════
$deleted = [];
if (empty($errors)) {
    $toDelete = [
        __DIR__ . '/add_branch_mohbar.php',
        __DIR__ . '/add_mobbar_employees.php',
        __FILE__,   // هذا الملف نفسه
    ];
    foreach ($toDelete as $f) {
        if (file_exists($f) && @unlink($f)) {
            $deleted[] = basename($f);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تنفيذ مرة واحدة</title>
<style>
  body{font-family:'Segoe UI',Tahoma,sans-serif;background:#0F172A;color:#E2E8F0;padding:30px}
  .box{max-width:680px;margin:0 auto}
  h2{color:#10B981;margin-bottom:20px}
  .ok{color:#34D399}.warn{color:#FCD34D}.err{color:#F87171}
  ul{list-style:none;padding:0;margin:0 0 20px}
  ul li{padding:7px 12px;border-bottom:1px solid #1E293B;font-size:.9rem}
  .section{background:#1E293B;border-radius:10px;padding:16px 20px;margin-bottom:16px}
  .section-title{font-size:.75rem;font-weight:700;color:#94A3B8;letter-spacing:.5px;margin-bottom:10px;text-transform:uppercase}
  .error-box{background:#450A0A;border:1.5px solid #EF4444;border-radius:10px;padding:16px 20px;margin-bottom:16px}
  .deleted{color:#6EE7B7;font-size:.78rem;margin-top:4px}
  a{color:#818CF8;text-decoration:none}a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="box">
  <h2>🚀 نتائج التنفيذ</h2>

  <?php if (!empty($errors)): ?>
  <div class="error-box">
    <div class="section-title" style="color:#FCA5A5">أخطاء</div>
    <?php foreach ($errors as $e): ?>
      <div class="err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-title">السجلات</div>
    <ul>
    <?php foreach ($results as $r): ?>
      <li class="<?= $r['type'] === 'ok' ? 'ok' : ($r['type'] === 'warn' ? 'warn' : 'err') ?>"><?= htmlspecialchars($r['msg']) ?></li>
    <?php endforeach; ?>
    </ul>
  </div>

  <?php if (!empty($deleted)): ?>
  <div class="section">
    <div class="section-title">ملفات محذوفة تلقائياً</div>
    <?php foreach ($deleted as $f): ?>
      <div class="deleted">🗑️ <?= htmlspecialchars($f) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (empty($errors)): ?>
    <p style="color:#6EE7B7;font-weight:700;margin-top:8px">✅ اكتمل التنفيذ — جميع الملفات المؤقتة حُذفت من السيرفر.</p>
  <?php else: ?>
    <p style="color:#FCA5A5;font-weight:700;margin-top:8px">⚠️ يوجد أخطاء — الملفات لم تُحذف. راجع السجلات أعلاه.</p>
  <?php endif; ?>

  <p style="margin-top:16px"><a href="admin/branches.php">← عرض الفروع</a> &nbsp;|&nbsp; <a href="admin/employees.php">← عرض الموظفين</a></p>
</div>
</body>
</html>
