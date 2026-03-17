// =============================================================
// assets/js/radar.js — Employee = CENTER, Branch = TARGET
// Radar rotates with device compass. Branch on real bearing.
// =============================================================
(function(){
'use strict';

// ── Translations ──
const LANG = {
  ar: {
    status_checked_in:'مسجّل دخول', status_checked_out:'اكتمل', status_none:'لم يُسجَّل',
    checked_in_at:'دخول:',
    distance_label:'المسافة', meter_unit:' م',
    gps_locating:'جاري تحديد موقعك…',
    gps_high_accuracy:'دقة عالية', gps_med_accuracy:'دقة متوسطة', gps_low_accuracy:'دقة منخفضة',
    gps_no_support:'المتصفح لا يدعم GPS', gps_allow:'اسمح بالموقع من الإعدادات',
    gps_enable:'فعّل GPS وأعد المحاولة', gps_timeout:'انتهت مهلة GPS',
    gps_error:'خطأ في الموقع', gps_failed:'تعذّر تحديد موقعك',
    inside_zone:'✅ أنت داخل نطاق العمل',
    outside_zone_pre:'⚠ تبعد ', outside_zone_suf:' م عن النطاق',
    btn_checkin:'تسجيل الحضور', btn_checkout:'تسجيل الانصراف', btn_overtime:'دوام إضافي',
    shift_done:'تم إنهاء دوامك اليوم',
    checkout_countdown:'الانصراف بعد:', work_duration:'مدة الدوام:',
    type_in:'▶ حضور', type_out:'◀ انصراف', type_overtime:'⏰ إضافي',
    'type_overtime-start':'⏰ بدء إضافي', 'type_overtime-end':'⏰ نهاية إضافي',
    error_title:'رابط غير صالح',
    error_invalid_link:'رابط غير صالح. يرجى التحقق من الرابط المرسل.',
    error_expired_link:'الرابط غير صحيح أو انتهت صلاحيته. تواصل مع المدير.',
    msg_error_network:'خطأ في الاتصال',
    ov_verifying:'جاري التحقق…', ov_please_wait:'يرجى الانتظار',
    ov_locked_title:'رابط مرتبط بجهاز آخر',
    ov_locked_msg:'هذا الرابط مسجّل على جهاز مختلف. تواصل مع المشرف لإعادة تعيين الجهاز.',
    ov_error_title:'خطأ في التحقق', ov_error_msg:'حدث خطأ. تواصل مع المشرف.',
    wa_support:'تواصل مع الدعم الفني',
    radar_n:'ش', radar_s:'ج', radar_e:'شق', radar_w:'غ',
    time_h:' س ', time_m:' د ', time_s:' ث',
    gps_modal_title:'الموقع غير مفعّل',
    gps_modal_msg:'يجب تفعيل خدمة الموقع (GPS) لتتمكن من تسجيل الحضور.',
    gps_modal_btn:'فتح الإعدادات',
    gps_modal_retry:'إعادة المحاولة',
    compass_dirs:['ش','ش.شق','شق','ج.شق','ج','ج.غ','غ','ش.غ'],
    heading_label:'اتجاهك', bearing_label:'الفرع', accuracy_label:'الدقة',
    dir:'rtl', locale:'ar-SA',
  },
  en: {
    status_checked_in:'Checked In', status_checked_out:'Completed', status_none:'Not Checked',
    checked_in_at:'In:',
    distance_label:'Distance', meter_unit:' m',
    gps_locating:'Locating…',
    gps_high_accuracy:'High accuracy', gps_med_accuracy:'Medium accuracy', gps_low_accuracy:'Low accuracy',
    gps_no_support:'No GPS support', gps_allow:'Allow location in settings',
    gps_enable:'Enable GPS and retry', gps_timeout:'GPS timeout',
    gps_error:'Location error', gps_failed:'Could not get location',
    inside_zone:'✅ Within work zone',
    outside_zone_pre:'⚠ ', outside_zone_suf:' m away',
    btn_checkin:'Check In', btn_checkout:'Check Out', btn_overtime:'Overtime',
    shift_done:'Shift completed for today',
    checkout_countdown:'Check-out in:', work_duration:'Duration:',
    type_in:'▶ In', type_out:'◀ Out', type_overtime:'⏰ OT',
    'type_overtime-start':'⏰ OT Start', 'type_overtime-end':'⏰ OT End',
    error_title:'Invalid Link',
    error_invalid_link:'Invalid link.',
    error_expired_link:'Link invalid or expired.',
    msg_error_network:'Connection error',
    ov_verifying:'Verifying…', ov_please_wait:'Please wait',
    ov_locked_title:'Link tied to another device',
    ov_locked_msg:'Contact your supervisor to reset.',
    ov_error_title:'Error', ov_error_msg:'An error occurred.',
    wa_support:'Contact Support',
    radar_n:'N', radar_s:'S', radar_e:'E', radar_w:'W',
    time_h:'h ', time_m:'m ', time_s:'s',
    gps_modal_title:'Location Disabled',
    gps_modal_msg:'Enable location to check in.',
    gps_modal_btn:'Settings',
    gps_modal_retry:'Retry',
    compass_dirs:['N','NE','E','SE','S','SW','W','NW'],
    heading_label:'Heading', bearing_label:'Branch', accuracy_label:'Accuracy',
    dir:'ltr', locale:'en-US',
  }
};

const browserLang = (navigator.language || navigator.userLanguage || 'ar').toLowerCase();
const isArabic = browserLang.startsWith('ar');
const T = isArabic ? LANG.ar : LANG.en;

document.documentElement.lang = isArabic ? 'ar' : 'en';
document.documentElement.dir  = T.dir;
document.querySelectorAll('[data-i18n]').forEach(function(el){
  var key = el.getAttribute('data-i18n');
  if (T[key] !== undefined) el.textContent = T[key];
});

// ── Config ──
const CFG   = window.RADAR_CFG   || {};
const STATE = window.RADAR_STATE || {};
const URLS  = window.RADAR_URLS  || {};

// ── WhatsApp Support Helper ──
const WA_SUPPORT_NUM = '966578448146';
const WA_SVG = '<svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

function buildWaLink(errorMsg) {
  var empName = (CFG && CFG.token) ? (document.querySelector('.emp-name') || {}).textContent || '' : '';
  var text = '\uD83D\uDEA8 مشكلة في نظام الحضور\n'
    + '\uD83D\uDCCB الخطأ: ' + errorMsg + '\n'
    + (empName ? '\uD83D\uDC64 الموظف: ' + empName + '\n' : '')
    + '\uD83D\uDD17 الرابط: ' + window.location.href + '\n'
    + '\uD83D\uDD50 الوقت: ' + new Date().toLocaleString(T.locale);
  return 'https://wa.me/' + WA_SUPPORT_NUM + '?text=' + encodeURIComponent(text);
}

function waButtonHTML(errorMsg, large) {
  var cls = large ? 'wa-support-btn' : 'wa-support-inline';
  return '<a class="' + cls + '" href="' + buildWaLink(errorMsg) + '" target="_blank" rel="noopener">'
    + WA_SVG + '<span>' + T.wa_support + '</span></a>';
}

// ── Silent Error Reporting ──
var _reportQueue = [], _reportBusy = false;
function silentReport(errorType, errorMsg, extra) {
  if (!URLS.errorReport) return;
  _reportQueue.push({error_type: errorType, error_message: errorMsg, token: CFG.token || '', page: location.pathname, extra: extra || ''});
  if (!_reportBusy) _flushReports();
}
function _flushReports() {
  if (!_reportQueue.length) { _reportBusy = false; return; }
  _reportBusy = true;
  var item = _reportQueue.shift();
  fetch(URLS.errorReport, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(item)})
    .catch(function(){})
    .finally(function(){ setTimeout(_flushReports, 500); });
}

// ── State ──
var userLat = null, userLon = null, userAcc = null, locReady = false;
var userDist = null;     // distance from employee to branch
var autoCheckInDone = false; // prevent repeated auto check-in
var branchBearing = 0;   // bearing FROM employee TO branch (radians, geographic)
var deviceHeading = 0;   // device compass heading (degrees, 0=north)
var smoothHeading = 0;   // smoothed heading for rendering
var compassSupported = false;
var LERP = 0.06;          // smoother heading rotation

// Lerped branch position (smooth canvas movement)
var smoothBranchDist    = 0;
var smoothBearAngle     = 0;
var smoothBearInit      = false;

function lerpAngle(from, to, t) {
  var diff = to - from;
  while (diff > 180) diff -= 360;
  while (diff < -180) diff += 360;
  return from + diff * t;
}

// ================================================================
// DEVICE FINGERPRINT
// ================================================================
async function getFingerprint() {
  // v2: stable fingerprint — no userAgent/platform (volatile), system fonts only
  var parts = [
    screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
    Intl.DateTimeFormat().resolvedOptions().timeZone,
    navigator.language,
    navigator.hardwareConcurrency || '',
    (navigator.maxTouchPoints || 0).toString()
  ];
  try {
    var c = document.createElement('canvas');
    var x = c.getContext('2d');
    x.textBaseline = 'top'; x.font = '14px Arial,sans-serif';
    x.fillStyle = '#F97316'; x.fillRect(0, 0, 100, 20);
    x.fillStyle = '#1E293B'; x.fillText('attendance2025', 2, 2);
    parts.push(c.toDataURL().slice(-50));
  } catch(e){}
  var raw = parts.join('|');
  var buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
  return Array.from(new Uint8Array(buf)).map(function(b){return b.toString(16).padStart(2,'0')}).join('');
}

async function verifyDevice() {
  var overlay = document.getElementById('deviceOverlay');
  var ovTitle = document.getElementById('ovTitle');
  var ovSub   = document.getElementById('ovSub');
  var ovSpinner = document.getElementById('ovSpinner');
  if (!overlay) return;
  ovTitle.textContent = T.ov_verifying;
  ovSub.textContent   = T.ov_please_wait;
  try {
    var fp  = await getFingerprint();
    var res = await fetch(URLS.verifyDevice, {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({token: CFG.token, fingerprint: fp})
    });
    var data = await res.json();
    if (data.success) {
      overlay.classList.add('hidden');
    } else if (data.locked) {
      silentReport('device_locked', T.ov_locked_title);
      ovSpinner.style.display = 'none';
      overlay.innerHTML = '<div class="ov-locked">' +
        '<div class="ov-icon">\uD83D\uDD12</div>' +
        '<div class="ov-title">' + T.ov_locked_title + '</div>' +
        '<div class="ov-sub">' + T.ov_locked_msg + '</div>' +
        waButtonHTML(T.ov_locked_title + ' - ' + T.ov_locked_msg, true) +
        '</div>';
    } else {
      silentReport('device_verify_fail', data.message || T.ov_error_msg);
      ovSpinner.style.display = 'none';
      ovTitle.textContent = T.ov_error_title;
      ovSub.textContent   = data.message || T.ov_error_msg;
      var waBtn = document.createElement('div');
      waBtn.innerHTML = waButtonHTML(T.ov_error_title + ' - ' + (data.message || T.ov_error_msg), true);
      overlay.appendChild(waBtn.firstChild);
    }
  } catch(e) { silentReport('device_verify_exception', e.message || 'unknown'); overlay.classList.add('hidden'); }
}
verifyDevice();

// ================================================================
// CLOCK
// ================================================================
(function tick(){
  var n = new Date();
  var d = document.getElementById('hdrDate');
  var t = document.getElementById('hdrTime');
  if (d) d.textContent = n.toLocaleDateString(T.locale, {weekday:'long', day:'numeric', month:'long'});
  if (t) t.textContent = n.toLocaleTimeString(T.locale);
  setTimeout(tick, 1000);
})();

// ================================================================
// WORK TIMER
// ================================================================
if (STATE.todayStatus === 'checked_in' && STATE.checkInTime) {
  var _t0 = new Date(STATE.checkInTime).getTime();
  (function tick(){
    var d = Date.now() - _t0;
    var h = Math.floor(d / 3600000);
    var m = Math.floor((d % 3600000) / 60000);
    var s = Math.floor((d % 60000) / 1000);
    var el = document.getElementById('workTimer');
    if (el) el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    setTimeout(tick, 1000);
  })();
}

// ================================================================
// AUTO CHECKOUT — at check_out_start_time
// ================================================================
var autoCheckOutDone = false;
if (STATE.todayStatus === 'checked_in' && STATE.coStart) {
  var _coP = STATE.coStart.split(':');
  (function tick(){
    if (autoCheckOutDone) return;
    var now = new Date(), tgt = new Date();
    tgt.setHours(+_coP[0], +_coP[1], 0, 0);
    if (tgt < now && +_coP[0] < 6) tgt.setDate(tgt.getDate() + 1);
    if (now >= tgt) {
      autoCheckOutDone = true;
      submitAttendance('out', false);
      return;
    }
    setTimeout(tick, 5000);
  })();
}

// ================================================================
// HAVERSINE & BEARING
// ================================================================
function haversine(la1, lo1, la2, lo2) {
  var R = 6371000, dL = (la2 - la1) * Math.PI / 180, dO = (lo2 - lo1) * Math.PI / 180;
  var a = Math.sin(dL/2)**2 + Math.cos(la1*Math.PI/180) * Math.cos(la2*Math.PI/180) * Math.sin(dO/2)**2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}
// Returns bearing in RADIANS from (la1,lo1) to (la2,lo2)
function bearingCalc(la1, lo1, la2, lo2) {
  var dO = (lo2 - lo1) * Math.PI / 180;
  var y = Math.sin(dO) * Math.cos(la2 * Math.PI / 180);
  var x = Math.cos(la1*Math.PI/180) * Math.sin(la2*Math.PI/180) -
          Math.sin(la1*Math.PI/180) * Math.cos(la2*Math.PI/180) * Math.cos(dO);
  return Math.atan2(y, x); // radians, 0=east CCW ... we'll convert
}

// ================================================================
// RADAR CANVAS — DARK THEME, Employee=Center, Branch=Target
// ================================================================
var canvas = document.getElementById('radarCanvas');
var ctx = canvas ? canvas.getContext('2d') : null;
var dpr = window.devicePixelRatio || 1;
var sweepA = 0;
var pulseRings = [];
var radarParticles = [];
var frameCount = 0;
var sweepSpeed = 0.012;

// Branch logo
var branchLogoImg = new Image();
branchLogoImg.src = URLS.logoUrl;
var branchLogoLoaded = false;
branchLogoImg.onload = function(){ branchLogoLoaded = true; };

function spawnParticles() {
  for (var i = 0; i < 8; i++) {
    radarParticles.push({
      angle: Math.random() * Math.PI * 2,
      dist: 0.2 + Math.random() * 0.75,
      life: 0.5 + Math.random() * 1.5,
      maxLife: 0.5 + Math.random() * 1.5,
      size: 1 + Math.random() * 1.5,
      brightness: 0.2 + Math.random() * 0.5
    });
  }
}
spawnParticles();

function resizeCanvas() {
  if (!canvas) return;
  dpr = window.devicePixelRatio || 1;
  var wrap = canvas.parentElement;
  var sz = Math.min(wrap.clientWidth, wrap.clientHeight);
  canvas.width = sz * dpr; canvas.height = sz * dpr;
  canvas.style.width = sz + 'px'; canvas.style.height = sz + 'px';
}

// ── Light theme colors (semi-transparent to show satellite map) ──
var RC = {
  bg:           'rgba(0,0,0,0)',
  grid:         'rgba(59,130,246,0.06)',
  ring:         'rgba(59,130,246,0.1)',
  ringOuter:    'rgba(59,130,246,0.22)',
  ringLabel:    'rgba(59,130,246,0.5)',
  cross:        'rgba(59,130,246,0.08)',
  sweepHi:      'rgba(59,130,246,0.12)',
  sweepMid:     'rgba(59,130,246,0.06)',
  sweepLo:      'rgba(59,130,246,0.02)',
  sweepZero:    'rgba(59,130,246,0)',
  sweepLine:    'rgba(59,130,246,0.4)',
  label:        'rgba(100,116,139,0.5)',
  tickMaj:      'rgba(59,130,246,0.35)',
  tickMin:      'rgba(59,130,246,0.15)',
  centerGlow:   'rgba(59,130,246,0.3)',
  insideC:      '#16A34A',
  outsideC:     '#DC2626',
  northFill:    'rgba(220,38,38,0.85)',
  northGlow:    'rgba(220,38,38,0.3)',
};

function drawRadar() {
  if (!ctx || !canvas) return;
  var sz = canvas.width / dpr, cx = sz/2, cy = sz/2, R = sz/2 - 2;
  var inside = (userDist !== null) && (userDist <= CFG.geofenceRadius);
  var hasLoc = locReady;
  frameCount++;

  // ── The entire radar rotates with device heading ──
  // smoothHeading is in degrees (0=north). We rotate the canvas so north stays north.
  var headingRad = -smoothHeading * Math.PI / 180;

  ctx.save(); ctx.scale(dpr, dpr); ctx.clearRect(0, 0, sz, sz);

  // ── Clip to circle ──
  ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI*2);
  ctx.save(); ctx.clip();

  // ── Dark overlay on satellite ──
  ctx.fillStyle = RC.bg;
  ctx.fillRect(0, 0, sz, sz);

  // ── Grid ──
  ctx.strokeStyle = RC.grid; ctx.lineWidth = 0.5;
  var gs = sz / 14;
  for (var i = gs; i < sz; i += gs) {
    ctx.beginPath(); ctx.moveTo(i, 0); ctx.lineTo(i, sz); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, i); ctx.lineTo(sz, i); ctx.stroke();
  }

  // ── Dynamic visible range based on employee distance ──
  // visibleRange = the real-world distance that maps to the outer ring
  var geoR = CFG.geofenceRadius || 500;
  var visibleRange;
  if (userDist !== null && userDist > 0) {
    // Show branch at ~70% of radius; minimum = geofence, add 30% padding
    visibleRange = Math.max(geoR, userDist * 1.4);
  } else {
    visibleRange = geoR * 1.5;
  }
  // Smooth zoom transition
  if (typeof drawRadar._prevRange === 'undefined') drawRadar._prevRange = visibleRange;
  drawRadar._prevRange += (visibleRange - drawRadar._prevRange) * 0.08;
  var smoothRange = drawRadar._prevRange;

  // ── Geofence ring (real radius) ──
  var geoRingPx = (geoR / smoothRange) * R;
  if (geoRingPx > 4 && geoRingPx < R) {
    ctx.strokeStyle = inside ? 'rgba(22,163,74,0.25)' : 'rgba(59,130,246,.2)';
    ctx.lineWidth = 1.5;
    ctx.setLineDash([4, 4]);
    ctx.beginPath(); ctx.arc(cx, cy, geoRingPx, 0, Math.PI*2); ctx.stroke();
    ctx.setLineDash([]);
    // Geofence label
    ctx.font = 'bold 7px Tajawal,sans-serif';
    ctx.fillStyle = inside ? 'rgba(22,163,74,0.6)' : 'rgba(220,38,38,0.5)';
    ctx.textAlign = 'left'; ctx.textBaseline = 'bottom';
    ctx.fillText(geoR + (isArabic ? 'م' : 'm'), cx + 3, cy - geoRingPx + 10);
  }

  // ── Concentric rings with distance labels (based on visible range) ──
  for (var i = 1; i <= 4; i++) {
    var rr = R * (i / 4);
    ctx.strokeStyle = i === 4 ? RC.ringOuter : RC.ring;
    ctx.lineWidth = i === 4 ? 1.2 : 0.6;
    ctx.setLineDash(i === 4 ? [] : [2, 4]);
    ctx.beginPath(); ctx.arc(cx, cy, rr, 0, Math.PI*2); ctx.stroke();
    ctx.setLineDash([]);
    var distLabel = Math.round(smoothRange * (i / 4));
    ctx.font = 'bold 7px Tajawal,sans-serif';
    ctx.fillStyle = RC.ringLabel;
    ctx.textAlign = 'left'; ctx.textBaseline = 'bottom';
    ctx.fillText(distLabel + (isArabic ? 'م' : 'm'), cx + 3, cy - rr + 10);
  }

  // ── Crosshairs ──
  ctx.strokeStyle = RC.cross; ctx.lineWidth = 0.5;
  ctx.beginPath(); ctx.moveTo(cx, cy-R); ctx.lineTo(cx, cy+R); ctx.stroke();
  ctx.beginPath(); ctx.moveTo(cx-R, cy); ctx.lineTo(cx+R, cy); ctx.stroke();

  // ── Particles ──
  for (var i = radarParticles.length - 1; i >= 0; i--) {
    var p = radarParticles[i];
    p.life -= 0.016;
    if (p.life <= 0) { radarParticles.splice(i, 1); continue; }
    var alpha = (p.life / p.maxLife) * p.brightness * 0.25;
    var px = cx + Math.cos(p.angle) * p.dist * R;
    var py = cy + Math.sin(p.angle) * p.dist * R;
    ctx.beginPath(); ctx.arc(px, py, p.size, 0, Math.PI*2);
    ctx.fillStyle = 'rgba(59,130,246,' + alpha + ')';
    ctx.fill();
  }
  if (frameCount % 40 === 0) spawnParticles();

  ctx.restore(); // end clip

  // ── Sweep ──
  ctx.save();
  ctx.beginPath(); ctx.arc(cx, cy, R-1, 0, Math.PI*2); ctx.clip();
  ctx.translate(cx, cy); ctx.rotate(sweepA);
  var sg = ctx.createConicGradient(-Math.PI/2, 0, 0);
  sg.addColorStop(0,    RC.sweepHi);
  sg.addColorStop(0.08, RC.sweepMid);
  sg.addColorStop(0.18, RC.sweepLo);
  sg.addColorStop(0.25, RC.sweepZero);
  sg.addColorStop(1,    RC.sweepZero);
  ctx.beginPath(); ctx.moveTo(0,0); ctx.arc(0,0,R-1,0,Math.PI*2); ctx.closePath();
  ctx.fillStyle = sg; ctx.fill();
  ctx.strokeStyle = RC.sweepLine; ctx.lineWidth = 1.5;
  ctx.shadowColor = 'rgba(59,130,246,0.5)'; ctx.shadowBlur = 5;
  ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(0, -(R-1)); ctx.stroke();
  ctx.shadowBlur = 0;
  ctx.restore();

  // ── Pulse rings from center ──
  if (frameCount % 100 === 0) pulseRings.push({r: 0, alpha: 0.3});
  ctx.save();
  ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI*2); ctx.clip();
  for (var i = pulseRings.length - 1; i >= 0; i--) {
    var pr = pulseRings[i];
    pr.r += 0.5; pr.alpha -= 0.003;
    if (pr.alpha <= 0) { pulseRings.splice(i, 1); continue; }
    ctx.strokeStyle = 'rgba(59,130,246,' + pr.alpha + ')';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(cx, cy, pr.r, 0, Math.PI*2); ctx.stroke();
  }
  ctx.restore();

  // ──────────────────────────────────────
  // BRANCH TARGET — appears on real bearing from employee
  // bearingAngle is geographic bearing (radians from north CW)
  // On canvas: 0 = up (north), rotate CW
  // Also rotate by device heading so it tracks real direction
  // ──────────────────────────────────────
  if (locReady && userDist !== null) {
    // Branch bearing on canvas (adjusted for heading)
    var bearAngleCanvas = branchBearing + headingRad - Math.PI/2;
    // Dynamic scale: pixels per meter based on smoothed visible range
    var targetBranchDist = Math.min((userDist / smoothRange) * R, R - 16);
    // Lerp branch distance for smooth zoom-in/out
    if (!smoothBearInit) {
      smoothBranchDist = targetBranchDist;
      smoothBearAngle  = bearAngleCanvas;
      smoothBearInit   = true;
    }
    smoothBranchDist += (targetBranchDist - smoothBranchDist) * 0.08;
    // Lerp bearing angle (handle wrap-around)
    var bearDiff = bearAngleCanvas - smoothBearAngle;
    while (bearDiff >  Math.PI) bearDiff -= Math.PI * 2;
    while (bearDiff < -Math.PI) bearDiff += Math.PI * 2;
    smoothBearAngle += bearDiff * 0.08;
    var branchDist = smoothBranchDist;

    var bx = cx + Math.cos(smoothBearAngle) * branchDist;
    var by = cy + Math.sin(smoothBearAngle) * branchDist;

    // Branch glow
    var bg = ctx.createRadialGradient(bx, by, 0, bx, by, 16);
    bg.addColorStop(0, inside ? 'rgba(22,163,74,0.3)' : 'rgba(249,115,22,0.3)');
    bg.addColorStop(1, inside ? 'rgba(22,163,74,0)' : 'rgba(249,115,22,0)');
    ctx.fillStyle = bg; ctx.beginPath(); ctx.arc(bx, by, 16, 0, Math.PI*2); ctx.fill();

    // Branch logo circle
    if (branchLogoLoaded) {
      var logoSize = 26;
      ctx.save();
      ctx.beginPath(); ctx.arc(bx, by, logoSize/2 + 2, 0, Math.PI*2);
      ctx.strokeStyle = inside ? 'rgba(22,163,74,0.5)' : 'rgba(249,115,22,0.5)';
      ctx.lineWidth = 2;
      ctx.shadowColor = inside ? 'rgba(22,163,74,0.4)' : 'rgba(249,115,22,0.4)';
      ctx.shadowBlur = 8; ctx.stroke(); ctx.shadowBlur = 0;
      ctx.beginPath(); ctx.arc(bx, by, logoSize/2, 0, Math.PI*2); ctx.clip();
      ctx.globalAlpha = 0.85;
      ctx.drawImage(branchLogoImg, bx - logoSize/2, by - logoSize/2, logoSize, logoSize);
      ctx.restore();
    } else {
      // Fallback: building icon dot
      ctx.beginPath(); ctx.arc(bx, by, 6, 0, Math.PI*2);
      ctx.fillStyle = inside ? RC.insideC : '#F97316'; ctx.fill();
      ctx.strokeStyle = 'rgba(255,255,255,0.8)'; ctx.lineWidth = 1.5; ctx.stroke();
    }

    // Pulse around branch
    var bPulse = 0.5 + 0.5 * Math.sin(frameCount * 0.04);
    ctx.beginPath(); ctx.arc(bx, by, 16 + bPulse * 4, 0, Math.PI*2);
    ctx.strokeStyle = inside ? 'rgba(22,163,74,' + (0.2 - bPulse*0.12) + ')' : 'rgba(249,115,22,' + (0.2 - bPulse*0.12) + ')';
    ctx.lineWidth = 1; ctx.stroke();

    // Direction arrow pointing to branch (from center)
    if (branchDist > 30) {
      var arrowDist = 20;
      var ax = cx + Math.cos(smoothBearAngle) * arrowDist;
      var ay = cy + Math.sin(smoothBearAngle) * arrowDist;
      ctx.save(); ctx.translate(ax, ay); ctx.rotate(smoothBearAngle);
      ctx.beginPath(); ctx.moveTo(6, 0); ctx.lineTo(-3, -3); ctx.lineTo(-3, 3); ctx.closePath();
      ctx.fillStyle = inside ? 'rgba(22,163,74,0.5)' : 'rgba(249,115,22,0.5)';
      ctx.fill(); ctx.restore();
    }
  }

  // ── CENTER: Employee icon (person) ──
  // Glow
  var cg = ctx.createRadialGradient(cx, cy, 0, cx, cy, 14);
  cg.addColorStop(0, RC.centerGlow);
  cg.addColorStop(1, 'rgba(59,130,246,0)');
  ctx.fillStyle = cg; ctx.beginPath(); ctx.arc(cx, cy, 14, 0, Math.PI*2); ctx.fill();
  // Person icon circle
  ctx.beginPath(); ctx.arc(cx, cy, 7, 0, Math.PI*2);
  ctx.fillStyle = '#3B82F6'; ctx.fill();
  ctx.strokeStyle = 'rgba(255,255,255,0.9)'; ctx.lineWidth = 1.5; ctx.stroke();
  // Person silhouette inside
  ctx.fillStyle = '#fff';
  ctx.beginPath(); ctx.arc(cx, cy - 2, 2.2, 0, Math.PI*2); ctx.fill();
  ctx.beginPath(); ctx.arc(cx, cy + 2.5, 3, Math.PI, 0); ctx.fill();
  // Pulse
  var cpulse = 0.5 + 0.5 * Math.sin(frameCount * 0.06);
  ctx.beginPath(); ctx.arc(cx, cy, 8 + cpulse * 5, 0, Math.PI*2);
  ctx.strokeStyle = 'rgba(59,130,246,' + (0.2 - cpulse*0.12) + ')';
  ctx.lineWidth = 1; ctx.stroke();

  // ── Compass North indicator (rotates with heading) ──
  if (compassSupported) {
    ctx.save(); ctx.translate(cx, cy);
    ctx.rotate(headingRad);
    // North arrow (points up = geographic north)
    ctx.beginPath();
    ctx.moveTo(0, -(R-3)); ctx.lineTo(-4, -(R-12)); ctx.lineTo(4, -(R-12));
    ctx.closePath();
    ctx.fillStyle = RC.northFill;
    ctx.shadowColor = RC.northGlow; ctx.shadowBlur = 5;
    ctx.fill(); ctx.shadowBlur = 0;
    ctx.font = 'bold 7px Tajawal,sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
    ctx.fillStyle = 'rgba(239,68,68,0.85)';
    ctx.fillText('N', 0, -(R-12));
    ctx.restore();
  }

  // ── Direction labels (static since radar rotates) ──
  ctx.font = 'bold 9px Tajawal,sans-serif';
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillStyle = RC.label;
  // These don't rotate — they're fixed screen positions
  // Actually, since radar rotates with compass, N/S/E/W should be at fixed geographic positions
  // They rotate with headingRad just like the north arrow
  // But for cleaner look, let's skip static labels — the N arrow is enough

  // ── Outer ring color ──
  ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI*2);
  var ringColor = inside ? 'rgba(22,163,74,.35)' : (hasLoc ? 'rgba(220,38,38,.25)' : 'rgba(59,130,246,.15)');
  ctx.strokeStyle = ringColor; ctx.lineWidth = 2;
  ctx.shadowColor = ringColor; ctx.shadowBlur = inside ? 6 : 3;
  ctx.stroke(); ctx.shadowBlur = 0;

  // ── Tick marks ──
  for (var i = 0; i < 12; i++) {
    var a = i * Math.PI * 2 / 12;
    var x1 = cx + Math.cos(a) * (R-1), y1 = cy + Math.sin(a) * (R-1);
    var tl = i % 3 === 0 ? 6 : 3;
    var x2 = cx + Math.cos(a) * (R-1-tl), y2 = cy + Math.sin(a) * (R-1-tl);
    ctx.strokeStyle = i % 3 === 0 ? RC.tickMaj : RC.tickMin;
    ctx.lineWidth = i % 3 === 0 ? 1.2 : 0.6;
    ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
  }

  ctx.restore();
}

