<?php
// =============================================================
// employee/attendance.php - واجهة تسجيل الحضور (ثنائية اللغة) - نسخة محسنة مع البوصلة
// =============================================================

// Disable QUIC/HTTP3 - fix ERR_QUIC_PROTOCOL_ERROR
header('Alt-Svc: clear');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$token    = trim($_GET['token'] ?? '');
$employee = null;
$error    = '';

if (empty($token)) {
  $error = 'invalid_link';
} else {
  $employee = getEmployeeByToken($token);
  if (!$employee) {
    $error = 'expired_link';
  }
}

// استخدام مواعيد وإحداثيات الفرع إذا كان للموظف فرع
$branchId       = ($employee && !empty($employee['branch_id'])) ? (int)$employee['branch_id'] : null;
$schedule       = getBranchSchedule($branchId);
$workStart      = $schedule['work_start_time'];
$workEnd        = $schedule['work_end_time'];
$allowOvertime  = $schedule['allow_overtime'];

$workLat        = (float) getSystemSetting('work_latitude',  '24.572307');
$workLon        = (float) getSystemSetting('work_longitude', '46.602552');
$geofenceRadius = (int)   getSystemSetting('geofence_radius', '500');
$branchName     = '';

if ($branchId) {
  $branchStmt = db()->prepare("SELECT name, latitude, longitude, geofence_radius FROM branches WHERE id = ? AND is_active = 1");
  $branchStmt->execute([$branchId]);
  $branch = $branchStmt->fetch();
  if ($branch) {
    $workLat        = (float) $branch['latitude'];
    $workLon        = (float) $branch['longitude'];
    $geofenceRadius = (int)   $branch['geofence_radius'];
    $branchName     = $branch['name'];
  }
}

$todayStatus  = 'none';
$checkInTime  = null;
$checkOutTime = null;
$historyRecords = [];

if ($employee) {
  $stmt = db()->prepare("SELECT type, timestamp FROM attendances WHERE employee_id=? AND attendance_date=CURDATE() ORDER BY timestamp ASC");
  $stmt->execute([$employee['id']]);
  foreach ($stmt->fetchAll() as $rec) {
    if ($rec['type'] === 'in'  && !$checkInTime)  $checkInTime  = $rec['timestamp'];
    if ($rec['type'] === 'out')                    $checkOutTime = $rec['timestamp'];
  }
  if ($checkOutTime)    $todayStatus = 'checked_out';
  elseif ($checkInTime) $todayStatus = 'checked_in';

  $stmtH = db()->prepare("SELECT type, timestamp FROM attendances WHERE employee_id=? AND attendance_date=CURDATE() ORDER BY timestamp DESC LIMIT 6");
  $stmtH->execute([$employee['id']]);
  $historyRecords = $stmtH->fetchAll();
}

$showCheckoutButton = false; // الانصراف تلقائي دائماً

$jsConfig = json_encode([
  'token'          => $token,
  'status'         => $todayStatus,
  'checkInTime'    => $checkInTime,
  'workLat'        => $workLat,
  'workLon'        => $workLon,
  'geofenceRadius' => $geofenceRadius,
  'workEnd'        => $workEnd,
  'showCheckout'   => false,
  'allowOvertime'  => $allowOvertime,
  'branchName'     => $branchName,
], JSON_UNESCAPED_UNICODE);

$badgeClass = $todayStatus === 'checked_in' ? 'in' : ($todayStatus === 'checked_out' ? 'out' : 'none');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no, viewport-fit=cover">
  <title><?= SITE_NAME ?></title>
  <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/loogo.png">
  <link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/images/loogo.png">
  <link rel="manifest" href="<?= SITE_URL ?>/manifest.json">
  <meta name="theme-color" content="#F97316">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/fonts/tajawal.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/radar.css?v=<?= time() ?>">
</head>

