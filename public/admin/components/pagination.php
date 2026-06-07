<?php
/**
 * Pagination — TailAdmin pattern
 * Expects: $paginationBaseUrl (string, e.g. admin base + page=members),
 *          $paginationCurrentPage (int, 1-based),
 *          $paginationTotalPages (int),
 *          $paginationTotal (int, optional, for "Showing X–Y of Z"),
 *          $paginationPerPage (int, optional)
 * Uses GET param 'p' for page number.
 */
if (!isset($paginationBaseUrl) || !isset($paginationTotalPages) || (int)$paginationTotalPages <= 1) {
    return;
}
$current  = (int)($paginationCurrentPage ?? 1);
$total    = (int)($paginationTotal       ?? 0);
$perPage  = (int)($paginationPerPage     ?? 25);
$sep      = strpos($paginationBaseUrl, '?') !== false ? '&' : '?';
$url      = fn ($p) => $paginationBaseUrl . $sep . 'p=' . $p;

// Build page number window (max 7 buttons shown)
$window = 2;
$pages  = [];
for ($i = 1; $i <= $paginationTotalPages; $i++) {
    if ($i === 1 || $i === $paginationTotalPages || abs($i - $current) <= $window) {
        $pages[] = $i;
    }
}

// Showing X–Y of Z
$start = ($current - 1) * $perPage + 1;
$end   = min($current * $perPage, $total);
?>
<nav class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-3 shadow-card dark:border-gray-700 dark:bg-gray-800" aria-label="Pagination">

    <!-- Showing info -->
    <p class="text-sm text-gray-500 dark:text-gray-400">
        <?php if ($total > 0): ?>
            Showing <span class="font-medium text-gray-700 dark:text-gray-200"><?= $start ?>–<?= $end ?></span>
            of <span class="font-medium text-gray-700 dark:text-gray-200"><?= number_format($total) ?></span>
        <?php else: ?>
            Page <span class="font-medium text-gray-700 dark:text-gray-200"><?= $current ?></span>
            of <span class="font-medium text-gray-700 dark:text-gray-200"><?= $paginationTotalPages ?></span>
        <?php endif; ?>
    </p>

    <!-- Page buttons -->
    <div class="flex items-center gap-1">
        <!-- Prev -->
        <?php if ($current > 1): ?>
            <a href="<?= e($url($current - 1)) ?>"
               class="ta-page-btn"
               aria-label="Previous page">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </a>
        <?php else: ?>
            <span class="ta-page-btn opacity-40 cursor-not-allowed" aria-disabled="true">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </span>
        <?php endif; ?>

        <!-- Page numbers with ellipsis -->
        <?php
        $prev = null;
        foreach ($pages as $p):
            if ($prev !== null && $p - $prev > 1):
        ?>
                <span class="ta-page-btn opacity-60 cursor-default">…</span>
        <?php
            endif;
            $prev = $p;
        ?>
            <?php if ($p === $current): ?>
                <span class="ta-page-btn active" aria-current="page"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e($url($p)) ?>" class="ta-page-btn"><?= $p ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Next -->
        <?php if ($current < $paginationTotalPages): ?>
            <a href="<?= e($url($current + 1)) ?>"
               class="ta-page-btn"
               aria-label="Next page">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        <?php else: ?>
            <span class="ta-page-btn opacity-40 cursor-not-allowed" aria-disabled="true">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </span>
        <?php endif; ?>
    </div>
</nav>
