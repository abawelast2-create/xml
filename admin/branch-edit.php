<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$branchId = (int)($_GET['id'] ?? $_POST['branch_id'] ?? 0);
if ($branchId <= 0) {
    header('Location: branches.php?msg=' . urlencode('رقم الفرع غير صالح') . '&t=error');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: branches.php?msg=' . urlencode('طلب غير صالح') . '&t=error');
        exit;
    }

    $name     = sanitize($_POST['name'] ?? '');
    $lat      = (float)($_POST['latitude'] ?? 0);
    $lon      = (float)($_POST['longitude'] ?? 0);
    $radius   = (int)($_POST['geofence_radius'] ?? 25);
    $wsTime   = sanitize($_POST['work_start_time'] ?? '08:00');
    $weTime   = sanitize($_POST['work_end_time'] ?? '16:00');
    $ciStart  = sanitize($_POST['check_in_start_time'] ?? '07:00');
    $ciEnd    = sanitize($_POST['check_in_end_time'] ?? '10:00');
    $coStart  = sanitize($_POST['check_out_start_time'] ?? '15:00');
    $coEnd    = sanitize($_POST['check_out_end_time'] ?? '20:00');
    $coShow   = (int)($_POST['checkout_show_before'] ?? 30);
    $allowOT  = (int)($_POST['allow_overtime'] ?? 1);
    $otAfter  = (int)($_POST['overtime_start_after'] ?? 60);
    $otMin    = (int)($_POST['overtime_min_duration'] ?? 30);
    $active   = (int)($_POST['is_active'] ?? 1);

    if (!$name) {
        header('Location: branch-edit.php?id=' . $branchId . '&msg=' . urlencode('اسم الفرع مطلوب') . '&t=error');
        exit;
    }

    // التحقق من صحة الإحداثيات
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        header('Location: branch-edit.php?id=' . $branchId . '&msg=' . urlencode('إحداثيات غير صالحة') . '&t=error');
        exit;
    }
    if ($radius < 10 || $radius > 10000) {
        header('Location: branch-edit.php?id=' . $branchId . '&msg=' . urlencode('نصف قطر الجيوفينس يجب أن يكون بين 10 و 10000 متر') . '&t=error');
        exit;
    }

    $stmt = db()->prepare("UPDATE branches SET name=?, latitude=?, longitude=?, geofence_radius=?,
        work_start_time=?, work_end_time=?, check_in_start_time=?, check_in_end_time=?,
        check_out_start_time=?, check_out_end_time=?, checkout_show_before=?,
        allow_overtime=?, overtime_start_after=?, overtime_min_duration=?, is_active=?
        WHERE id=?");

    $stmt->execute([
        $name,
        $lat,
        $lon,
        $radius,
        $wsTime,
        $weTime,
        $ciStart,
        $ciEnd,
        $coStart,
        $coEnd,
        $coShow,
        $allowOT,
        $otAfter,
        $otMin,
        $active,
        $branchId
    ]);

    header('Location: branches.php?msg=' . urlencode('تم تحديث الفرع «' . $name . '»') . '&t=success');
    exit;
}

$stmt = db()->prepare('SELECT * FROM branches WHERE id = ? LIMIT 1');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();

if (!$branch) {
    header('Location: branches.php?msg=' . urlencode('الفرع غير موجود') . '&t=error');
    exit;
}

$pageTitle  = 'تعديل الفرع';
$activePage = 'branches';
$message    = !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
$msgType    = $_GET['t'] ?? 'success';
$csrf       = generateCsrfToken();

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon.png',
    iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon-2x.png',
    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
});
</script>

