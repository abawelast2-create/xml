<?php
// =============================================================
// admin/settings.php - إعدادات النظام الشاملة
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'إعدادات النظام';
$activePage = 'settings';
$message    = '';
$msgType    = '';

// =================== حفظ الإعدادات ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'طلب غير صالح'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_geo') {
            setSystemSetting('work_latitude',       sanitize($_POST['work_latitude'] ?? '0'));
            setSystemSetting('work_longitude',      sanitize($_POST['work_longitude'] ?? '0'));
            setSystemSetting('geofence_radius',     sanitize($_POST['geofence_radius'] ?? '500'));
            $message = 'تم حفظ إعدادات الموقع الجغرافي'; $msgType = 'success';
        }

        if ($action === 'save_time') {
            setSystemSetting('work_start_time',       sanitize($_POST['work_start_time'] ?? '08:00'));
            setSystemSetting('work_end_time',         sanitize($_POST['work_end_time'] ?? '16:00'));
            setSystemSetting('check_in_start_time',   sanitize($_POST['check_in_start']  ?? '07:00'));
            setSystemSetting('check_in_end_time',     sanitize($_POST['check_in_end']    ?? '10:00'));
            setSystemSetting('check_out_start_time',  sanitize($_POST['check_out_start'] ?? '15:00'));
            setSystemSetting('check_out_end_time',    sanitize($_POST['check_out_end']   ?? '20:00'));
            setSystemSetting('checkout_show_before',  sanitize($_POST['checkout_show_before'] ?? '30'));
            $message = 'تم حفظ إعدادات أوقات الدوام'; $msgType = 'success';
        }

        if ($action === 'save_overtime') {
            setSystemSetting('allow_overtime',        sanitize($_POST['allow_overtime'] ?? '0'));
            setSystemSetting('overtime_start_after',  sanitize($_POST['overtime_start_after'] ?? '60'));
            setSystemSetting('overtime_min_duration', sanitize($_POST['overtime_min_duration'] ?? '30'));
            $message = 'تم حفظ إعدادات الدوام الإضافي'; $msgType = 'success';
        }

        if ($action === 'save_general') {
            setSystemSetting('site_name',     sanitize($_POST['site_name'] ?? 'نظام الحضور'));
            setSystemSetting('company_name',  sanitize($_POST['company_name'] ?? ''));
            setSystemSetting('timezone',      sanitize($_POST['timezone'] ?? 'Asia/Riyadh'));
            $message = 'تم حفظ الإعدادات العامة'; $msgType = 'success';
        }

        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if (strlen($new) < 8) {
                $message = 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل'; $msgType = 'error';
            } elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)) {
                $message = 'كلمة المرور يجب أن تحتوي على حرف كبير وحرف صغير ورقم ورمز خاص'; $msgType = 'error';
            } elseif ($new !== $confirm) {
                $message = 'كلمة المرور الجديدة وتأكيدها غير متطابقتان'; $msgType = 'error';
            } else {
                $stmt = db()->prepare("SELECT password_hash FROM admins WHERE id = ?");
                $stmt->execute([$_SESSION['admin_id']]);
                $admin = $stmt->fetch();
                if (!$admin || !password_verify($current, $admin['password_hash'])) {
                    $message = 'كلمة المرور الحالية غير صحيحة'; $msgType = 'error';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    db()->prepare("UPDATE admins SET password_hash=? WHERE id=?")->execute([$hash, $_SESSION['admin_id']]);
                    auditLog('change_password', 'تم تغيير كلمة المرور');
                    $message = 'تم تغيير كلمة المرور بنجاح'; $msgType = 'success';
                }
            }
        }
    }
}

$csrf = generateCsrfToken();

// جلب جميع الإعدادات
$workLat   = getSystemSetting('work_latitude',       '24.572307');
$workLon   = getSystemSetting('work_longitude',      '46.602552');
$radius    = getSystemSetting('geofence_radius',     '500');