// ================================================================
// ANIMATION LOOP
// ================================================================
function animate() {
  sweepA += sweepSpeed;
  smoothHeading = lerpAngle(smoothHeading, deviceHeading, LERP);

  // Info strip updates
  if (compassSupported) {
    var ci = document.getElementById('compassIndicator');
    var hi = document.getElementById('headingText');
    var idx = Math.round(((smoothHeading%360)+360)%360 / 45) % 8;
    var dirStr = Math.round(smoothHeading) + '° ' + T.compass_dirs[idx];
    if (ci) ci.textContent = '🧭 ' + dirStr;
    if (hi) { hi.textContent = dirStr; hi.parentElement.classList.add('active'); }
  }
  if (locReady && userDist !== null) {
    var bt = document.getElementById('bearingText');
    if (bt) {
      var bearDeg = ((branchBearing * 180 / Math.PI) + 360) % 360;
      var bIdx = Math.round(bearDeg / 45) % 8;
      bt.textContent = Math.round(bearDeg) + '° ' + T.compass_dirs[bIdx];
      bt.parentElement.classList.add('active');
    }
  }
  if (userAcc !== null) {
    var at = document.getElementById('accText');
    if (at) {
      at.textContent = '±' + Math.round(userAcc) + (isArabic ? ' م' : ' m');
      at.parentElement.classList.add('active');
    }
  }

  // Rotate satellite map to match compass heading (north always real north)
  if (radarMap && compassSupported) {
    var mapEl = document.getElementById('radarMap');
    if (mapEl) {
      mapEl.style.transform = 'rotate(' + (-smoothHeading) + 'deg)';
      // Counter-rotate user pin to stay upright
      var uPins = mapEl.querySelectorAll('.map-user-pin');
      for (var p = 0; p < uPins.length; p++) {
        uPins[p].style.transform = 'rotate(' + smoothHeading + 'deg)';
      }
      // Rotate branch arrows to point FROM user TOWARD branch
      var bPins = mapEl.querySelectorAll('.map-branch-pin');
      var bDeg = (branchBearing * 180 / Math.PI);
      for (var p = 0; p < bPins.length; p++) {
        bPins[p].style.transform = 'rotate(' + (smoothHeading + bDeg - 90) + 'deg)';
      }
    }
  }

  drawRadar();
  requestAnimationFrame(animate);
}
animate();

