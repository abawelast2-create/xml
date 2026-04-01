<?php
// =============================================================
// admin/login.php - تسجيل دخول المدير
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (isAdminLoggedIn()) {
    header('Location: dashboard.php'); exit;
}

$error   = '';
$message = '';
$showCopyright = false;

// =================== حماية Brute Force (DB-based) ===================
$maxAttempts  = 5;
$lockDuration = 600; // 10 دقائق
$clientIP     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// تنظيف المحاولات المنتهية + حساب المحاولات الحالية
try {
    db()->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")->execute([$lockDuration]);
    $stmtCount = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmtCount->execute([$clientIP, $lockDuration]);
    $attempts = (int)$stmtCount->fetchColumn();
} catch (Exception $e) {
    // إذا لم يكن الجدول موجوداً بعد — استخدام Session كوضع احتياطي
    $attempts = $_SESSION['login_attempts'] ?? 0;
}
$isLocked = ($attempts >= $maxAttempts);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isLocked) {
        // حساب الوقت المتبقي
        try {
            $stmtLast = db()->prepare("SELECT MAX(attempted_at) FROM login_attempts WHERE ip_address = ?");
            $stmtLast->execute([$clientIP]);
            $lastAttemptTime = strtotime($stmtLast->fetchColumn());
            $remaining = $lockDuration - (time() - $lastAttemptTime);
        } catch (Exception $e) {
            $remaining = $lockDuration;
        }
        $remainMin = ceil($remaining / 60);
        $error = "عنوان IP مقفل مؤقتاً بسبب محاولات دخول متكررة. حاول مجدداً بعد {$remainMin} دقيقة.";
    } elseif (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'طلب غير صالح. حاول مرة أخرى.';
    } else {
        // Easter egg: حقوق الملكية
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
        if ($u === 'حقوق' && $p === 'الملكية') {
            $showCopyright = true;
        }
        $result = adminLogin($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['success']) {
            // مسح المحاولات عند النجاح
            try {
                db()->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$clientIP]);
            } catch (Exception $e) {}
            unset($_SESSION['login_attempts'], $_SESSION['last_attempt_ts']);
            auditLog('login', 'تسجيل دخول ناجح من IP: ' . $clientIP);
            header('Location: dashboard.php'); exit;
        } else {
            // تسجيل المحاولة الفاشلة
            try {
                db()->prepare("INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())")
                    ->execute([$clientIP, sanitize($_POST['username'] ?? '')]);
                $attempts++;
            } catch (Exception $e) {
                // fallback session
                $_SESSION['login_attempts'] = ($attempts ?? 0) + 1;
                $attempts = $_SESSION['login_attempts'];
            }
            $remaining_attempts = $maxAttempts - $attempts;
            $error = $result['message'];
            if ($remaining_attempts > 0) {
                $error .= " (تبقى {$remaining_attempts} محاولة)";
            } else {
                $error = "تم تجاوز الحد المسموح. عنوان IP مقفل لمدة 10 دقائق.";
                $isLocked = true;
            }
        }
    }
}
$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول المدير - <?= SITE_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect x='2' y='2' width='22' height='22' rx='4' fill='%23F97316' opacity='.9'/%3E%3Crect x='16' y='16' width='22' height='22' rx='4' fill='%23EA580C'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Tajawal',sans-serif;
            background:#F8FAFC;
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;
            position:relative; overflow:hidden;
        }
        body::before {
            content:''; position:fixed; inset:0;
            background:
                radial-gradient(ellipse 700px 500px at 20% 10%, rgba(249,115,22,.08) 0%, transparent 60%),
                radial-gradient(ellipse 500px 400px at 80% 90%, rgba(234,88,12,.06) 0%, transparent 60%);
            pointer-events:none;
        }
        .login-wrap {
            display:flex; width:100%; max-width:880px;
            border-radius:20px; overflow:hidden;
            box-shadow:0 20px 50px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
            position:relative; z-index:1; border:1px solid #E2E8F0;
        }
        /* البانر */
        .login-banner {
            flex:1; display:none;
            background:linear-gradient(155deg,#F97316 0%,#EA580C 50%,#C2410C 100%);
            padding:48px 36px; align-items:center; justify-content:center;
            flex-direction:column; text-align:center; position:relative; overflow:hidden;
        }
        .login-banner::before {
            content:''; position:absolute; inset:0;
            background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .banner-logo { margin-bottom:24px; opacity:.95; }
        .banner-title { color:#fff; font-size:1.5rem; font-weight:800; line-height:1.4; margin-bottom:12px; }
        .banner-sub   { color:rgba(255,255,255,.8); font-size:.88rem; line-height:1.7; }
        .banner-dots  { display:flex; gap:8px; justify-content:center; margin-top:28px; }
        .banner-dots span { width:8px; height:8px; border-radius:50%; background:rgba(255,255,255,.3); }
        .banner-dots span:first-child { background:rgba(255,255,255,.8); width:24px; border-radius:4px; }
        @media (min-width:700px) { .login-banner { display:flex; } }

        /* النموذج */
        .login-card {
            background:#fff; padding:48px 40px;
            width:100%; max-width:420px; min-width:300px;
        }
        .brand { text-align:center; margin-bottom:36px; }
        .brand-logo-wrap {
            width:72px; height:72px; margin:0 auto 16px;
            display:flex; align-items:center; justify-content:center;
        }
        .brand-name { color:#1E293B; font-size:1.2rem; font-weight:800; }
        .brand-sub  { color:#94A3B8; font-size:.82rem; margin-top:4px; }

        .form-group { margin-bottom:20px; }
        label { display:block; color:#475569; font-size:.84rem; font-weight:600; margin-bottom:7px; }
        .input-wrap { position:relative; }
        .input-icon {
            position:absolute; right:14px; top:50%; transform:translateY(-50%);
            color:#CBD5E1; pointer-events:none; display:flex;
        }
        input[type=text],input[type=password] {
            width:100%; padding:11px 44px 11px 14px;
            background:#F8FAFC; border:1.5px solid #E2E8F0;
            border-radius:10px; color:#1E293B; font-size:.92rem;
            font-family:inherit; direction:ltr; text-align:right; outline:none;
            transition:border-color .2s, box-shadow .2s;
        }
        input:focus {
            border-color:#F97316; background:#fff;
            box-shadow:0 0 0 3px rgba(249,115,22,.1);
        }
        input::placeholder { color:#94A3B8; }

        .btn-login {
            width:100%; padding:13px;
            background:linear-gradient(135deg,#F97316,#EA580C);
            border:none; border-radius:12px;
            color:#fff; font-size:1rem; font-weight:700;
            font-family:inherit; cursor:pointer;
            transition:all .2s; margin-top:8px;
            box-shadow:0 4px 14px rgba(249,115,22,.3);
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .btn-login:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(249,115,22,.4); }
        .btn-login:active { transform:translateY(0); }

        .alert-error {
            background:#FEE2E2; color:#DC2626;
            border:1px solid #FECACA; border-radius:10px;
            padding:12px 16px; font-size:.875rem; margin-bottom:20px;
            display:flex; gap:8px; align-items:center;
        }
        .alert-locked {
            background:#FEF3C7; color:#D97706;
            border:1px solid #FDE68A; border-radius:10px;
            padding:12px 16px; font-size:.875rem; margin-bottom:20px;
            display:flex; gap:8px; align-items:center;
        }
        .footer-note {
            text-align:center; color:#CBD5E1; font-size:.74rem; margin-top:28px;
            display:flex; align-items:center; justify-content:center; gap:6px;
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <!-- بانر -->
    <div class="login-banner">
        <div class="banner-logo"><?= getLogoSvg(80, '#fff', 'rgba(255,255,255,.6)') ?></div>
        <div class="banner-title">نظام الحضور<br>والانصراف الذكي</div>
        <div class="banner-sub">تتبع وإدارة حضور موظفيك<br>بكل سهولة ودقة عالية</div>
        <div class="banner-dots"><span></span><span></span><span></span></div>
    </div>

    <!-- نموذج الدخول -->
    <div class="login-card">
        <div class="brand">
            <div class="brand-logo-wrap"><?= getLogoSvg(64) ?></div>
            <div class="brand-name"><?= SITE_NAME ?></div>
            <div class="brand-sub">لوحة تحكم المدير</div>
        </div>

        <?php if ($error): ?>
        <div class="<?= $isLocked ? 'alert-locked' : 'alert-error' ?>">
            <?= svgIcon($isLocked ? 'lock' : 'absent', 18) ?>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" <?= $isLocked ? 'style="opacity:.5;pointer-events:none"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="form-group">
                <label for="username">اسم المستخدم</label>
                <div class="input-wrap">
                    <span class="input-icon"><?= svgIcon('user', 18) ?></span>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="admin" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label for="password">كلمة المرور</label>
                <div class="input-wrap">
                    <span class="input-icon"><?= svgIcon('key', 18) ?></span>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#64748B">
                    <input type="checkbox" name="remember_me" value="1" style="width:16px;height:16px;accent-color:#F97316;cursor:pointer">
                    تذكرني (30 يوم)
                </label>
            </div>
            <button type="submit" class="btn-login">
                <span>تسجيل الدخول</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z"/></svg>
            </button>
        </form>

        <div class="footer-note">
            <?= getLogoSvg(14) ?>
            نظام الحضور والانصراف الإلكتروني
        </div>
    </div>
</div>

<?php if (!empty($showCopyright)): ?>
<!-- حقوق الملكية الفكرية — أنيميشن مبهر -->
<div id="copyrightOverlay">
  <canvas id="crParticles"></canvas>
  <div class="cr-content">

    <!-- المرحلة 1: الشعار يظهر مع توهج -->
    <div class="cr-stage cr-stage-1" id="crStage1">
      <div class="cr-logo-ring">
        <div class="cr-ring-outer"></div>
        <div class="cr-ring-inner"></div>
        <img src="<?= SITE_URL ?>/assets/images/loogo.png" class="cr-logo-img" alt="Logo">
      </div>
    </div>

    <!-- المرحلة 2: النص العربي -->
    <div class="cr-stage cr-stage-2" id="crStage2">
      <div class="cr-shield">⚖️</div>
      <div class="cr-title-ar">حقوق الملكية الفكرية</div>
      <div class="cr-divider"></div>
      <div class="cr-body-ar">
        جميع حقوق الملكية الفكرية لهذا النظام<br>
        محفوظة بشكل شخصي وحصري لصالح
      </div>
      <div class="cr-name-ar">السيد عبدالحكيم خلف المذهول</div>
      <div class="cr-warn-ar">⛔ يُمنع منعاً باتاً نسخ أو تعديل الكود</div>
    </div>

    <!-- المرحلة 3: النص الإنجليزي -->
    <div class="cr-stage cr-stage-3" id="crStage3">
      <div class="cr-shield">⚖️</div>
      <div class="cr-title-en">Intellectual Property Rights</div>
      <div class="cr-divider"></div>
      <div class="cr-body-en">
        All intellectual property rights of this system<br>
        are exclusively and personally reserved for
      </div>
      <div class="cr-name-en">Mr. Abdulhakeem Khalaf Al-Mathhool</div>
      <div class="cr-warn-en">⛔ Copying or modifying the code is strictly prohibited</div>
    </div>

    <!-- المرحلة 4: الشعار + الختم النهائي -->
    <div class="cr-stage cr-stage-4" id="crStage4">
      <img src="<?= SITE_URL ?>/assets/images/loogo.png" class="cr-final-logo" alt="Logo">
      <div class="cr-stamp">© <?= date('Y') ?></div>
      <div class="cr-final-name">Abdulhakeem Khalaf Al-Mathhool</div>
      <div class="cr-final-sub">All Rights Reserved — جميع الحقوق محفوظة</div>
      <div class="cr-countdown-wrap">
        العودة خلال <span id="crCountdown">12</span> ثوانٍ...
      </div>
    </div>

  </div>
</div>

<style>
#copyrightOverlay{
  position:fixed;inset:0;z-index:99999;
  background:#07090F;
  overflow:hidden;
}
#crParticles{
  position:absolute;inset:0;z-index:1;
}
.cr-content{
  position:relative;z-index:2;
  width:100%;height:100%;
  display:flex;align-items:center;justify-content:center;
}
.cr-stage{
  position:absolute;inset:0;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  opacity:0;padding:30px;text-align:center;
  pointer-events:none;
}

/* === المرحلة 1: الشعار === */
.cr-logo-ring{
  position:relative;width:160px;height:160px;
  display:flex;align-items:center;justify-content:center;
}
.cr-ring-outer{
  position:absolute;inset:-10px;border-radius:50%;
  border:2px solid transparent;
  border-top-color:#F97316;border-bottom-color:#3B82F6;
  animation:crSpin 3s linear infinite;
  box-shadow:0 0 40px rgba(249,115,22,.15),inset 0 0 40px rgba(59,130,246,.1);
}
.cr-ring-inner{
  position:absolute;inset:2px;border-radius:50%;
  border:1.5px solid transparent;
  border-left-color:#F97316;border-right-color:#3B82F6;
  animation:crSpin 2s linear infinite reverse;
}
.cr-logo-img{
  width:100px;height:100px;object-fit:contain;
  border-radius:50%;
  filter:drop-shadow(0 0 30px rgba(249,115,22,.4));
  animation:crPulseGlow 2s ease-in-out infinite;
}

/* === المرحلة 2: العربي === */
.cr-shield{font-size:3rem;margin-bottom:10px;animation:crBounceIn .6s ease}
.cr-title-ar{
  color:#F97316;font-size:1.4rem;font-weight:800;letter-spacing:2px;
  text-shadow:0 0 30px rgba(249,115,22,.4);
  margin-bottom:10px;
}
.cr-divider{
  width:200px;height:2px;margin:0 auto 16px;
  background:linear-gradient(90deg,transparent,#F97316,#3B82F6,transparent);
  border-radius:2px;
}
.cr-body-ar{
  color:#CBD5E1;font-size:1.05rem;line-height:2.2;font-weight:500;
  margin-bottom:8px;
}
.cr-name-ar{
  color:#F97316;font-size:1.6rem;font-weight:800;
  text-shadow:0 0 30px rgba(249,115,22,.35);
  margin:14px 0;padding:12px 30px;
  border:1.5px solid rgba(249,115,22,.25);border-radius:16px;
  background:rgba(249,115,22,.06);
  display:inline-block;
}
.cr-warn-ar{
  color:#F87171;font-size:.92rem;font-weight:700;
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);
  border-radius:12px;padding:12px 20px;margin-top:16px;
  display:inline-block;
  animation:crPulseWarn 2s ease-in-out infinite;
}

/* === المرحلة 3: الإنجليزي === */
.cr-title-en{
  color:#3B82F6;font-size:1.3rem;font-weight:800;letter-spacing:2px;
  text-shadow:0 0 30px rgba(59,130,246,.4);
  margin-bottom:10px;direction:ltr;
}
.cr-body-en{
  color:#94A3B8;font-size:1rem;line-height:2.2;font-weight:500;
  margin-bottom:8px;direction:ltr;
}
.cr-name-en{
  color:#3B82F6;font-size:1.4rem;font-weight:800;direction:ltr;
  text-shadow:0 0 30px rgba(59,130,246,.35);
  margin:14px 0;padding:12px 30px;
  border:1.5px solid rgba(59,130,246,.25);border-radius:16px;
  background:rgba(59,130,246,.06);
  display:inline-block;
}
.cr-warn-en{
  color:#F87171;font-size:.85rem;font-weight:600;direction:ltr;
  background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);
  border-radius:12px;padding:10px 18px;margin-top:16px;
  display:inline-block;
  animation:crPulseWarn 2s ease-in-out infinite;
}

/* === المرحلة 4: الختم النهائي === */
.cr-final-logo{
  width:90px;height:90px;object-fit:contain;border-radius:50%;
  filter:drop-shadow(0 0 40px rgba(249,115,22,.5));
  margin-bottom:16px;
}
.cr-stamp{
  font-size:2.4rem;font-weight:800;
  background:linear-gradient(135deg,#F97316,#3B82F6);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  background-clip:text;
  margin-bottom:8px;
  text-shadow:none;
}
.cr-final-name{
  color:#E2E8F0;font-size:1.1rem;font-weight:700;direction:ltr;
  letter-spacing:1px;margin-bottom:4px;
}
.cr-final-sub{
  color:#64748B;font-size:.85rem;font-weight:500;
  margin-bottom:20px;
}
.cr-countdown-wrap{
  color:#475569;font-size:.78rem;
}
.cr-countdown-wrap span{
  color:#F97316;font-weight:800;font-size:1rem;
}

/* === الأنيميشن === */
@keyframes crSpin{to{transform:rotate(360deg)}}
@keyframes crPulseGlow{
  0%,100%{filter:drop-shadow(0 0 20px rgba(249,115,22,.3))}
  50%{filter:drop-shadow(0 0 50px rgba(249,115,22,.6)) brightness(1.1)}
}
@keyframes crBounceIn{
  0%{transform:scale(0) rotate(-20deg);opacity:0}
  60%{transform:scale(1.2) rotate(5deg);opacity:1}
  100%{transform:scale(1) rotate(0)}
}
@keyframes crPulseWarn{
  0%,100%{opacity:.85;transform:scale(1)}
  50%{opacity:1;transform:scale(1.02)}
}

/* Transitions for stages */
.cr-stage{ transition:opacity .8s ease, transform .8s ease; transform:scale(.92); }
.cr-stage.active{ opacity:1; transform:scale(1); pointer-events:auto; }
.cr-stage.exit{ opacity:0; transform:scale(1.08); }
</style>

<script>
(function(){
  // === جزيئات الخلفية ===
  var cvs = document.getElementById('crParticles');
  var ctx = cvs.getContext('2d');
  var W, H, particles = [];
  function resize(){ W = cvs.width = window.innerWidth; H = cvs.height = window.innerHeight; }
  resize(); window.addEventListener('resize', resize);

  function Particle(){
    this.x = Math.random()*W;
    this.y = Math.random()*H;
    this.r = Math.random()*2.5+.5;
    this.vx = (Math.random()-.5)*.4;
    this.vy = (Math.random()-.5)*.4;
    this.alpha = Math.random()*.5+.1;
    this.color = Math.random()>.5 ? '249,115,22' : '59,130,246';
  }
  for(var i=0;i<80;i++) particles.push(new Particle());

  function drawParticles(){
    ctx.clearRect(0,0,W,H);
    particles.forEach(function(p){
      p.x += p.vx; p.y += p.vy;
      if(p.x<0) p.x=W; if(p.x>W) p.x=0;
      if(p.y<0) p.y=H; if(p.y>H) p.y=0;
      ctx.beginPath();
      ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
      ctx.fillStyle='rgba('+p.color+','+p.alpha+')';
      ctx.fill();
    });
    // خطوط اتصال
    for(var i=0;i<particles.length;i++){
      for(var j=i+1;j<particles.length;j++){
        var dx=particles[i].x-particles[j].x, dy=particles[i].y-particles[j].y;
        var d=Math.sqrt(dx*dx+dy*dy);
        if(d<120){
          ctx.beginPath();
          ctx.moveTo(particles[i].x,particles[i].y);
          ctx.lineTo(particles[j].x,particles[j].y);
          ctx.strokeStyle='rgba(249,115,22,'+(0.08*(1-d/120))+')';
          ctx.lineWidth=.5;
          ctx.stroke();
        }
      }
    }
    requestAnimationFrame(drawParticles);
  }
  drawParticles();

  // === تسلسل المراحل ===
  var stages = [
    {id:'crStage1', dur:3000},
    {id:'crStage2', dur:4000},
    {id:'crStage3', dur:4000},
    {id:'crStage4', dur:5000}
  ];
  var totalSec = 12;
  var current = 0;

  function showStage(idx){
    stages.forEach(function(s,i){
      var el = document.getElementById(s.id);
      if(!el) return;
      el.classList.remove('active','exit');
      if(i === idx) el.classList.add('active');
      else if(i < idx) el.classList.add('exit');
    });
  }

  function nextStage(){
    if(current >= stages.length) return;
    showStage(current);
    if(current < stages.length - 1){
      var c = current;
      setTimeout(function(){ current = c+1; nextStage(); }, stages[c].dur);
    }
  }

  // بدء بعد لحظة
  setTimeout(nextStage, 300);

  // عداد تنازلي
  var cdEl = document.getElementById('crCountdown');
  var sec = totalSec;
  var iv = setInterval(function(){
    sec--;
    if(cdEl) cdEl.textContent = sec;
    if(sec <= 0){
      clearInterval(iv);
      var ov = document.getElementById('copyrightOverlay');
      if(ov){ ov.style.transition='opacity 1s ease'; ov.style.opacity='0'; }
      setTimeout(function(){ location.href = location.pathname; }, 1000);
    }
  }, 1000);
})();
</script>
<?php endif; ?>

</body>
</html>