$workStart = getSystemSetting('work_start_time',     '08:00');
$workEnd   = getSystemSetting('work_end_time',       '16:00');
$ciStart   = getSystemSetting('check_in_start_time', '07:00');
$ciEnd     = getSystemSetting('check_in_end_time',   '10:00');
$coStart   = getSystemSetting('check_out_start_time','15:00');
$coEnd     = getSystemSetting('check_out_end_time',  '20:00');
$coShowBefore = getSystemSetting('checkout_show_before', '30');

$allowOT   = getSystemSetting('allow_overtime',        '1');
$otAfter   = getSystemSetting('overtime_start_after',  '60');
$otMinDur  = getSystemSetting('overtime_min_duration', '30');

$siteName    = getSystemSetting('site_name',    SITE_NAME);
$companyName = getSystemSetting('company_name', '');
$timezone    = getSystemSetting('timezone',     'Asia/Riyadh');

// عدد الفروع التي تُخصّص إعداداتها
$branchCount = 0;
try {
    $branchCount = (int) db()->query("SELECT COUNT(*) FROM branches WHERE is_active=1")->fetchColumn();
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

<style>
    .settings-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .tab-btn {
        padding: 10px 20px;
        background: var(--surface);
        border: 1.5px solid var(--border);
        border-radius: 10px;
        color: var(--text2);
        cursor: pointer;
        font-family: inherit;
        font-size: .86rem;
        font-weight: 600;
        transition: all .18s;
    }
    .tab-btn:hover { background: var(--primary-xl); color: var(--primary-d); border-color: var(--primary-l); }
    .tab-btn.active {
        background: linear-gradient(135deg,var(--primary),var(--primary-d));
        color: #fff;
        border-color: var(--primary);
        box-shadow: 0 4px 12px rgba(249,115,22,.3);
    }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    #mapContainer {
        width: 100%;
        height: 480px;
        border-radius: 14px;
        margin-bottom: 16px;
        border: 2px solid var(--primary-l);
        box-shadow: 0 4px 16px rgba(249,115,22,.12);
    }
    .map-controls {
        display: flex;
        gap: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .map-info {
        background: var(--surface);
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 16px;
        font-size: .9rem;
        color: var(--text2);
    }
    .scope-note {
        background: linear-gradient(135deg, #FFFBEB, #FEF3C7);
        border: 1px solid #FCD34D;
        border-radius: 10px;
        padding: 12px 16px;
        margin-bottom: 16px;
        font-size: .84rem;
        color: #92400E;
        display: flex;
        align-items: flex-start;
        gap: 8px;
        line-height: 1.7;
    }
    .scope-note svg { flex-shrink: 0; margin-top: 3px; }
    .scope-note a { color: #D97706; font-weight: 700; text-decoration: none; }
    .scope-note a:hover { text-decoration: underline; }
    .radius-preview {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }
    .radius-slider {
        flex: 1;
        -webkit-appearance: none;
        height: 8px;
        background: var(--surface2);
        border-radius: 4px;
        outline: none;
    }
    .radius-slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 22px; height: 22px;
        background: linear-gradient(135deg,var(--primary),var(--primary-d));
        border-radius: 50%;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(249,115,22,.4);
    }
    .radius-value {
        min-width: 80px;
        text-align: center;
        font-weight: bold;
        color: var(--primary);
    }
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= $message ?></div>
<?php endif; ?>

<!-- التبويبات -->
<div class="settings-tabs">
    <button class="tab-btn active" onclick="showTab('geo')">الموقع الجغرافي</button>
    <button class="tab-btn" onclick="showTab('time')">أوقات الدوام</button>
    <button class="tab-btn" onclick="showTab('overtime')">الدوام الإضافي</button>
    <button class="tab-btn" onclick="showTab('general')">إعدادات عامة</button>
    <button class="tab-btn" onclick="showTab('password')">كلمة المرور</button>
</div>

<!-- =================== إعدادات الموقع الجغرافي =================== -->
<div class="tab-content active" id="tab-geo">
    <div class="card">
        <div class="card-header"><span class="card-title"><span class="card-title-bar"></span> إعدادات الموقع الجغرافي الافتراضية (Geofence)</span></div>
        
        <div class="scope-note">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#D97706"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
            <span>
                هذه الإعدادات هي <strong>الإعدادات الافتراضية العامة</strong> لجميع الموظفين الذين ليس لهم فرع مخصّص.
                <?php if ($branchCount > 0): ?>
                    يوجد حالياً <strong><?= $branchCount ?> فرع نشط</strong> يُمكن لكل منها تجاوز هذه الإعدادات.
                    <a href="branches.php">إدارة الفروع ←</a>
                <?php else: ?>
                    يمكنك إنشاء فروع بإعدادات مستقلة من <a href="branches.php">إدارة الفروع</a>.
                <?php endif; ?>
            </span>
        </div>

        <div class="map-info">
            <strong>تعليمات:</strong> انقر على الخريطة لتحديد الموقع الافتراضي لمركز العمل، أو اسحب العلامة.
            الدائرة تمثل نطاق السماح للتسجيل. هذا الموقع يُستخدم للموظفين غير المرتبطين بفرع معيّن.
        </div>

        <div class="map-controls">
            <button type="button" class="btn btn-secondary" onclick="useMyLocation()">
                موقعي الحالي
            </button>
            <button type="button" class="btn btn-secondary" onclick="searchLocation()">
                بحث عن مكان
            </button>
            <button type="button" class="btn btn-secondary" onclick="toggleSatellite()">
                طبقة القمر الصناعي
            </button>
        </div>

        <!-- الخريطة -->
        <div id="mapContainer"></div>

        <!-- شريط نصف القطر -->
        <div class="radius-preview">
            <label>نصف القطر:</label>
            <input type="range" class="radius-slider" id="radiusSlider" min="1" max="2000" step="1" value="<?= htmlspecialchars($radius) ?>">
            <span class="radius-value" id="radiusValue"><?= htmlspecialchars($radius) ?> م</span>
        </div>

        <form method="POST" id="geoForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_geo">
            
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">خط العرض (Latitude)</label>
                    <input class="form-control" type="text" name="work_latitude" id="workLat"
                           value="<?= htmlspecialchars($workLat) ?>" style="direction:ltr" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">خط الطول (Longitude)</label>
                    <input class="form-control" type="text" name="work_longitude" id="workLon"
                           value="<?= htmlspecialchars($workLon) ?>" style="direction:ltr" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">نصف قطر النطاق المسموح (متر)</label>
                    <input class="form-control" type="number" name="geofence_radius" id="radiusInput"
                           value="<?= htmlspecialchars($radius) ?>" min="1" max="5000" step="1">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">حفظ إعدادات الموقع</button>
        </form>
    </div>
</div>

<!-- =================== إعدادات أوقات الدوام =================== -->
<div class="tab-content" id="tab-time">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><span class="card-title-bar"></span> أوقات الدوام الافتراضية</span>
            <button type="button" class="btn btn-green btn-sm" onclick="calcOptimalSettings()" style="font-size:.78rem">✨ النسب المثالية</button>
        </div>
        
        <div class="scope-note">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#D97706"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
            <span>هذه المواعيد هي <strong>الافتراضية لجميع الفروع</strong>. كل فرع يُمكنه تخصيص مواعيده من <a href="branches.php">إدارة الفروع</a>.</span>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_time">

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">بداية الدوام الرسمي</label>
                    <input class="form-control" type="time" name="work_start_time" id="sWS" value="<?= $workStart ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">نهاية الدوام الرسمي</label>
                    <input class="form-control" type="time" name="work_end_time" id="sWE" value="<?= $workEnd ?>">
                </div>
            </div>

            <hr style="border-color:var(--border);margin:20px 0">

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">بداية وقت تسجيل الحضور</label>
                    <input class="form-control" type="time" name="check_in_start" id="sCIS" value="<?= $ciStart ?>">
                    <small style="color:var(--text3)">أول وقت يُسمح فيه بتسجيل الدخول</small>
                </div>
                <div class="form-group">
                    <label class="form-label">نهاية وقت تسجيل الحضور (متأخر)</label>
                    <input class="form-control" type="time" name="check_in_end" id="sCIE" value="<?= $ciEnd ?>">
                    <small style="color:var(--text3)">بعده يُعتبر الموظف متأخراً</small>
                </div>
                <div class="form-group">
                    <label class="form-label">بداية وقت تسجيل الانصراف</label>
                    <input class="form-control" type="time" name="check_out_start" id="sCOS" value="<?= $coStart ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">نهاية وقت تسجيل الانصراف</label>
                    <input class="form-control" type="time" name="check_out_end" id="sCOE" value="<?= $coEnd ?>">
                </div>
            </div>

            <div class="form-group" style="margin-top:16px">
                <label class="form-label">إظهار زر الانصراف قبل (دقيقة)</label>
                <input class="form-control" type="number" name="checkout_show_before" id="sCOShow" value="<?= $coShowBefore ?>" min="0" max="180" style="max-width:200px">
                <small style="color:var(--text3)">كم دقيقة قبل بداية وقت الانصراف يظهر الزر للموظف</small>
            </div>

            <button type="submit" class="btn btn-primary">حفظ أوقات الدوام</button>
        </form>
    </div>
</div>

<!-- =================== إعدادات الدوام الإضافي =================== -->
<div class="tab-content" id="tab-overtime">
    <div class="card">
        <div class="card-header"><span class="card-title"><span class="card-title-bar"></span> الدوام الإضافي (افتراضي)</span></div>
        
        <div class="scope-note">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#D97706"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
            <span>إعدادات الدوام الإضافي الافتراضية. يُمكن تخصيصها لكل فرع من <a href="branches.php">إدارة الفروع</a>.</span>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_overtime">

            <div class="form-group">
                <label class="form-label">السماح بالدوام الإضافي</label>
                <select class="form-control" name="allow_overtime" style="max-width:300px">
                    <option value="1" <?= $allowOT == '1' ? 'selected' : '' ?>>مفعّل</option>
                    <option value="0" <?= $allowOT == '0' ? 'selected' : '' ?>>معطّل</option>
                </select>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">يبدأ احتساب الإضافي بعد (دقيقة)</label>
                    <input class="form-control" type="number" name="overtime_start_after" value="<?= $otAfter ?>" min="0" max="300">
                    <small style="color:var(--text3)">بعد نهاية الدوام الرسمي</small>
                </div>
                <div class="form-group">
                    <label class="form-label">الحد الأدنى لاحتساب الإضافي (دقيقة)</label>
                    <input class="form-control" type="number" name="overtime_min_duration" value="<?= $otMinDur ?>" min="15" max="120">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">حفظ إعدادات الدوام الإضافي</button>
        </form>
    </div>
</div>

<!-- =================== إعدادات عامة =================== -->
<div class="tab-content" id="tab-general">
    <div class="card">
        <div class="card-header"><span class="card-title"><span class="card-title-bar"></span> إعدادات عامة</span></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_general">

            <div class="form-group">
                <label class="form-label">اسم النظام</label>
                <input class="form-control" type="text" name="site_name" value="<?= htmlspecialchars($siteName) ?>" style="max-width:400px">
            </div>

            <div class="form-group">
                <label class="form-label">اسم الشركة</label>
                <input class="form-control" type="text" name="company_name" value="<?= htmlspecialchars($companyName) ?>" style="max-width:400px">
            </div>

            <div class="form-group">
                <label class="form-label">المنطقة الزمنية</label>
                <select class="form-control" name="timezone" style="max-width:300px">
                    <option value="Asia/Riyadh" <?= $timezone === 'Asia/Riyadh' ? 'selected' : '' ?>>الرياض (UTC+3)</option>
                    <option value="Asia/Dubai" <?= $timezone === 'Asia/Dubai' ? 'selected' : '' ?>>دبي (UTC+4)</option>
                    <option value="Asia/Kuwait" <?= $timezone === 'Asia/Kuwait' ? 'selected' : '' ?>>الكويت (UTC+3)</option>
                    <option value="Africa/Cairo" <?= $timezone === 'Africa/Cairo' ? 'selected' : '' ?>>القاهرة (UTC+2)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">حفظ الإعدادات العامة</button>
        </form>
    </div>
</div>

<!-- =================== تغيير كلمة المرور =================== -->
<div class="tab-content" id="tab-password">
    <div class="card">
        <div class="card-header"><span class="card-title"><span class="card-title-bar"></span> تغيير كلمة المرور</span></div>
        <form method="POST" style="max-width:400px">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label class="form-label">كلمة المرور الحالية</label>
                <input class="form-control" type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label class="form-label">كلمة المرور الجديدة</label>
                <input class="form-control" type="password" name="new_password" required minlength="8"
                       pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                       title="8 أحرف كحد أدنى: حرف كبير + صغير + رقم + رمز خاص">
                <small style="color:var(--text-secondary)">يجب أن تحتوي على حرف كبير وحرف صغير ورقم ورمز خاص (مثال: Admin@1234)</small>
            </div>
            <div class="form-group">
                <label class="form-label">تأكيد كلمة المرور الجديدة</label>
                <input class="form-control" type="password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-primary">تغيير كلمة المرور</button>
        </form>
    </div>
</div>

<script>
// =================== التبويبات ===================
function showTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + tabName).classList.add('active');
}

