<?php
/**
 * Premium Print Header for Reports
 * Include at the top of report content (after admin_layout.php)
 * Required variables: $reportTitle (string)
 * Optional: $reportSubtitle, $reportMeta (array of strings)
 */
$_reportTitle    = $reportTitle ?? 'تقرير';
$_reportSubtitle = $reportSubtitle ?? '';
$_reportMeta     = $reportMeta ?? [];
?>
<div class="print-report-header">
    <div class="prh-inner">
        <div class="prh-date"><?= date('Y/m/d') ?></div>
        <div class="prh-center">
            <img src="<?= SITE_URL ?>/assets/images/loogo.png" alt="" class="prh-logo">
            <div class="prh-title"><?= htmlspecialchars($_reportTitle) ?></div>
            <?php if ($_reportSubtitle): ?>
            <div class="prh-subtitle"><?= htmlspecialchars($_reportSubtitle) ?></div>
            <?php endif; ?>
            <div class="prh-divider"></div>
        </div>
        <div style="width:55px"></div>
    </div>
    <?php if (!empty($_reportMeta)): ?>
    <div class="prh-meta">
        <?php foreach ($_reportMeta as $m): ?>
        <span><?= htmlspecialchars($m) ?></span>
        <?php endforeach; ?>
        <span>وقت الإصدار: <?= date('H:i') ?></span>
    </div>
    <?php endif; ?>
</div>
