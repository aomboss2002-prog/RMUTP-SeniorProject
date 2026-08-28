<?php
function render_alert(string $type, string $message): void
{
    echo '<div class="alert alert-' . e($type) . ' alert-dismissible fade show" role="alert">';
    echo e($message);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    echo '</div>';
}