// =================== Leaflet Map ===================
let map, marker, circle;
let satelliteLayer, streetLayer;
let isSatellite = false;

const initialLat = parseFloat(document.getElementById('workLat').value) || 24.572307;
const initialLon = parseFloat(document.getElementById('workLon').value) || 46.602552;
const initialRadius = parseInt(document.getElementById('radiusInput').value) || 500;

// طبقة الشوارع
streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap'
});

// طبقة القمر الصناعي (ESRI)
satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: '© ESRI'
});

// إنشاء الخريطة
map = L.map('mapContainer', {
    layers: [streetLayer],
    zoomControl: false
}).setView([initialLat, initialLon], 16);

// أزرار التكبير يمين
L.control.zoom({ position: 'topright' }).addTo(map);

// معلومات الإحداثيات أسفل الخريطة
const coordsInfo = L.control({ position: 'bottomleft' });
coordsInfo.onAdd = function() {
    const div = L.DomUtil.create('div');
    div.style.cssText = 'background:rgba(255,255,255,.92);padding:4px 10px;border-radius:8px;font-size:12px;color:#333;direction:ltr;font-family:monospace;';
    div.id = 'mapCoords';
    div.textContent = initialLat.toFixed(6) + ', ' + initialLon.toFixed(6);
    return div;
};
coordsInfo.addTo(map);