<style>
    .branch-edit-wrap {
        max-width: 980px;
        margin: 0 auto;
    }

    .branch-edit-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .branch-edit-head {
        background: linear-gradient(135deg, var(--primary), var(--primary-d));
        color: #fff;
        padding: 16px 20px;
        font-size: 1.05rem;
        font-weight: 700;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .branch-edit-body {
        padding: 18px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-bottom: 14px;
    }

    .form-row.col3 {
        grid-template-columns: 1fr 1fr 1fr;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--text2);
        margin-bottom: 4px;
    }

    .form-control {
        padding: 8px 12px;
        border: 1.5px solid var(--border);
        border-radius: 8px;
        font-family: inherit;
        font-size: .88rem;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(249, 115, 22, .15);
    }

    .form-section {
        font-size: .82rem;
        font-weight: 700;
        color: var(--primary-d);
        margin: 16px 0 8px;
        padding: 6px 0;
        border-bottom: 2px solid var(--primary-l);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    #branchMapEditPage {
        width: 100%;
        height: 320px;
        border-radius: 10px;
        margin-bottom: 10px;
        border: 2px solid var(--border);
        overflow: hidden;
        position: relative;
        z-index: 0;
    }

    .branch-edit-foot {
        padding: 14px 18px;
        border-top: 1px solid var(--border);
        background: var(--surface2);
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    @media (max-width: 900px) {
        .form-row,
        .form-row.col3 {
            grid-template-columns: 1fr;
        }
        #branchMapEditPage {
            height: 250px;
        }
        .branch-edit-body {
            padding: 12px;
        }
        .branch-edit-head {
            padding: 12px 14px;
            font-size: .95rem;
        }
        .branch-edit-foot {
            flex-direction: column;
        }
        .branch-edit-foot .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= $message ?></div>
<?php endif; ?>

<div class="branch-edit-wrap">
    <div class="branch-edit-card">
        <div class="branch-edit-head">
            <span>تعديل الفرع: <?= htmlspecialchars($branch['name']) ?></span>
            <a href="branches.php" class="btn btn-secondary btn-sm">رجوع</a>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="branch_id" value="<?= (int)$branch['id'] ?>">

            <div class="branch-edit-body">
                <div class="form-section">بيانات الفرع</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">اسم الفرع *</label>
                        <input class="form-control" name="name" value="<?= htmlspecialchars($branch['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">نصف قطر النطاق (م)</label>
                        <input class="form-control" type="number" name="geofence_radius" value="<?= (int)$branch['geofence_radius'] ?>" min="1">
                    </div>
                </div>

                <div class="form-section">
                    الموقع الجغرافي
                    <button type="button" class="btn btn-secondary btn-sm" onclick="useMyLocation()" style="margin-right:auto;font-size:.72rem;padding:3px 10px">📍 موقعي الحالي</button>
                </div>
                <div id="branchMapEditPage"></div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">خط العرض</label>
                        <input class="form-control" type="number" step="any" name="latitude" id="eLat" value="<?= htmlspecialchars((string)$branch['latitude']) ?>" style="direction:ltr" oninput="syncMapFromInputs()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">خط الطول</label>
                        <input class="form-control" type="number" step="any" name="longitude" id="eLon" value="<?= htmlspecialchars((string)$branch['longitude']) ?>" style="direction:ltr" oninput="syncMapFromInputs()">
                    </div>
                </div>

                <div class="form-section">أوقات الدوام
                    <button type="button" class="btn btn-green btn-sm" onclick="calcOptimal()" style="margin-right:auto;font-size:.72rem;padding:3px 10px">✨ النسب المثالية</button>
                </div>
                <div class="form-row col3">
                    <div class="form-group">
                        <label class="form-label">بدء الدوام</label>
                        <input class="form-control" type="time" name="work_start_time" id="eWS" value="<?= htmlspecialchars($branch['work_start_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نهاية الدوام</label>
                        <input class="form-control" type="time" name="work_end_time" id="eWE" value="<?= htmlspecialchars($branch['work_end_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">عرض الانصراف قبل (دقيقة)</label>
                        <input class="form-control" type="number" name="checkout_show_before" id="eCOShow" value="<?= (int)$branch['checkout_show_before'] ?>" min="0">
                    </div>
                </div>

                <div class="form-section">نوافذ التسجيل</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">بدء تسجيل الدخول</label>
                        <input class="form-control" type="time" name="check_in_start_time" id="eCIS" value="<?= htmlspecialchars($branch['check_in_start_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نهاية تسجيل الدخول</label>
                        <input class="form-control" type="time" name="check_in_end_time" id="eCIE" value="<?= htmlspecialchars($branch['check_in_end_time']) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">بدء الانصراف</label>
                        <input class="form-control" type="time" name="check_out_start_time" id="eCOS" value="<?= htmlspecialchars($branch['check_out_start_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نهاية الانصراف</label>
                        <input class="form-control" type="time" name="check_out_end_time" id="eCOE" value="<?= htmlspecialchars($branch['check_out_end_time']) ?>">
                    </div>
                </div>

                <div class="form-section">الدوام الإضافي</div>
                <div class="form-row col3">
                    <div class="form-group">
                        <label class="form-label">مسموح</label>
                        <select class="form-control" name="allow_overtime" id="eOT">
                            <option value="1" <?= (int)$branch['allow_overtime'] === 1 ? 'selected' : '' ?>>نعم</option>
                            <option value="0" <?= (int)$branch['allow_overtime'] === 0 ? 'selected' : '' ?>>لا</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">يبدأ بعد (دقيقة)</label>
                        <input class="form-control" type="number" name="overtime_start_after" id="eOTAfter" value="<?= (int)$branch['overtime_start_after'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">الحد الأدنى (دقيقة)</label>
                        <input class="form-control" type="number" name="overtime_min_duration" id="eOTMin" value="<?= (int)$branch['overtime_min_duration'] ?>" min="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">الحالة</label>
                        <select class="form-control" name="is_active" id="eActive">
                            <option value="1" <?= (int)$branch['is_active'] === 1 ? 'selected' : '' ?>>مفعّل</option>
                            <option value="0" <?= (int)$branch['is_active'] === 0 ? 'selected' : '' ?>>معطّل</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="branch-edit-foot">
                <a href="branches.php" class="btn btn-secondary">إلغاء</a>
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<script>
    let editMap, editMarker;

    function initMap() {
        const lat = parseFloat(document.getElementById('eLat').value) || 24.7136;
        const lon = parseFloat(document.getElementById('eLon').value) || 46.6753;
        editMap = L.map('branchMapEditPage').setView([lat, lon], 16);
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
            attribution: '© Esri & contributors',
            maxZoom: 19
        }).addTo(editMap);

        editMarker = L.marker([lat, lon]).addTo(editMap);

        editMap.on('click', function(e) {
            placeMarker(e.latlng.lat, e.latlng.lng);
        });

        setTimeout(() => editMap.invalidateSize(), 120);
    }

    function placeMarker(lat, lon, updateInputs = true) {
        if (!editMap) return;
        if (editMarker) editMap.removeLayer(editMarker);
        editMarker = L.marker([lat, lon]).addTo(editMap);
        editMap.setView([lat, lon], editMap.getZoom() < 14 ? 16 : editMap.getZoom());

        if (updateInputs) {
            document.getElementById('eLat').value = lat.toFixed(8);
            document.getElementById('eLon').value = lon.toFixed(8);
        }
    }

    function syncMapFromInputs() {
        const lat = parseFloat(document.getElementById('eLat').value);
        const lon = parseFloat(document.getElementById('eLon').value);
        if (!isNaN(lat) && !isNaN(lon) && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180) {
            placeMarker(lat, lon, false);
        }
    }

    function useMyLocation() {
        if (!navigator.geolocation) {
            alert('المتصفح لا يدعم تحديد الموقع');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                placeMarker(pos.coords.latitude, pos.coords.longitude);
            },
            function(err) {
                alert('تعذّر تحديد الموقع: ' + err.message);
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    function calcOptimal() {
        const wsEl = document.getElementById('eWS');
        const weEl = document.getElementById('eWE');
        const cisEl = document.getElementById('eCIS');
        const cieEl = document.getElementById('eCIE');
        const cosEl = document.getElementById('eCOS');
        const coeEl = document.getElementById('eCOE');
        const coShowEl = document.getElementById('eCOShow');
        const otAfterEl = document.getElementById('eOTAfter');
        const otMinEl = document.getElementById('eOTMin');

        if (!wsEl.value || !weEl.value) {
            alert('حدد بدء الدوام ونهايته أولاً');
            return;
        }

        function toMin(t) {
            const p = t.split(':');
            return parseInt(p[0]) * 60 + parseInt(p[1]);
        }

        function toTime(m) {
            m = ((m % 1440) + 1440) % 1440;
            return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
        }

        const ws = toMin(wsEl.value);
        const we = toMin(weEl.value);
        let duration = we - ws;
        if (duration <= 0) duration += 1440;

        cisEl.value = toTime(ws - 30);
        cieEl.value = toTime(ws + 60);
        coShowEl.value = 15;
        cosEl.value = toTime(ws + duration - 15);
        coeEl.value = toTime(ws + duration + 30);
        otAfterEl.value = 30;
        otMinEl.value = 30;
    }

    function tick() {
        const el = document.getElementById('topbarClock');
        if (el) el.textContent = new Date().toLocaleString('ar-SA');
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    }

    document.getElementById('sidebarOverlay')?.addEventListener('click', toggleSidebar);

    setTimeout(initMap, 400);
    tick();
    setInterval(tick, 1000);
</script>

</div>
</div>
</body>

</html>
