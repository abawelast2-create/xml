<?php
// =============================================================
// includes/admin_layout.php - الهيكل المشترك لصفحات الإدارة
// =============================================================
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="<?= !empty($_COOKIE['attendance_theme']) && $_COOKIE['attendance_theme'] === 'dark' ? 'dark' : '' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#F97316">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?> - لوحة التحكم</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/loogo.png">
    <link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/images/loogo.png">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/fonts/tajawal.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/dark-mode.css">
    <?php if (function_exists('\App\Middleware\CsrfProtection::metaTag')): ?>
    <?= \App\Middleware\CsrfProtection::metaTag() ?>
    <?php else: ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <?php endif; ?>
    <script>window.SITE_URL = '<?= SITE_URL ?>';</script>
    <script src="<?= SITE_URL ?>/assets/js/theme.js"></script>
</head>

<body>

    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo">
                <div class="brand-mark"><img src="<?= SITE_URL ?>/assets/images/loogo.png" alt="Logo" style="width:42px;height:42px;border-radius:10px;object-fit:cover"></div>
                <div>
                    <div class="brand-name"><?= SITE_NAME ?></div>
                    <div class="brand-sub">مرحباً، <?= htmlspecialchars($_SESSION['admin_name'] ?? 'مدير') ?></div>
                </div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">القائمة الرئيسية</div>
            <a href="dashboard.php" class="nav-item <?= ($activePage ?? '') === 'dashboard'  ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('dashboard', 18) ?></span> لوحة التحكم
            </a>
            <a href="branches.php" class="nav-item <?= ($activePage ?? '') === 'branches'   ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('branch', 18) ?></span> إدارة الفروع
            </a>
            <a href="employees.php" class="nav-item <?= ($activePage ?? '') === 'employees'  ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('employees', 18) ?></span> إدارة الموظفين
            </a>
            <a href="attendance.php" class="nav-item <?= ($activePage ?? '') === 'attendance' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('attendance', 18) ?></span> تقارير الحضور
            </a>
            <a href="late-report.php" class="nav-item <?= ($activePage ?? '') === 'late-report' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('attendance', 18) ?></span> تقرير التأخير
            </a>
            <a href="report-charts.php" class="nav-item <?= ($activePage ?? '') === 'report-charts' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('attendance', 18) ?></span> التقارير البيانية
            </a>
            <a href="leaves.php" class="nav-item <?= ($activePage ?? '') === 'leaves' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('absent', 18) ?></span> إدارة الإجازات
            </a>
            <a href="documents-expiry.php" class="nav-item <?= ($activePage ?? '') === 'documents-expiry' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('absent', 18) ?></span> وثائق المنتهية
            </a>
            <a href="tampering.php" class="nav-item <?= ($activePage ?? '') === 'tampering' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('lock', 18) ?></span> حالات التلاعب
            </a>
            <a href="secret-reports.php" class="nav-item <?= ($activePage ?? '') === 'secret-reports' ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('absent', 18) ?></span> التقارير السرية
            </a>
            <div class="nav-label">النظام</div>
            <a href="settings.php" class="nav-item <?= ($activePage ?? '') === 'settings'   ? 'active' : '' ?>">
                <span class="nav-icon"><?= svgIcon('settings', 18) ?></span> إعدادات النظام
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <span class="nav-icon"><?= svgIcon('logout', 18) ?></span> تسجيل الخروج
            </a>
        </div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="hamburger" onclick="toggleSidebar()" aria-label="القائمة">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                    </svg>
                </button>
                <div class="topbar-page-icon">
                    <?php
                    $pageIcons = ['dashboard' => 'dashboard', 'employees' => 'employees', 'attendance' => 'attendance', 'settings' => 'settings', 'branches' => 'branch'];
                    echo svgIcon($pageIcons[$activePage ?? 'dashboard'] ?? 'dashboard', 20);
                    ?>
                </div>
                <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
                <span class="topbar-badge">لوحة التحكم</span>
            </div>
            <div class="topbar-right">
                <span class="topbar-clock" id="topbarClock"></span>
                <button class="theme-toggle" onclick="ThemeManager.toggle()" title="تبديل المظهر">
                    <span class="icon-moon">🌙</span>
                    <span class="icon-sun">☀️</span>
                </button>
                <a href="logout.php" class="topbar-logout-btn" title="تسجيل الخروج">
                    <?= svgIcon('logout', 18) ?>
                    <span class="topbar-logout-text">خروج</span>
                </a>
            </div>
        </div>
        <!-- Bottom Navigation (mobile) -->
        <nav class="bottom-nav" id="bottomNav">
            <a href="dashboard.php" class="bnav-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
                <?= svgIcon('dashboard', 22) ?>
                <span>الرئيسية</span>
            </a>
            <a href="branches.php" class="bnav-item <?= ($activePage ?? '') === 'branches' ? 'active' : '' ?>">
                <?= svgIcon('branch', 22) ?>
                <span>الفروع</span>
            </a>
            <a href="employees.php" class="bnav-item <?= ($activePage ?? '') === 'employees' ? 'active' : '' ?>">
                <?= svgIcon('employees', 22) ?>
                <span>الموظفين</span>
            </a>
            <a href="attendance.php" class="bnav-item <?= ($activePage ?? '') === 'attendance' ? 'active' : '' ?>">
                <?= svgIcon('attendance', 22) ?>
                <span>الحضور</span>
            </a>
            <a href="settings.php" class="bnav-item <?= ($activePage ?? '') === 'settings' ? 'active' : '' ?>">
                <?= svgIcon('settings', 22) ?>
                <span>الإعدادات</span>
            </a>
        </nav>
    <!-- Dropdown Overlay (mobile bottom-sheet) -->
    <div class="dropdown-overlay" id="dropdownOverlay"></div>

        <div class="content fade-in">