// علامة المركز
marker = L.marker([initialLat, initialLon], {
    draggable: true
}).addTo(map);

// دائرة النطاق
circle = L.circle([initialLat, initialLon], {
    radius: initialRadius,
    color: '#F97316',
    fillColor: '#F97316',
    fillOpacity: 0.12,
    weight: 2,
    dashArray: '8 4'
}).addTo(map);

// تحديث عند سحب العلامة
marker.on('dragend', function(e) {
    const pos = e.target.getLatLng();
    updateLocation(pos.lat, pos.lng);
});

// تحديث عند النقر على الخريطة
map.on('click', function(e) {
    updateLocation(e.latlng.lat, e.latlng.lng);
});

function updateLocation(lat, lng) {
    document.getElementById('workLat').value = lat.toFixed(8);
    document.getElementById('workLon').value = lng.toFixed(8);
    marker.setLatLng([lat, lng]);
    circle.setLatLng([lat, lng]);
    const coords = document.getElementById('mapCoords');
    if (coords) coords.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
}

function updateRadius(radius) {
    circle.setRadius(radius);
    document.getElementById('radiusValue').textContent = radius + ' م';
    document.getElementById('radiusInput').value = radius;
}

// شريط نصف القطر
document.getElementById('radiusSlider').addEventListener('input', function() {
    updateRadius(parseInt(this.value));
});

