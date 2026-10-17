<?php
$pagination = $pagination ?? ['current_page' => 1, 'total_pages' => 1];
$currentPage = (int) ($pagination['current_page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
$pageUrl = $pageUrl ?? static fn (int $page): string => '#';
$paginationLabel = $paginationLabel ?? 'Result pages';
$pages = array_values(array_unique(array_filter([
    1,
    $currentPage - 2,
    $currentPage - 1,
    $currentPage,
    $currentPage + 1,
    $currentPage + 2,
    $totalPages,
], static fn (int $page): bool => $page >= 1 && $page <= $totalPages)));
sort($pages);
?>
<?php if ($totalPages > 1): ?>
<nav class="d-flex justify-content-center px-3 py-3 border-top" aria-label="<?= esc($paginationLabel) ?>">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $currentPage <= 1 ? '#' : esc($pageUrl($currentPage - 1)) ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Previous</span></a></li>
        <?php $previousPage = 0; foreach ($pages as $pageNumber): ?>
            <?php if ($previousPage !== 0 && $pageNumber > $previousPage + 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <li class="page-item <?= $pageNumber === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= esc($pageUrl($pageNumber)) ?>"<?= $pageNumber === $currentPage ? ' aria-current="page"' : '' ?>><?= $pageNumber ?></a></li>
        <?php $previousPage = $pageNumber; endforeach; ?>
        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $currentPage >= $totalPages ? '#' : esc($pageUrl($currentPage + 1)) ?>" aria-label="Next page"><span class="d-none d-sm-inline me-1">Next</span><i class="bi bi-chevron-right"></i></a></li>
    </ul>
</nav>
<?php endif; ?>
