<?php
/**
 * Premium Print Footer for Reports
 * Include at the bottom of report content (before admin_footer.php)
 */
?>
<div class="print-report-footer">
    <div class="prf-content">
        <div>
            <div class="prf-brand">
                <img src="<?= SITE_URL ?>/assets/images/loogo.png" alt="">
                <span>نظام الحضور والانصراف</span>
            </div>
            <div class="prf-info">
                <div>تاريخ التقرير: <?= date('Y/m/d') ?></div>
                <div>وقت الإصدار: <?= date('H:i:s') ?></div>
            </div>
        </div>
        <div style="display:flex;gap:50px">
            <div class="prf-sig">
                <div class="prf-sig-line">توقيع المدير</div>
            </div>
            <div class="prf-sig">
                <div class="prf-sig-line">توقيع مسؤول الموارد البشرية</div>
            </div>
        </div>
    </div>
</div>