document.getElementById('radiusInput').addEventListener('change', function() {
    const val = parseInt(this.value);
    document.getElementById('radiusSlider').value = val;
    updateRadius(val);
});

// تبديل طبقة القمر الصناعي
function toggleSatellite() {
    if (isSatellite) {
        map.removeLayer(satelliteLayer);
        map.addLayer(streetLayer);
    } else {
        map.removeLayer(streetLayer);
        map.addLayer(satelliteLayer);
    }
    isSatellite = !isSatellite;
}

// استخدام موقعي الحالي
function useMyLocation() {
    if (!navigator.geolocation) {
        alert('المتصفح لا يدعم GPS');
        return;
    }
    navigator.geolocation.getCurrentPosition(function(pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        updateLocation(lat, lng);
        map.setView([lat, lng], 17);
        alert('تم تحديد موقعك. اضغط حفظ لتأكيده.');
    }, function() {
        alert('تعذّر الحصول على موقعك.');
    });
}

// بحث عن مكان باستخدام Nominatim
function searchLocation() {
    const query = prompt('أدخل اسم المكان أو العنوان:');
    if (!query) return;
    
    fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            if (data.length > 0) {
                const lat = parseFloat(data[0].lat);
                const lng = parseFloat(data[0].lon);
                updateLocation(lat, lng);
                map.setView([lat, lng], 16);
            } else {
                alert('لم يتم العثور على نتائج');
            }
        })
        .catch(() => alert('خطأ في البحث'));
}

