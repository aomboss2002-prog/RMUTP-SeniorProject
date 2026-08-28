<?php
function render_pagination(int $pageNumber, int $totalPages, string $targetPage): void
{
    if ($totalPages <= 1) {
        return;
    }
    echo '<nav aria-label="Pagination"><ul class="pagination">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $pageNumber ? ' active' : '';
        echo '<li class="page-item' . $active . '"><a class="page-link" href="' . e(route_url($targetPage, ['p' => $i])) . '">' . $i . '</a></li>';
    }
    echo '</ul></nav>';
}