// ================================================================
// UI UPDATE
// ================================================================
function updateUI(dist) {
  var rad = CFG.geofenceRadius, inside = dist <= rad;
  var dot   = document.getElementById('distDot');
  var val   = document.getElementById('distValue');
  var guide = document.getElementById('guidanceBox');
  if (dot) dot.className = 'dist-dot ' + (inside ? 'inside' : 'outside');
  if (val) val.textContent = Math.round(dist);
  if (guide) {
    guide.className = 'guidance-box show ' + (inside ? 'inside' : 'outside');
    if (inside) {
      guide.textContent = T.inside_zone;
    } else {
      var rem = Math.round(dist - rad);
      guide.innerHTML = T.outside_zone_pre + '<strong>' + rem + '</strong>' + T.outside_zone_suf;
    }
  }
}

// ================================================================
// GPS
// ================================================================
var gpsPermissionDenied = false;

function initGPS() {
  if (!navigator.geolocation) { setGPS('error', T.gps_no_support); showGPSModal(); return; }
  setGPS('getting', T.gps_locating);
  navigator.geolocation.watchPosition(function(p) {
    userLat = p.coords.latitude; userLon = p.coords.longitude; userAcc = p.coords.accuracy; locReady = true;
    gpsPermissionDenied = false; hideGPSModal();
    // Distance FROM employee TO branch
    var dist = haversine(userLat, userLon, CFG.workLat, CFG.workLon);
    userDist = dist;
    // Bearing FROM employee TO branch (radians)
    branchBearing = bearingCalc(userLat, userLon, CFG.workLat, CFG.workLon);
    var a = userAcc < 50 ? T.gps_high_accuracy : userAcc < 150 ? T.gps_med_accuracy : T.gps_low_accuracy;
    setGPS('ready', a + ' ±' + Math.round(userAcc) + (isArabic ? ' م' : ' m'));
    updateUI(dist);
    updateSonar(dist);
    updateMapUser(userLat, userLon);
    document.querySelectorAll('.btn').forEach(function(b){ b.disabled = false; });
    // Auto check-in if inside geofence and not yet checked in
    if (!autoCheckInDone && STATE.todayStatus === 'none' && dist <= CFG.geofenceRadius) {
      autoCheckInDone = true;
      submitAttendance('in', false);
    }
  }, function(e) {
    var msgs = {1: T.gps_allow, 2: T.gps_enable, 3: T.gps_timeout};
    setGPS('error', msgs[e.code] || T.gps_error);
    silentReport('gps_error', msgs[e.code] || T.gps_error, 'code=' + e.code);
    if (e.code === 1) { gpsPermissionDenied = true; showGPSModal(); }
    document.querySelectorAll('.btn').forEach(function(b){ b.disabled = false; });
  }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 10000});
}

