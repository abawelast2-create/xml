<?php
// =============================================================
// includes/admin_footer.php - التذييل المشترك لصفحات الإدارة (v4.0)
// =============================================================
// يُضمَّن قبل </body> في كل صفحة إدارة
// يضيف: مدير الجلسة، إشعارات Toast، تهيئة المظهر
// =============================================================
?>
<script>
// Session Timeout Manager
if (typeof SessionManager !== 'undefined') {
    SessionManager.init(<?= (int)(getSystemSetting('session_lifetime', '120')) ?>);
}

// Global AJAX session-expiry interceptor
(function(){
    const origFetch = window.fetch;
    window.fetch = function(...args) {
        return origFetch.apply(this, args).then(resp => {
            if (resp.ok) {
                const ct = resp.headers.get('content-type') || '';
                if (ct.includes('application/json')) {
                    const cloned = resp.clone();
                    cloned.json().then(data => {
                        if (data && data.session_expired) {
                            Toast.error('انتهت جلستك. جارٍ التحويل لتسجيل الدخول...');
                            setTimeout(() => { window.location.href = window.SITE_URL + '/admin/login.php'; }, 1500);
                        }
                    }).catch(() => {});
                }
            }
            return resp;
        });
    };
})();

// Clock
function tick(){
    const el = document.getElementById('topbarClock');
    if(el) el.textContent = new Date().toLocaleString('ar-SA');
}
tick();
setInterval(tick, 1000);

// Sidebar Toggle
function toggleSidebar(){
    document.getElementById('sidebar')?.classList.toggle('open');
    document.getElementById('sidebarOverlay')?.classList.toggle('show');
}
document.getElementById('sidebarOverlay')?.addEventListener('click', toggleSidebar);

// Notification Badge
function updateNotifBadge() {
    fetch(window.SITE_URL + '/api/notifications-count.php')
        .then(r => r.json())
        .then(d => {
            const topBadge = document.getElementById('topbarNotifBadge');
            const sideBadge = document.getElementById('sidebarNotifBadge');
            const count = d.count || 0;
            [topBadge, sideBadge].forEach(b => {
                if (!b) return;
                if (count > 0) {
                    b.textContent = count > 99 ? '99+' : count;
                    b.style.display = 'inline-block';
                } else {
                    b.style.display = 'none';
                }
            });
        })
        .catch(() => {});
}
updateNotifBadge();
setInterval(updateNotifBadge, 30000);

// Flash messages as Toasts
<?php if (!empty($_SESSION['flash_success'])): ?>
    Toast.success(<?= json_encode($_SESSION['flash_success']) ?>);
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    Toast.error(<?= json_encode($_SESSION['flash_error']) ?>);
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
</script>