<body>

  <?php if ($error): ?>
    <!-- ERROR PAGE — no device overlay needed -->
  <?php else: ?>
    <!-- DEVICE VERIFICATION OVERLAY -->
    <div id="deviceOverlay">
      <div class="ov-spinner" id="ovSpinner"></div>
      <div class="ov-title" id="ovTitle"></div>
      <div class="ov-sub" id="ovSub"></div>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error-wrap">
      <div class="error-card">
        <div class="error-icon">&#x26D4;</div>
        <div class="error-title" data-i18n="error_title"></div>
        <div class="error-msg" data-i18n="error_<?= $error ?>"></div>
        <a class="wa-support-btn" id="waErrorBtn" href="#" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
          </svg>
          <span data-i18n="wa_support">تواصل مع الدعم الفني</span>
        </a>
      </div>
    </div>
  <?php else: ?>

    <!-- HEADER -->
    <div class="header">
      <div class="hl">
        <div class="emp-av"><img src="<?= SITE_URL ?>/assets/images/loogo.png" alt="Logo"></div>
        <div>
          <div class="emp-name"><?= htmlspecialchars($employee['name']) ?></div>
          <div class="emp-job"><?= htmlspecialchars($employee['job_title']) ?></div>
          <?php if ($branchName): ?>
            <div class="branch-tag">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />
              </svg>
              <?= htmlspecialchars($branchName) ?>
            </div>
          <?php endif; ?>
          <span class="hdr-badge <?= $badgeClass ?>" data-i18n="status_<?= $todayStatus ?>"></span>
        </div>
      </div>
      <div class="hr">
        <div class="hdr-date" id="hdrDate"></div>
        <div class="hdr-time" id="hdrTime"></div>
        <?php if ($checkInTime): ?>
          <div class="hdr-date"><span data-i18n="checked_in_at"></span> <?= date('h:i A', strtotime($checkInTime)) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- RADAR ZONE -->
    <div class="radar-zone">
      <div class="guidance-box" id="guidanceBox"></div>
      <div class="radar-card">
        <div class="radar-wrap">
          <div id="radarMap"></div>
          <canvas id="radarCanvas"></canvas>
        </div>
      </div>
      <div class="dist-row">
        <div class="dist-dot" id="distDot"></div>
        <div class="dist-lbl" data-i18n="distance_label"></div>
        <div><span class="dist-val" id="distValue">--</span><span class="dist-unit" data-i18n="meter_unit"></span></div>
      </div>

      <!-- ACTION BUTTONS (inline after distance) -->
      <div class="bottom-panel">


        <div class="message" id="messageBox"></div>

        <div class="btn-row">
          <button class="btn btn-in <?= $todayStatus !== 'none' ? 'btn-hidden' : '' ?>"
            id="btnCheckIn" onclick="submitAttendance('in', true)" disabled>
            <span class="btn-icon">&#x2705;</span>
            <span data-i18n="btn_checkin"></span>
          </button>
          <?php if ($todayStatus === 'checked_in'): ?>
            <div class="done-msg" data-i18n="auto_checkout_note">سيتم تسجيل الانصراف تلقائياً عند <?= htmlspecialchars($workEnd) ?></div>
          <?php elseif ($allowOvertime && $todayStatus === 'checked_out'): ?>
            <button class="btn btn-overtime" id="btnOvertime" onclick="submitAttendance('overtime', true)" disabled>
              <span class="btn-icon">&#x23F0;</span>
              <span data-i18n="btn_overtime"></span>
            </button>
          <?php elseif ($todayStatus === 'checked_out'): ?>
            <div class="done-msg" data-i18n="shift_done"></div>
          <?php endif; ?>
        </div>

        <div class="spinner" id="spinner"></div>
      </div>

      <div class="gps-row">
        <div class="gps-dot" id="gpsDot"></div>
        <span id="gpsText" data-i18n="gps_locating"></span>
        <span class="compass-indicator" id="compassIndicator">🧭 ---</span>
      </div>
      <div class="info-strip">
        <div class="info-chip"><span>🧭</span><span id="headingText">---</span></div>
        <div class="info-chip"><span>🎯</span><span id="bearingText">---</span></div>
        <div class="info-chip"><span>📡</span><span id="accText">---</span></div>
      </div>
      <?php if ($todayStatus === 'checked_in' && $checkInTime): ?>
        <div class="timer-row">
          <span class="timer-lbl" data-i18n="work_duration"></span>
          <span class="timer-clock" id="workTimer">00:00:00</span>
        </div>
      <?php endif; ?>

      <?php if ($historyRecords): ?>
        <div class="history-wrap">
          <?php foreach ($historyRecords as $rec): ?>
            <div class="hist-item">
              <span class="type-<?= $rec['type'] ?>" data-i18n="type_<?= $rec['type'] ?>"></span>
              <span class="hist-time"><?= date('h:i A', strtotime($rec['timestamp'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- FLOATING SECRET REPORT BUTTON -->
    <button class="sr-float-btn" onclick="openReportModal()" title="تقرير سري">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="display:block;margin:auto">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        <line x1="12" y1="8" x2="12" y2="12" />
        <line x1="12" y1="16" x2="12.01" y2="16" />
      </svg>
    </button>

    <!-- FLOATING REFRESH BUTTON -->
    <button class="refresh-float-btn" onclick="location.reload()" title="تحديث الصفحة">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="display:block;margin:auto">
        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
      </svg>
    </button>

    <!-- FLOATING SWITCH USER BUTTON -->
    <button class="switch-user-float-btn" onclick="switchUser()" title="تغيير المستخدم">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="display:block;margin:auto">
        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="8.5" cy="7" r="4"/>
        <line x1="20" y1="8" x2="20" y2="14"/>
        <line x1="23" y1="11" x2="17" y2="11"/>
      </svg>
    </button>

    <!-- SECRET REPORT MODAL -->
    <div class="sr-modal-overlay" id="srModal">
      <div class="sr-modal">
        <div class="sr-modal-header">
          <div class="sr-modal-title">🔒 تقرير سري</div>
          <button class="sr-close" onclick="closeReportModal()">&times;</button>
        </div>
        <div class="sr-privacy-notice">
          <span>🛡️</span> هذا التقرير سري. لن يتم الكشف عن اسمك للأشخاص المذكورين في التقرير.
        </div>
        <form id="secretReportForm" enctype="multipart/form-data">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

          <div class="sr-field">
            <label>نوع البلاغ</label>
            <select name="report_type" id="srType">
              <option value="general">📝 بلاغ عام</option>
              <option value="violation">⚠️ مخالفة</option>
              <option value="harassment">🚨 تحرش</option>
              <option value="theft">🔐 سرقة</option>
              <option value="safety">🛡️ سلامة</option>
              <option value="other">📋 أخرى</option>
            </select>
          </div>

          <div class="sr-field">
            <label>تفاصيل البلاغ</label>
            <textarea name="report_text" id="srText" rows="4" placeholder="اكتب تفاصيل البلاغ هنا..."></textarea>
          </div>

          <!-- IMAGE UPLOAD -->
          <div class="sr-field">
            <label>📷 إرفاق صور (اختياري)</label>
            <div class="sr-img-actions">
              <label class="sr-img-btn" id="cameraBtnLabel">
                📸 الكاميرا
                <input type="file" name="images[]" accept="image/*" capture="environment" multiple style="display:none" onchange="previewImages(this)">
              </label>
              <label class="sr-img-btn">
                🖼️ المعرض
                <input type="file" name="images[]" accept="image/*" multiple style="display:none" onchange="previewImages(this)">
              </label>
            </div>
            <div class="sr-img-preview" id="imgPreview"></div>
          </div>

          <!-- VOICE RECORDING -->
          <div class="sr-field">
            <label>🎙️ رسالة صوتية (اختياري)</label>

            <!-- Voice Effect Selection -->
            <div class="sr-voice-effects" id="voiceEffects">
              <label class="sr-effect-option active">
                <input type="radio" name="voice_effect" value="none" checked onchange="changeVoiceEffect(this.value)">
                <span class="sr-effect-icon">🎤</span>
                <span>بدون تغيير</span>
              </label>
              <label class="sr-effect-option">
                <input type="radio" name="voice_effect" value="deep" onchange="changeVoiceEffect(this.value)">
                <span class="sr-effect-icon">🔊</span>
                <span>صوت عميق</span>
              </label>
              <label class="sr-effect-option">
                <input type="radio" name="voice_effect" value="high" onchange="changeVoiceEffect(this.value)">
                <span class="sr-effect-icon">🔔</span>
                <span>صوت حاد</span>
              </label>
              <label class="sr-effect-option">
                <input type="radio" name="voice_effect" value="robot" onchange="changeVoiceEffect(this.value)">
                <span class="sr-effect-icon">🤖</span>
                <span>صوت روبوت</span>
              </label>
            </div>

            <div class="sr-voice-controls">
              <button type="button" class="sr-voice-btn" id="recordBtn" onclick="toggleRecording()">
                <span id="recordIcon">🎙️</span>
                <span id="recordText">ابدأ التسجيل</span>
              </button>
              <span class="sr-record-time" id="recordTime" style="display:none">00:00</span>
            </div>

            <!-- Voice Preview -->
            <div class="sr-voice-preview" id="voicePreview" style="display:none">
              <div class="sr-preview-label">معاينة الصوت بعد التغيير:</div>
              <audio id="previewAudio" controls style="width:100%;height:36px"></audio>
              <div class="sr-preview-actions">
                <button type="button" class="sr-preview-btn" onclick="reRecord()">🔄 إعادة التسجيل</button>
              </div>
            </div>
          </div>

          <button type="submit" class="sr-submit" id="srSubmit">
            <span id="srSubmitText">📤 إرسال التقرير</span>
            <span id="srSubmitSpinner" style="display:none">⏳ جاري الإرسال...</span>
          </button>
        </form>

        <div class="sr-success" id="srSuccess" style="display:none">
          <div style="font-size:3rem;margin-bottom:12px">✅</div>
          <div style="font-size:1.1rem;font-weight:800;margin-bottom:8px;color:#1E293B">تم إرسال التقرير بنجاح</div>
          <div style="font-size:.82rem;color:#64748B;margin-bottom:16px">شكراً لمساهمتك في تحسين بيئة العمل</div>
          <button class="sr-submit" onclick="closeReportModal()">إغلاق</button>
        </div>
      </div>
    </div>

    <!-- MIC PERMISSION POPUP -->
    <div class="sr-modal-overlay" id="micPermModal">
      <div class="sr-modal" style="max-width:380px;border-radius:22px;text-align:center;padding:28px 22px;background:#fff">
        <div style="font-size:3rem;margin-bottom:12px">🎙️</div>
        <div style="font-size:1.05rem;font-weight:800;color:#1E293B;margin-bottom:8px">يلزم إذن المايكروفون</div>
        <div style="font-size:.82rem;color:#64748B;margin-bottom:18px;line-height:1.6">
          لتسجيل رسالة صوتية، يجب السماح للمتصفح باستخدام المايكروفون.<br>
          اضغط الزر أدناه لفتح الإعدادات.
        </div>
        <button class="sr-submit" onclick="requestMicPermission()" style="margin-bottom:10px">🔓 السماح بالمايكروفون</button>
        <button class="sr-submit" onclick="document.getElementById('micPermModal').classList.remove('show')" style="background:#E2E8F0;color:#64748B">إلغاء</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$error): ?>
    <!-- CONFIG (PHP → JS) -->
    <script>
      window.RADAR_CFG = <?= $jsConfig ?>;
      window.RADAR_STATE = {
        todayStatus: '<?= $todayStatus ?>',
        checkInTime: '<?= $checkInTime ? date("Y-m-d\\TH:i:s", strtotime($checkInTime)) : "" ?>',
        showCheckout: <?= $showCheckoutButton ? 'true' : 'false' ?>,
        workEnd: '<?= $workEnd ?>'
      };
      window.RADAR_URLS = {
        verifyDevice: '<?= SITE_URL ?>/api/verify-device.php',
        checkIn: '<?= SITE_URL ?>/api/check-in.php',
        checkOut: '<?= SITE_URL ?>/api/check-out.php',
        ot: '<?= SITE_URL ?>/api/ot.php',
        errorReport: '<?= SITE_URL ?>/api/error-report.php',
        logoUrl: '<?= SITE_URL ?>/assets/images/loogo.png',
        worker: '<?= SITE_URL ?>/worker.js',
        sw: '<?= SITE_URL ?>/sw.js',
        leafletCss: '<?= SITE_URL ?>/assets/vendor/leaflet/leaflet.min.css',
        leafletJs: '<?= SITE_URL ?>/assets/vendor/leaflet/leaflet.min.js',
        tileSatellite: '<?= SITE_URL ?>/api/tile.php?l=satellite&z={z}&y={y}&x={x}'
      };

      // ===== Global Error Handler — يظهر رابط الدعم الفني =====
      window.onerror = function(msg, url, line, col, error) {
          showSupportOverlay(msg, url + ':' + line);
          return true;
      };
      window.addEventListener('unhandledrejection', function(e) {
          showSupportOverlay(e.reason || 'خطأ غير متوقع', '');
      });
      function showSupportOverlay(errMsg, errDetail) {
          if (document.getElementById('supportOverlay')) return;
          const empName = <?= json_encode($employee['name'] ?? '') ?>;
          const empToken = <?= json_encode($token) ?>;
          const supportPhone = <?= json_encode(getSystemSetting('support_phone', '966578448146')) ?>;
          const errorInfo = 'الموظف: ' + empName + '\\nالخطأ: ' + errMsg + '\\nالتفاصيل: ' + errDetail + '\\nالوقت: ' + new Date().toLocaleString('ar-SA');
          const waLink = 'https://wa.me/' + supportPhone + '?text=' + encodeURIComponent('🔴 مشكلة في بوابة الموظف\\n\\n' + errorInfo);
          const ov = document.createElement('div');
          ov.id = 'supportOverlay';
          ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px';
          ov.innerHTML = '<div style="background:#fff;border-radius:20px;padding:30px;max-width:380px;width:100%;text-align:center;font-family:Tajawal,sans-serif">'
            + '<div style="font-size:3rem;margin-bottom:12px">⚠️</div>'
            + '<div style="font-size:1.1rem;font-weight:800;color:#1E293B;margin-bottom:8px">حدث خطأ غير متوقع</div>'
            + '<div style="font-size:.82rem;color:#64748B;margin-bottom:18px;line-height:1.6">لا تقلق! يمكنك إرسال تفاصيل المشكلة للدعم الفني وسيتم حلها في أقرب وقت.</div>'
            + '<a href="' + waLink + '" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;gap:8px;background:#25D366;color:#fff;padding:12px 20px;border-radius:12px;text-decoration:none;font-weight:700;font-size:.95rem;margin-bottom:10px">'
            + '<svg width="20" height="20" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>'
            + 'إرسال المشكلة للدعم الفني</a>'
            + '<button onclick="this.closest(\'#supportOverlay\').remove()" style="background:#E2E8F0;color:#64748B;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:inherit;font-weight:600">إغلاق</button>'
            + '</div>';
          document.body.appendChild(ov);
      }
    </script>
    <script src="<?= SITE_URL ?>/assets/js/radar.js?v=<?= time() ?>"></script>
    <script>
      // ── Switch User (clear saved PIN, go to gateway) ──
      function switchUser() {
        try { localStorage.removeItem('att_pin_auth'); } catch(e) {}
        window.location.href = '<?= SITE_URL ?>/employee/';
      }

      let mediaRecorder = null;
      let audioChunks = [];
      let recordingTimer = null;
      let recordStartTime = 0;
      let currentEffect = 'none';
      let processedBlob = null;
      let rawAudioBlob = null;

      function openReportModal() {
        document.getElementById('srModal').classList.add('show');
        document.body.style.overflow = 'hidden';
      }

      // Request mic permission
      async function requestMicPermission() {
        try {
          const stream = await navigator.mediaDevices.getUserMedia({
            audio: true
          });
          stream.getTracks().forEach(t => t.stop());
          document.getElementById('micPermModal').classList.remove('show');
          // بعد الحصول على الإذن، فعّل التسجيل
          toggleRecording();
        } catch (e) {
          alert('تم رفض الإذن. يرجى السماح بالمايكروفون من إعدادات المتصفح ثم إعادة المحاولة.');
        }
      }

      function closeReportModal() {
        document.getElementById('srModal').classList.remove('show');
        document.body.style.overflow = '';
        // Reset form
        document.getElementById('secretReportForm').reset();
        document.getElementById('imgPreview').innerHTML = '';
        document.getElementById('voicePreview').style.display = 'none';
        document.getElementById('srSuccess').style.display = 'none';
        document.getElementById('secretReportForm').style.display = '';
        document.querySelectorAll('.sr-effect-option').forEach((o, i) => o.classList.toggle('active', i === 0));
        processedBlob = null;
        rawAudioBlob = null;
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          mediaRecorder.stop();
        }
      }

      // Image preview
      function previewImages(input) {
        const preview = document.getElementById('imgPreview');
        const files = input.files;
        for (let i = 0; i < Math.min(files.length, 5); i++) {
          const reader = new FileReader();
          reader.onload = function(e) {
            const thumb = document.createElement('div');
            thumb.className = 'sr-img-thumb';
            thumb.innerHTML = `<img src="${e.target.result}"><button type="button" class="sr-img-remove" onclick="this.parentElement.remove()">✕</button>`;
            preview.appendChild(thumb);
          };
          reader.readAsDataURL(files[i]);
        }
      }

      // Voice effect radio buttons
      document.querySelectorAll('.sr-effect-option').forEach(opt => {
        opt.addEventListener('click', function() {
          document.querySelectorAll('.sr-effect-option').forEach(o => o.classList.remove('active'));
          this.classList.add('active');
        });
      });

      function changeVoiceEffect(effect) {
        currentEffect = effect;
        // If we have a raw recording, re-process it with new effect
        if (rawAudioBlob) {
          applyVoiceEffect(rawAudioBlob, effect).then(blob => {
            processedBlob = blob;
            const url = URL.createObjectURL(blob);
            document.getElementById('previewAudio').src = url;
          });
        }
      }

      // Voice recording
      async function toggleRecording() {
        const btn = document.getElementById('recordBtn');
        const icon = document.getElementById('recordIcon');
        const text = document.getElementById('recordText');
        const timeEl = document.getElementById('recordTime');

        if (mediaRecorder && mediaRecorder.state === 'recording') {
          // Stop recording
          mediaRecorder.stop();
          btn.classList.remove('recording');
          icon.textContent = '🎙️';
          text.textContent = 'ابدأ التسجيل';
          timeEl.style.display = 'none';
          clearInterval(recordingTimer);
          return;
        }

        try {
          const stream = await navigator.mediaDevices.getUserMedia({
            audio: true
          });
          mediaRecorder = new MediaRecorder(stream, {
            mimeType: 'audio/webm'
          });
          audioChunks = [];

          mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) audioChunks.push(e.data);
          };

          mediaRecorder.onstop = async () => {
            stream.getTracks().forEach(t => t.stop());
            rawAudioBlob = new Blob(audioChunks, {
              type: 'audio/webm'
            });

            // Apply voice effect
            const effectVal = document.querySelector('input[name="voice_effect"]:checked').value;
            processedBlob = await applyVoiceEffect(rawAudioBlob, effectVal);

            // Show preview
            const previewUrl = URL.createObjectURL(processedBlob);
            document.getElementById('previewAudio').src = previewUrl;
            document.getElementById('voicePreview').style.display = '';
          };

          mediaRecorder.start(100);
          btn.classList.add('recording');
          icon.textContent = '⏹️';
          text.textContent = 'إيقاف التسجيل';
          timeEl.style.display = '';
          recordStartTime = Date.now();

          recordingTimer = setInterval(() => {
            const elapsed = Math.floor((Date.now() - recordStartTime) / 1000);
            const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const s = String(elapsed % 60).padStart(2, '0');
            timeEl.textContent = `${m}:${s}`;
          }, 200);

        } catch (err) {
          // عرض نافذة إذن المايكروفون
          document.getElementById('micPermModal').classList.add('show');
        }
      }

      function reRecord() {
        processedBlob = null;
        rawAudioBlob = null;
        document.getElementById('voicePreview').style.display = 'none';
      }

      // ── Voice Effect Processing (Web Audio API) ──
      async function applyVoiceEffect(blob, effect) {
        if (effect === 'none') return blob;

        const audioCtx = new(window.AudioContext || window.webkitAudioContext)();
        const arrayBuffer = await blob.arrayBuffer();
        const audioBuffer = await audioCtx.decodeAudioData(arrayBuffer);

        let pitchRate = 1;
        let addDistortion = false;
        if (effect === 'deep') pitchRate = 0.65; // صوت عميق
        if (effect === 'high') pitchRate = 1.55; // صوت حاد
        if (effect === 'robot') {
          pitchRate = 0.85;
          addDistortion = true;
        } // روبوت

        // Create offline context with adjusted duration
        const duration = audioBuffer.duration / pitchRate;
        const offlineCtx = new OfflineAudioContext(
          audioBuffer.numberOfChannels,
          Math.ceil(audioCtx.sampleRate * duration),
          audioCtx.sampleRate
        );

        const source = offlineCtx.createBufferSource();
        source.buffer = audioBuffer;
        source.playbackRate.value = pitchRate;

        if (addDistortion) {
          // Robot effect: add waveshaper + tremolo
          const waveshaper = offlineCtx.createWaveShaper();
          const curve = new Float32Array(256);
          for (let i = 0; i < 256; i++) {
            const x = (i * 2) / 256 - 1;
            curve[i] = Math.sign(x) * Math.pow(Math.abs(x), 0.5);
          }
          waveshaper.curve = curve;
          waveshaper.oversample = '4x';

          // Tremolo (amplitude modulation)
          const gainNode = offlineCtx.createGain();
          const lfo = offlineCtx.createOscillator();
          const lfoGain = offlineCtx.createGain();
          lfo.frequency.value = 30; // Hz
          lfoGain.gain.value = 0.3;
          lfo.connect(lfoGain);
          lfoGain.connect(gainNode.gain);
          lfo.start();

          source.connect(waveshaper);
          waveshaper.connect(gainNode);
          gainNode.connect(offlineCtx.destination);
        } else {
          source.connect(offlineCtx.destination);
        }

        source.start(0);
        const renderedBuffer = await offlineCtx.startRendering();

        // Convert to WAV then to Blob
        const wavBlob = audioBufferToWavBlob(renderedBuffer);
        audioCtx.close();
        return wavBlob;
      }

      function audioBufferToWavBlob(buffer) {
        const numChan = buffer.numberOfChannels;
        const sampleRate = buffer.sampleRate;
        const format = 1; // PCM
        const bitsPerSample = 16;

        let interleaved;
        if (numChan === 2) {
          const l = buffer.getChannelData(0);
          const r = buffer.getChannelData(1);
          interleaved = new Float32Array(l.length + r.length);
          for (let i = 0, j = 0; i < l.length; i++) {
            interleaved[j++] = l[i];
            interleaved[j++] = r[i];
          }
        } else {
          interleaved = buffer.getChannelData(0);
        }

        const dataLength = interleaved.length * (bitsPerSample / 8);
        const headerLength = 44;
        const arrayBuffer = new ArrayBuffer(headerLength + dataLength);
        const view = new DataView(arrayBuffer);

        // WAV header
        writeString(view, 0, 'RIFF');
        view.setUint32(4, 36 + dataLength, true);
        writeString(view, 8, 'WAVE');
        writeString(view, 12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, format, true);
        view.setUint16(22, numChan, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * numChan * bitsPerSample / 8, true);
        view.setUint16(32, numChan * bitsPerSample / 8, true);
        view.setUint16(34, bitsPerSample, true);
        writeString(view, 36, 'data');
        view.setUint32(40, dataLength, true);

        // Write samples
        let offset = 44;
        for (let i = 0; i < interleaved.length; i++, offset += 2) {
          const s = Math.max(-1, Math.min(1, interleaved[i]));
          view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
        }

        function writeString(view, offset, str) {
          for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
        }

        return new Blob([view], {
          type: 'audio/wav'
        });
      }

      // ── Submit Report ──
      document.getElementById('secretReportForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('srSubmit');
        const submitText = document.getElementById('srSubmitText');
        const submitSpinner = document.getElementById('srSubmitSpinner');

        submitBtn.disabled = true;
        submitText.style.display = 'none';
        submitSpinner.style.display = '';

        const formData = new FormData();
        formData.append('token', '<?= htmlspecialchars($token) ?>');
        formData.append('report_type', document.getElementById('srType').value);
        formData.append('report_text', document.getElementById('srText').value);
        formData.append('voice_effect', document.querySelector('input[name="voice_effect"]:checked').value);

        // Collect images from all file inputs
        document.querySelectorAll('#secretReportForm input[type="file"][name="images[]"]').forEach(input => {
          for (let i = 0; i < input.files.length; i++) {
            formData.append('images[]', input.files[i]);
          }
        });

        // Attach processed voice blob
        if (processedBlob) {
          formData.append('voice', processedBlob, 'voice_recording.webm');
        }

        try {
          const resp = await fetch('../api/submit-report.php', {
            method: 'POST',
            body: formData
          });
          const data = await resp.json();

          if (data.success) {
            document.getElementById('secretReportForm').style.display = 'none';
            document.getElementById('srSuccess').style.display = '';
          } else {
            alert(data.message || 'حدث خطأ');
          }
        } catch (err) {
          alert('خطأ في الاتصال. حاول مرة أخرى.');
        } finally {
          submitBtn.disabled = false;
          submitText.style.display = '';
          submitSpinner.style.display = 'none';
        }
      });

      // Close modal on overlay click
      document.getElementById('srModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeReportModal();
      });
    </script>
  <?php else: ?>
    <!-- Error page — show clear Arabic error + WhatsApp support -->
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var T = {
          error_title: 'رابط غير صالح',
          error_invalid_link: 'رابط غير صالح. يرجى التحقق من الرابط المرسل.',
          error_expired_link: 'الرابط غير صحيح أو انتهت صلاحيته. تواصل مع المدير للحصول على رابط جديد.',
          wa_support: 'تواصل مع الدعم الفني'
        };
        document.querySelectorAll('[data-i18n]').forEach(function(el) {
          var key = el.getAttribute('data-i18n');
          if (T[key]) el.textContent = T[key];
        });
        // Build WhatsApp support link with error details
        var errorType = '<?= $error ?>';
        var errorMsg = T['error_' + errorType] || 'خطأ غير معروف';
        var waText = '🚨 مشكلة في نظام الحضور\n' +
          '📋 الخطأ: ' + errorMsg + '\n' +
          '🔗 الرابط: ' + window.location.href + '\n' +
          '🕐 الوقت: ' + new Date().toLocaleString('ar-SA');
        var waBtn = document.getElementById('waErrorBtn');
        if (waBtn) waBtn.href = 'https://wa.me/966578448146?text=' + encodeURIComponent(waText);
      });
    </script>
  <?php endif; ?>
</body>

</html>