function setGPS(s, t) {
  var d = document.getElementById('gpsDot'), sp = document.getElementById('gpsText');
  if (d) d.className = 'gps-dot ' + s;
  if (sp) sp.textContent = t;
}

// ================================================================
// GPS MODAL
// ================================================================
function showGPSModal() {
  var m = document.getElementById('gpsModal');
  if (!m) {
    m = document.createElement('div'); m.id = 'gpsModal';
    m.innerHTML = '<div class="gps-banner-inner">' +
      '<div class="gps-banner-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3A8.994 8.994 0 0 0 13 3.06V1h-2v2.06A8.994 8.994 0 0 0 3.06 11H1v2h2.06A8.994 8.994 0 0 0 11 20.94V23h2v-2.06A8.994 8.994 0 0 0 20.94 13H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/></svg></div>' +
      '<div class="gps-banner-body">' +
      '<div class="gps-banner-title">' + T.gps_modal_title + '</div>' +
      '<div class="gps-banner-msg">' + T.gps_modal_msg + '</div>' +
      '<div class="gps-banner-actions">' +
      '<button class="gps-modal-btn" onclick="openLocationSettings()">' + T.gps_modal_btn + '</button>' +
      '<button class="gps-modal-retry" onclick="retryGPS()">' + T.gps_modal_retry + '</button>' +
      '</div></div></div>';
    document.body.insertBefore(m, document.body.firstChild);
  }
  m.classList.remove('hidden');
  requestAnimationFrame(function(){ document.body.style.paddingTop = m.offsetHeight + 'px'; });
}

