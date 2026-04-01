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
    SessionManager.init(<?= (int)(getSystemSetting('session_timeout', '30')) ?>);
}

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

// Flash messages as Toasts
<?php if (!empty($_SESSION['flash_success'])): ?>
    Toast.success('<?= addslashes($_SESSION['flash_success']) ?>');
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    Toast.error('<?= addslashes($_SESSION['flash_error']) ?>');
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
</script>