// النسب المثالية — حساب تلقائي من بدء/نهاية الدوام
function calcOptimalSettings() {
    const wsEl = document.getElementById('sWS');
    const weEl = document.getElementById('sWE');
    if (!wsEl || !weEl || !wsEl.value || !weEl.value) {
        alert('حدد بدء الدوام ونهايته أولاً');
        return;
    }
    function toMin(t) { const p = t.split(':'); return parseInt(p[0]) * 60 + parseInt(p[1]); }
    function toTime(m) {
        m = ((m % 1440) + 1440) % 1440;
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    }
    const ws = toMin(wsEl.value);
    const we = toMin(weEl.value);
    let duration = we - ws;
    if (duration <= 0) duration += 1440;

    document.getElementById('sCIS').value = toTime(ws - 30);
    document.getElementById('sCIE').value = toTime(ws + 60);
    document.getElementById('sCOShow').value = 15;
    document.getElementById('sCOS').value = toTime(ws + duration - 15);
    document.getElementById('sCOE').value = toTime(ws + duration + 30);

    [document.getElementById('sCIS'), document.getElementById('sCIE'),
     document.getElementById('sCOS'), document.getElementById('sCOE'),
     document.getElementById('sCOShow')].forEach(el => {
        if (!el) return;
        el.style.transition = 'background .3s';
        el.style.background = '#D1FAE5';
        setTimeout(() => { el.style.background = ''; }, 1500);
    });
}

// الساعة
function tick() {
    const el = document.getElementById('topbarClock');
    if (el) el.textContent = new Date().toLocaleString('ar-SA');
}
tick();
setInterval(tick, 1000);

function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
document.getElementById('sidebarOverlay')?.addEventListener('click', toggleSidebar);
</script>

</div></div>
</body></html>