function hideGPSModal() {
  var m = document.getElementById('gpsModal');
  if (m) { m.classList.add('hidden'); document.body.style.paddingTop = ''; }
}

function openLocationSettings() {
  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({name:'geolocation'}).then(function(result){
      if (result.state === 'prompt') {
        navigator.geolocation.getCurrentPosition(function(){ hideGPSModal(); }, function(){}, {enableHighAccuracy:true, timeout:10000});
      } else {
        window.location.href = 'app-settings:';
        setTimeout(function(){ retryGPS(); }, 1500);
      }
    });
  } else { retryGPS(); }
}

function retryGPS() {
  hideGPSModal();
  navigator.geolocation.getCurrentPosition(function(p) {
    userLat = p.coords.latitude; userLon = p.coords.longitude; userAcc = p.coords.accuracy; locReady = true;
    gpsPermissionDenied = false;
    var dist = haversine(userLat, userLon, CFG.workLat, CFG.workLon);
    userDist = dist; branchBearing = bearingCalc(userLat, userLon, CFG.workLat, CFG.workLon);
    updateUI(dist); updateSonar(dist); updateMapUser(userLat, userLon); setGPS('ready', T.gps_high_accuracy);
    document.querySelectorAll('.btn').forEach(function(b){ b.disabled = false; });
  }, function(e) {
    if (e.code === 1) { gpsPermissionDenied = true; showGPSModal(); }
  }, {enableHighAccuracy: true, timeout: 10000, maximumAge: 0});
}

