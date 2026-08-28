<footer class="app-footer">
    <span>ระบบจัดการโครงงาน RMUTP</span>
    <span>พร้อมใช้งานจริง</span>
</footer>
<script src="<?= e(asset_url('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/jquery.dataTables.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/dataTables.responsive.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/responsive.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/dataTables.buttons.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/buttons.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/jszip/jszip.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/buttons.html5.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/datatables/buttons.print.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/chartjs/chart.umd.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(versioned_asset_url('js/app.js')) ?>"></script>
<script src="<?= e(versioned_asset_url('js/dashboard.js')) ?>"></script>
<script src="<?= e(versioned_asset_url('js/student.js')) ?>"></script>
<script src="<?= e(versioned_asset_url('js/notification.js')) ?>"></script>
<?php if (function_exists('storage_driver') && storage_driver() === 'vercel_blob'): ?>
<script src="<?= e(versioned_asset_url('js/vercel-blob-upload.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(versioned_asset_url('js/portal.js')) ?>"></script>
<script src="<?= e(versioned_asset_url('js/advisor.js')) ?>"></script>

