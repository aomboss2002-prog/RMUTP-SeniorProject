<footer class="app-footer">
    <span>ระบบจัดการโครงงาน RMUTP</span>
    <span>พร้อมใช้งานจริง</span>
</footer>
<?php
$currentPage = (string) ($page ?? '');
$isPortalPage = str_starts_with($currentPage, 'portal-');
$isAdvisorPage = str_starts_with($currentPage, 'advisor-');
$isAdminPage = !$isPortalPage && !$isAdvisorPage
    && !in_array($currentPage, ['401', '403', '404', '422', '500'], true);
?>
<script defer src="<?= e(asset_url('vendor/jquery/jquery.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (page_uses_datatables($currentPage)): ?>
<script defer src="<?= e(asset_url('vendor/datatables/jquery.dataTables.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/dataTables.responsive.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/responsive.bootstrap5.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/dataTables.buttons.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/buttons.bootstrap5.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/jszip/jszip.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/buttons.html5.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/datatables/buttons.print.min.js')) ?>"></script>
<?php endif; ?>
<?php if (page_uses_charts($currentPage)): ?>
<script defer src="<?= e(asset_url('vendor/chartjs/chart.umd.js')) ?>"></script>
<?php endif; ?>
<script defer src="<?= e(asset_url('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script defer src="<?= e(versioned_asset_url('js/app.js')) ?>"></script>
<?php if ($currentPage === 'dashboard'): ?>
<script defer src="<?= e(versioned_asset_url('js/dashboard.js')) ?>"></script>
<?php endif; ?>
<?php if ($isAdminPage): ?>
<script defer src="<?= e(versioned_asset_url('js/student.js')) ?>"></script>
<script defer src="<?= e(versioned_asset_url('js/notification.js')) ?>"></script>
<?php endif; ?>
<?php if (page_uses_blob_upload($currentPage) && function_exists('storage_driver') && storage_driver() === 'vercel_blob'): ?>
<script defer src="<?= e(versioned_asset_url('js/vercel-blob-upload.js')) ?>"></script>
<?php endif; ?>
<?php if ($isPortalPage): ?>
<script defer src="<?= e(versioned_asset_url('js/portal.js')) ?>"></script>
<?php elseif ($isAdvisorPage): ?>
<script defer src="<?= e(versioned_asset_url('js/advisor.js')) ?>"></script>
<?php endif; ?>