setInterval(function() {
  if (!gpsPermissionDenied) return;
  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({name:'geolocation'}).then(function(result){
      if (result.state === 'granted') { gpsPermissionDenied = false; hideGPSModal(); initGPS(); }
    });
  }
}, 3000);

// ================================================================
// SUBMIT ATTENDANCE
// ================================================================
function submitAttendance(type, manual) {
  if (manual === undefined) manual = true;
  var btns = document.querySelectorAll('.btn');
  btns.forEach(function(b){ b.disabled = true; });
  var sp = document.getElementById('spinner'); if (sp && manual) sp.classList.add('show');
  if (manual) hideMsg();
  function doPost(la, lo, ac) {
    var ep = type === 'in' ? URLS.checkIn : type === 'out' ? URLS.checkOut : URLS.ot;
    fetch(ep, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({token: CFG.token, latitude: la, longitude: lo, accuracy: ac || 0})})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (sp) sp.classList.remove('show');
      if (d.success) { showMsg('success', d.message); setTimeout(function(){ location.reload(); }, 2000); }
      else { silentReport('attendance_fail', d.message, type); if (manual) showMsg('error', d.message); btns.forEach(function(b){ b.disabled = false; }); }
    }).catch(function(){
      silentReport('attendance_network', T.msg_error_network, type);
      if (sp) sp.classList.remove('show');
      if (manual) showMsg('error', T.msg_error_network);
      btns.forEach(function(b){ b.disabled = false; });
    });
  }
  if (userLat && userLon) { doPost(userLat, userLon, userAcc); }
  else {
    navigator.geolocation.getCurrentPosition(
      function(p) { userLat = p.coords.latitude; userLon = p.coords.longitude; userAcc = p.coords.accuracy; doPost(userLat, userLon, userAcc); },
      function() { silentReport('gps_submit_fail', T.gps_failed, type); if (sp) sp.classList.remove('show'); showMsg('error', T.gps_failed); btns.forEach(function(b){ b.disabled = false; }); },
      {enableHighAccuracy: true, timeout: 10000, maximumAge: 0});
  }
}
function showMsg(t, x) {
  var e = document.getElementById('messageBox');
  if (e) {
    e.className = 'message ' + t + ' show';
    if (t === 'error') {
      e.innerHTML = '<span>' + x + '</span>' + waButtonHTML(x, false);
    } else {
      e.textContent = x;
    }
  }
}
function hideMsg() { var e = document.getElementById('messageBox'); if (e) e.className = 'message'; }

