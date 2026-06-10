<?php
/**
 * Single facility details (public — members and guests)
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Services\FacilityService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');

$isLoggedIn = PortalAuthMiddleware::isAuthenticated();
$orgId = headcount_resolve_portal_organization_id(
    $isLoggedIn ? PortalAuthMiddleware::getOrganizationId() : null,
    $config,
    Database::getInstance()
);

$slug = trim((string) ($_GET['facility'] ?? $_GET['slug'] ?? ''));
$facility = null;
if ($orgId && $slug !== '') {
    $svc = new FacilityService();
    if ($svc->tableExists()) {
        $facility = $svc->getBySlugForOrg($slug, $orgId);
    }
}
if (!$facility || ($facility['status'] ?? '') !== 'active') {
    header('Location: ' . ($baseUrlPath ? $baseUrlPath : '') . '/portal/facilities.php');
    exit;
}

$dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$hoursRows = [];
if (!empty($facility['operating_hours']) && is_array($facility['operating_hours'])) {
    for ($d = 0; $d <= 6; $d++) {
        $cfg = $facility['operating_hours'][(string) $d] ?? null;
        if (!is_array($cfg)) {
            continue;
        }
        if (!empty($cfg['closed'])) {
            $hoursRows[] = ['day' => $dayLabels[$d], 'hours' => 'Closed'];
        } elseif (!empty($cfg['open']) && !empty($cfg['close'])) {
            $hoursRows[] = ['day' => $dayLabels[$d], 'hours' => date('g:i A', strtotime($cfg['open'])) . ' – ' . date('g:i A', strtotime($cfg['close']))];
        }
    }
}

$imageUrls = $facility['image_urls'] ?? [];
if (empty($imageUrls) && !empty($facility['image'])) {
    $imageUrls = [hc_public_api_image_url((string) $facility['image'])];
}

$descRawStored = (string) ($facility['description'] ?? '');
$facilityDesc = headcount_facility_description_for_display($descRawStored);
$hasDescription = $facilityDesc['has'];
$facilityDescHtml = $facilityDesc['html'];
if (!$hasDescription && trim($descRawStored) !== '') {
    $plain = headcount_undo_nested_html_entity_encoding(trim($descRawStored));
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(preg_replace('/\s+/u', ' ', str_replace(["\xc2\xa0", '&nbsp;'], ' ', $plain)));
    if ($plain !== '') {
        $hasDescription = true;
        $facilityDescHtml = '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
}

$slideCount = count($imageUrls);
$multiSlide = $slideCount > 1;

$showPriceBadge = !empty($facility['is_paid']) && (float) ($facility['hourly_rate'] ?? 0) > 0;
$showCapacityBadge = !empty($facility['capacity']) && (int) $facility['capacity'] > 0;

$pageTitle = $facility['name'] ?? 'Facility';
$currentPage = 'facility-details';
require __DIR__ . '/includes/header.php';
?>

<style>
.facility-detail-page { max-width: 80rem; margin-left: auto; margin-right: auto; }
.facility-detail-row-main {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 2rem;
    margin-top: 2rem;
}
.facility-detail-main {
    flex: 1 1 68%;
    min-width: 0;
}
.facility-detail-sidebar {
    flex: 0 1 30%;
    min-width: 260px;
    max-width: 360px;
}
@media (max-width: 960px) {
    .facility-detail-main,
    .facility-detail-sidebar {
        flex: 1 1 100%;
        max-width: none;
    }
}
.facility-detail-sidebar-inner {
    position: sticky;
    top: 1.5rem;
}
.facility-description-body p { margin-bottom: 0.75rem; }
.facility-description-body ul { list-style: disc; padding-left: 1.25rem; margin-bottom: 0.75rem; }
.facility-description-body ol { list-style: decimal; padding-left: 1.25rem; margin-bottom: 0.75rem; }
.facility-description-body li { margin-bottom: 0.25rem; }
.facility-description-body strong { font-weight: 600; }
.facility-slider-wrap { position: relative; }
.facility-slider-badges {
    position: absolute;
    bottom: 0.75rem;
    left: 0.75rem;
    z-index: 35;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    max-width: calc(100% - 5rem);
}
.facility-slider-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 700;
    line-height: 1.25;
    background: rgba(255, 255, 255, 0.95);
    color: #312e81;
    border: 1px solid rgba(199, 210, 254, 0.9);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    backdrop-filter: blur(4px);
}
.facility-slider-badge--capacity {
    color: #1f2937;
    border-color: rgba(229, 231, 235, 0.95);
}
.facility-slider-badge--discount {
    background: #dcfce7;
    color: #166534;
    border-color: #bbf7d0;
}
.facility-slider-gradient {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 5rem;
    z-index: 20;
    pointer-events: none;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.45), transparent);
}
.facility-meta-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.facility-description {
    color: #374151;
    line-height: 1.65;
    display: block;
}
.facility-description-body,
.facility-description-body p,
.facility-description-body li,
.facility-description-body span {
    color: #374151 !important;
}
.facility-description-body a {
    color: #4f46e5;
    text-decoration: underline;
}
.facility-description-body img {
    max-width: 100%;
    height: auto;
    border-radius: 0.75rem;
    margin: 0.75rem 0;
}
.facility-slider-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 40;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 9999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    border: none;
    font-size: 2rem;
    font-weight: 300;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    transition: background 0.15s ease;
}
.facility-slider-btn:hover {
    background: rgba(0, 0, 0, 0.75);
}
.facility-slider-btn--prev { left: 0.75rem; }
.facility-slider-btn--next { right: 0.75rem; }
.facility-slider-counter {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    z-index: 40;
    padding: 0.25rem 0.625rem;
    border-radius: 9999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.25;
}
.facility-slider-dots {
    position: absolute;
    top: 0.75rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 40;
    display: flex;
    gap: 0.375rem;
    align-items: center;
}
.facility-slider-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 9999px;
    border: 1px solid rgba(255, 255, 255, 0.7);
    background: rgba(255, 255, 255, 0.35);
    padding: 0;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
}
.facility-slider-dot.is-active,
.facility-slider-dot:hover {
    background: #fff;
    transform: scale(1.1);
}
</style>

<div x-data="facilityDetails()" x-init="init()" class="facility-detail-page">
    <a href="<?= e($baseUrlPath) ?>/portal/facilities.php" class="text-indigo-600 dark:text-indigo-300 text-sm font-semibold hover:underline">&larr; All facilities</a>

    <div class="facility-detail-row-main">
        <div class="facility-detail-main space-y-6">
            <?php if (!empty($imageUrls)): ?>
            <div>
                <div class="facility-slider-wrap rounded-2xl overflow-hidden bg-gray-100 dark:bg-gray-700 h-56 sm:h-64 max-h-[280px]">
                    <img :src="slides[current]"
                         src="<?= e($imageUrls[0]) ?>"
                         alt="<?= e($facility['name']) ?>"
                         class="w-full h-full object-cover transition-opacity duration-300">

                    <?php if ($showPriceBadge || $showCapacityBadge): ?>
                    <div class="facility-slider-gradient" aria-hidden="true"></div>
                    <div class="facility-slider-badges">
                        <?php if ($showPriceBadge): ?>
                        <span class="facility-slider-badge">$<?= number_format((float) $facility['hourly_rate'], 2) ?> / hr</span>
                        <?php endif; ?>
                        <?php if ($showCapacityBadge): ?>
                        <span class="facility-slider-badge facility-slider-badge--capacity"><?= (int) $facility['capacity'] ?> people</span>
                        <?php endif; ?>
                        <?php if ($showPriceBadge && !empty($facility['discount_percent']) && (float) $facility['discount_percent'] > 0): ?>
                        <span class="facility-slider-badge facility-slider-badge--discount">
                            <?= number_format((float) $facility['discount_percent'], 0) ?>% off<?php if (!empty($facility['discount_label'])): ?> · <?= e($facility['discount_label']) ?><?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($multiSlide): ?>
                    <button type="button" @click="prev()" class="facility-slider-btn facility-slider-btn--prev" aria-label="Previous image"><span aria-hidden="true">&#8249;</span></button>
                    <button type="button" @click="next()" class="facility-slider-btn facility-slider-btn--next" aria-label="Next image"><span aria-hidden="true">&#8250;</span></button>
                    <div class="facility-slider-dots" role="tablist" aria-label="Image slides">
                        <?php foreach ($imageUrls as $idx => $url): ?>
                        <button type="button"
                                @click="goTo(<?= (int) $idx ?>)"
                                class="facility-slider-dot"
                                :class="current === <?= (int) $idx ?> ? 'is-active' : ''"
                                aria-label="Image <?= (int) $idx + 1 ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="facility-slider-counter" x-text="(current + 1) + ' / ' + slides.length"><?= '1 / ' . (int) $slideCount ?></div>
                    <?php endif; ?>
                </div>
                <?php if (count($imageUrls) > 1): ?>
                <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                    <?php foreach ($imageUrls as $idx => $url): ?>
                    <button type="button" @click="goTo(<?= (int) $idx ?>)"
                            class="flex-shrink-0 w-20 h-14 rounded-lg overflow-hidden border-2 transition-colors"
                            :class="current === <?= (int) $idx ?> ? 'border-indigo-600 ring-2 ring-indigo-200' : 'border-gray-200 dark:border-gray-700 opacity-80 hover:opacity-100'">
                        <img src="<?= e($url) ?>" alt="" class="w-full h-full object-cover">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php if ($showPriceBadge || $showCapacityBadge): ?>
            <div class="facility-meta-badges">
                <?php if ($showPriceBadge): ?>
                <span class="facility-slider-badge">
                    $<?= number_format((float) $facility['hourly_rate'], 2) ?> / hr
                </span>
                <?php endif; ?>
                <?php if ($showCapacityBadge): ?>
                <span class="facility-slider-badge facility-slider-badge--capacity">
                    <?= (int) $facility['capacity'] ?> people
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white"><?= e($facility['name']) ?></h1>
                <?php if (!empty($facility['location'])): ?>
                <p class="text-gray-500 dark:text-gray-400 mt-2 flex items-start gap-1.5">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span><?= e($facility['location']) ?></span>
                </p>
                <?php endif; ?>
            </div>

            <div class="facility-description border-t border-gray-200 dark:border-gray-700 pt-6 mt-4">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-3">About this space</h2>
                <?php if ($hasDescription): ?>
                <div class="facility-description-body"><?= $facilityDescHtml ?></div>
                <?php else: ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 italic">No description has been added for this space yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <aside class="facility-detail-sidebar">
            <div class="facility-detail-sidebar-inner space-y-6">
                <?php if (!empty($hoursRows)): ?>
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-sm overflow-hidden">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white px-4 py-3 border-b border-gray-100 dark:border-gray-800 bg-gray-50/80">Available hours</h2>
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($hoursRows as $row): ?>
                        <div class="flex justify-between gap-3 px-4 py-2.5 text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-300"><?= e($row['day']) ?></span>
                            <span class="text-gray-600 dark:text-gray-300 text-right"><?= e($row['hours']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-sm p-5">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white mb-4">Book this space</h2>
                    <div class="flex flex-col gap-3">
                        <?php if ($isLoggedIn && !empty($facility['allow_member_booking'])): ?>
                        <a href="<?= e($baseUrlPath) ?>/portal/facility-book.php?facility=<?= e(urlencode($facility['slug'])) ?>"
                           class="w-full text-center py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 transition-colors">Book as member</a>
                        <?php elseif (!$isLoggedIn && !empty($facility['allow_member_booking'])): ?>
                        <a href="<?= e($baseUrlPath) ?>/portal/login.php?redirect=<?= e(urlencode($baseUrlPath . '/portal/facility-book.php?facility=' . $facility['slug'])) ?>"
                           class="w-full text-center py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 transition-colors">Log in to book</a>
                        <?php endif; ?>
                        <?php if (!empty($facility['allow_guest_booking'])): ?>
                        <a href="<?= e($baseUrlPath) ?>/portal/facility-book-guest.php?facility=<?= e(urlencode($facility['slug'])) ?>"
                           class="w-full text-center py-3 border border-indigo-200 dark:border-indigo-500/30 text-indigo-700 dark:text-indigo-300 font-bold rounded-xl hover:bg-indigo-50 transition-colors">Book as guest</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($facility['allow_guest_booking'])): ?>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-4 leading-relaxed">Guests can request a booking without an account. Complete your profile to manage bookings online.</p>
                    <?php endif; ?>
                    <p class="text-xs text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/15 border border-amber-100 dark:border-amber-500/30 rounded-lg px-3 py-2 mt-4">All requests require staff approval.</p>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
function facilityDetails() {
    return {
        slides: <?= json_encode(array_values($imageUrls), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        current: 0,
        init() {},
        goTo(i) {
            if (i >= 0 && i < this.slides.length) {
                this.current = i;
            }
        },
        prev() {
            if (!this.slides.length) return;
            this.current = (this.current - 1 + this.slides.length) % this.slides.length;
        },
        next() {
            if (!this.slides.length) return;
            this.current = (this.current + 1) % this.slides.length;
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