// ================================================================
// COMPASS — rotates the whole radar with phone
// ================================================================
function initCompass() {
  if (!window.DeviceOrientationEvent) return;
  if ('ondeviceorientationabsolute' in window) {
    window.addEventListener('deviceorientationabsolute', handleOrientation);
    compassSupported = true;
  } else if (typeof DeviceOrientationEvent.requestPermission === 'function') {
    document.addEventListener('touchstart', function _ask() {
      DeviceOrientationEvent.requestPermission()
        .then(function(state) { if (state === 'granted') { window.addEventListener('deviceorientation', handleOrientation); compassSupported = true; } })
        .catch(function(){});
      document.removeEventListener('touchstart', _ask);
    }, {once: true});
  } else {
    window.addEventListener('deviceorientation', handleOrientation);
    compassSupported = true;
  }
}
function handleOrientation(event) {
  if (event.webkitCompassHeading !== undefined) {
    deviceHeading = event.webkitCompassHeading;
  } else if (event.alpha !== null) {
    deviceHeading = (360 - event.alpha) % 360;
  }
}
initCompass();

// ================================================================
// SATELLITE MAP (Leaflet) — centered on EMPLOYEE
// ================================================================
var radarMap = null, radarUserMarker = null, radarBranchMarker = null;

function initRadarMap() {
  var el = document.getElementById('radarMap');
  if (!el || !window.L) return;

  // Center on employee position or branch as fallback
  var centerLat = userLat || CFG.workLat;
  var centerLon = userLon || CFG.workLon;

  radarMap = L.map('radarMap', {
    zoomControl: false, attributionControl: false,
    dragging: false, scrollWheelZoom: false, doubleClickZoom: false, touchZoom: false, keyboard: false
  }).setView([centerLat, centerLon], 17);
  L.tileLayer((URLS.tileSatellite || '../api/tile.php?l=satellite&z={z}&y={y}&x={x}'), {maxZoom: 19, maxNativeZoom: 18}).addTo(radarMap);
  // Ensure tiles render after container has size
  setTimeout(function(){ if(radarMap) radarMap.invalidateSize(); }, 200);
  setTimeout(function(){ if(radarMap) radarMap.invalidateSize(); }, 1000);
  setTimeout(function(){ if(radarMap) radarMap.invalidateSize(); }, 3000);

  // Branch marker — three animated arrows pointing toward branch
  var brIcon = L.divIcon({
    className: '',
    html: '<div class="map-branch-pin"><span class="arrow a1">&#x276F;</span><span class="arrow a2">&#x276F;</span><span class="arrow a3">&#x276F;</span></div>',
    iconSize: [40,40], iconAnchor: [20,20]
  });
  radarBranchMarker = L.marker([CFG.workLat, CFG.workLon], {icon: brIcon}).addTo(radarMap);

  // Geofence circle around branch — futuristic neon ring with glow
  var geoCircle = L.circle([CFG.workLat, CFG.workLon], {
    radius: CFG.geofenceRadius, color: '#00D4FF', fillColor: '#00D4FF', fillOpacity: 0.06, weight: 2, dashArray: '6,4',
    className: 'geo-fence-ring'
  }).addTo(radarMap);
  // Inner glow circle
  L.circle([CFG.workLat, CFG.workLon], {
    radius: CFG.geofenceRadius * 0.98, color: 'rgba(0,212,255,.2)', fillColor: 'transparent', fillOpacity: 0, weight: 4, dashArray: '0'
  }).addTo(radarMap);
}

function distToZoom(dist) {
  // Higher zoom = more detail. zoom 19≈25m, 18≈50m, 16≈200m, 14≈800m
  if (dist <= 30) return 19;
  if (dist <= 80) return 18;
  if (dist >= 10000) return 10;
  return Math.round(18 - Math.log2(dist / 50));
}

var _lastMapZoom = 17;
var _lastMapLat  = null;
var _lastMapLon  = null;

function updateMapUser(la, lo) {
  if (!radarMap) return;
  var mapZoom = userDist ? distToZoom(userDist) : 16;
  var zoomChanged = (mapZoom !== _lastMapZoom);
  var posChanged  = (la !== _lastMapLat || lo !== _lastMapLon);
  if (posChanged || zoomChanged) {
    _lastMapLat = la; _lastMapLon = lo; _lastMapZoom = mapZoom;
    // Smooth fly with animation for zoom changes, quick pan for position
    radarMap.flyTo([la, lo], mapZoom, {
      animate: true,
      duration: zoomChanged ? 1.4 : 0.6,
      easeLinearity: 0.3
    });
  }
  if (radarUserMarker) {
    radarUserMarker.setLatLng([la, lo]);
  } else {
    var uIcon = L.divIcon({
      className: '',
      html: '<div class="map-user-pin"></div>',
      iconSize: [14,14], iconAnchor: [7,7]
    });
    radarUserMarker = L.marker([la, lo], {icon: uIcon}).addTo(radarMap);
  }
}

var leafletLoaded = false;
function loadLeaflet(cb) {
  if (leafletLoaded) { cb(); return; }
  var link = document.createElement('link');
  link.rel = 'stylesheet'; link.href = (URLS.leafletCss || '../assets/vendor/leaflet/leaflet.min.css');
  document.head.appendChild(link);
  var script = document.createElement('script');
  script.src = (URLS.leafletJs || '../assets/vendor/leaflet/leaflet.min.js');
  script.onload = function() { leafletLoaded = true; cb(); };
  script.onerror = function() { console.warn('Leaflet load failed'); };
  document.head.appendChild(script);
}

// ================================================================
// RADAR SONAR — proximity beep (closer = faster, inside = continuous)
// ================================================================
var audioCtx = null;
var sonarInterval = null;
var sonarActive = false;
var sonarContinuous = false;
var sonarOsc = null;
var sonarGain = null;

function initAudioCtx() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  if (audioCtx.state === 'suspended') audioCtx.resume();
}

function playSonarPing() {
  try {
    initAudioCtx();
    var osc = audioCtx.createOscillator();
    var gain = audioCtx.createGain();
    osc.connect(gain); gain.connect(audioCtx.destination);
    osc.type = 'sine';
    // التردد والمدة يتم ضبطها حسب المتغير العالمي sonarProximity (0 بعيد، 1 قريب)
    var proximity = typeof window.sonarProximity === 'number' ? window.sonarProximity : 0;
    var freq = 600 + (proximity * 600); // 600Hz بعيد، 1200Hz قريب
    var duration = 0.35 - (proximity * 0.18); // 0.35s بعيد، 0.17s قريب
    var volume = 0.08 + (proximity * 0.18); // 0.08 بعيد، 0.26 قريب
    osc.frequency.value = freq;
    var t = audioCtx.currentTime;
    gain.gain.setValueAtTime(0, t);
    gain.gain.linearRampToValueAtTime(volume, t + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.001, t + duration);
    osc.start(t); osc.stop(t + duration);
  } catch(e){}
}

function startContinuousTone() {
  if (sonarContinuous) return;
  try {
    initAudioCtx();
    sonarOsc = audioCtx.createOscillator();
    sonarGain = audioCtx.createGain();
    sonarOsc.connect(sonarGain); sonarGain.connect(audioCtx.destination);
    sonarOsc.type = 'sine'; sonarOsc.frequency.value = 1400;
    sonarGain.gain.setValueAtTime(0.12, audioCtx.currentTime);
    sonarOsc.start();
    sonarContinuous = true;
  } catch(e){}
}

function stopContinuousTone() {
  if (!sonarContinuous) return;
  try {
    if (sonarGain) sonarGain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1);
    if (sonarOsc) setTimeout(function(){ try { sonarOsc.stop(); } catch(e){} }, 150);
    sonarOsc = null; sonarGain = null; sonarContinuous = false;
  } catch(e){}
}

function updateSonar(dist) {
  if (!audioCtx) return;
  var rad = CFG.geofenceRadius || 500;
  var inside = dist <= rad;

  // حساب نسبة القرب (0 بعيد، 1 قريب)
  var proximity = 0;
  if (!inside) {
    var excess = dist - rad;
    var maxRange = rad * 3;
    if (excess < maxRange) {
      proximity = 1 - (excess / maxRange);
    } else {
      proximity = 0;
    }
  } else {
    proximity = 1;
  }
  window.sonarProximity = proximity;

  if (inside) {
    // عند الدخول للنطاق: أوقف صوت الرادار وشغل موسيقى سنوب دوغ
    if (sonarInterval) { clearInterval(sonarInterval); sonarInterval = null; }
    stopContinuousTone();
    playSnoopMusic();
  } else {
    // خارج النطاق: أوقف الموسيقى وشغل صوت الرادار
    stopSnoopMusic();
    stopContinuousTone();
    var interval = 2000 - (proximity * 1800);
    interval = Math.max(200, Math.min(2000, interval));
    if (sonarInterval) clearInterval(sonarInterval);
    sonarInterval = setInterval(playSonarPing, interval);
    sonarActive = true;
  }
}

// --- تشغيل موسيقى سنوب دوغ عند الدخول للنطاق ---
var snoopAudio = null;
function playSnoopMusic() {
  if (snoopAudio && !snoopAudio.paused) return;
  if (!snoopAudio) {
    snoopAudio = new Audio('/assets/audio/snoop-dogg-drop-it-like-its-hot.mp3');
    snoopAudio.loop = true;
    snoopAudio.volume = 1.0;
  }
  snoopAudio.currentTime = 0;
  snoopAudio.play().catch(function(){});
}
function stopSnoopMusic() {
  if (snoopAudio && !snoopAudio.paused) {
    snoopAudio.pause();
    snoopAudio.currentTime = 0;
  }
}

function playRadarSound() {
  try {
    initAudioCtx();
    playSonarPing();
  } catch(e) {}
}

// ================================================================
// KEEP-ALIVE
// ================================================================
var SILENT_MP3 = 'data:audio/mp3;base64,SUQzBAAAAAABEVRYWFgAAAAtAAADY29tbWVudABCaWdTb3VuZEJhbmsuY29tIC8gTGFTb25vdGhlcXVlLm9yZwBURU5DAAAAHQAAA1N3aXRjaCBQbHVzIMKpIE5DSCBTb2Z0d2FyZQBUSVQyAAAABgAAAzIyMzUAVFNTRQAAAA8AAANMYXZmNTcuODMuMTAwAAAAAAAAAAAAAAD/80DEAAAAA0gAAAAATEFNRTMuMTAwVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsRbAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVQ==';
var keepAliveAudio = null;
var wakeLockRef = null;

function startKeepAlive() {
  try {
    keepAliveAudio = new Audio(SILENT_MP3);
    keepAliveAudio.loop = true; keepAliveAudio.volume = 0.01;
    keepAliveAudio.play().catch(function(){});
  } catch(e){}
  if ('wakeLock' in navigator) {
    navigator.wakeLock.request('screen').then(function(lock) { wakeLockRef = lock; }).catch(function(){});
  }
}

document.addEventListener('visibilitychange', function() {
  if (document.visibilityState === 'visible' && !wakeLockRef) {
    if ('wakeLock' in navigator) {
      navigator.wakeLock.request('screen').then(function(lock) { wakeLockRef = lock; }).catch(function(){});
    }
  }
});

// ================================================================
// INIT
// ================================================================
window.addEventListener('load', function() {
  resizeCanvas(); initGPS(); playRadarSound(); startKeepAlive();
  loadLeaflet(function(){ initRadarMap(); });
  document.addEventListener('touchstart', function _t() { playRadarSound(); document.removeEventListener('touchstart', _t); }, {once: true});
  document.addEventListener('click', function _c() { playRadarSound(); document.removeEventListener('click', _c); }, {once: true});
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(URLS.worker).catch(function() {
      navigator.serviceWorker.register(URLS.sw).catch(function(){});
    });
  }
});
window.addEventListener('resize', resizeCanvas);

// Auto-recovery + silent reporting
window.addEventListener('error', function(e) {
  silentReport('js_error', (e.message || '') + ' @ ' + (e.filename || '') + ':' + (e.lineno || ''));
  if (e.message && /net::ERR_QUIC|ERR_CONNECTION|ERR_NETWORK/i.test(e.message)) {
    setTimeout(function(){ location.reload(); }, 1500);
  }
});
window.addEventListener('unhandledrejection', function(e) {
  silentReport('promise_error', String(e.reason || 'unhandled promise'));
});

// ── Expose globals ──
window.submitAttendance     = submitAttendance;
window.openLocationSettings = openLocationSettings;
window.retryGPS             = retryGPS;

})();
