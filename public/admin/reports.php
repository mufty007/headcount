<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\AdminReportService;
use Headcount\Services\ReportFilterSet;
use Headcount\Services\ReportInsightsBuilder;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$db = Database::getInstance();

$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne('SELECT first_name, last_name, email FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local',
];

$reportType = get('report', 'overview');
$allowedReports = ['overview', 'events', 'members', 'rsvp', 'revenue', 'facilities', 'programs'];
if (!in_array($reportType, $allowedReports, true)) {
    $reportType = 'overview';
}

if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}
$apiBaseUrl = $basePath . '/public/api';
$adminRouter = rtrim($adminBase, '/') . '/index.php';
$reportsResetUrl = $adminRouter . '?page=reports';
$reportsBaseUrl = $adminRouter;

$filters = ReportFilterSet::fromGet($_GET, $db, $organizationId);
$reportSvc = new AdminReportService($db, $organizationId, $filters);

$stats = $reportSvc->getCoreStats();
$revenueStats = $reportSvc->getRevenueStats();
$noShowStats = $reportSvc->getNoShowStats((int) $stats['total_rsvps']);
$noShowCount = $noShowStats['count'];
$noShowRate = $noShowStats['rate'];
$prevStats = $reportSvc->getPrevPeriodStats();

$orgBranding = $db->queryOne('SELECT name, logo_path, primary_color FROM organizations WHERE id = :id', ['id' => $organizationId]);
$primaryColor = is_string($orgBranding['primary_color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $orgBranding['primary_color'])
    ? $orgBranding['primary_color']
    : '#3B82F6';

$trendData = [];
$categoryData = [];
$rsvpVsAttendanceTrend = [];
$topEvents = [];
$topAttendees = [];
$newAttendees = 0;
$returningAttendees = 0;
$eventPerformanceList = [];
$rsvpReportEvents = [];
$memberEngagementList = [];
$revenueByEventList = [];
$revenueMonthly = [];
$facilityStats = [];
$facilityPerformanceList = [];
$programStats = [];
$programPerformanceList = [];

if ($reportType === 'overview') {
    $categoryData = $reportSvc->getCategoryData();
    $trendData = $reportSvc->getTrendData();
    $rsvpVsAttendanceTrend = $reportSvc->getRsvpVsAttendanceTrend();
    $topEvents = $reportSvc->getTopEvents();
    if (method_exists(Utilities::class, 'decodeHtmlEntitiesInEventRows')) {
        Utilities::decodeHtmlEntitiesInEventRows($topEvents);
    }
    $topAttendees = $reportSvc->getTopAttendees();
    $nr = $reportSvc->getNewVsReturningCounts();
    $newAttendees = $nr['new'];
    $returningAttendees = $nr['returning'];
}

if ($reportType === 'events') {
    $eventPerformanceList = $reportSvc->getEventPerformanceList();
    if (method_exists(Utilities::class, 'decodeHtmlEntitiesInEventRows')) {
        Utilities::decodeHtmlEntitiesInEventRows($eventPerformanceList);
    }
}

if ($reportType === 'rsvp') {
    $rsvpReportEvents = $reportSvc->getRsvpReportEvents();
    if (method_exists(Utilities::class, 'decodeHtmlEntitiesInEventRows')) {
        Utilities::decodeHtmlEntitiesInEventRows($rsvpReportEvents);
    }
}

if ($reportType === 'members') {
    $memberEngagementList = $reportSvc->getMemberEngagementList();
}

if ($reportType === 'revenue') {
    $revenueByEventList = $reportSvc->getRevenueByEventList();
    if (method_exists(Utilities::class, 'decodeHtmlEntitiesInEventRows')) {
        Utilities::decodeHtmlEntitiesInEventRows($revenueByEventList);
    }
    $revenueMonthly = $reportSvc->getRevenueMonthlyTrend();
}

if ($reportType === 'facilities') {
    $facilityStats = $reportSvc->getFacilityReportStats();
    $facilityPerformanceList = $reportSvc->getFacilityPerformanceList();
}

if ($reportType === 'programs') {
    $programStats = $reportSvc->getProgramReportStats();
    $programPerformanceList = $reportSvc->getProgramPerformanceList();
}

$insights = ReportInsightsBuilder::build(
    $reportType,
    $filters,
    $stats,
    $prevStats,
    $categoryData,
    $noShowCount,
    $noShowRate,
    $revenueStats,
    $eventPerformanceList,
    $rsvpReportEvents,
    $memberEngagementList,
    $revenueByEventList
);

$eventPickerList = $reportSvc->getEventPickerList(100);
if (method_exists(Utilities::class, 'decodeHtmlEntitiesInEventRows')) {
    Utilities::decodeHtmlEntitiesInEventRows($eventPickerList);
}

$today = date('Y-m-d');
$presets = [
    'This month' => [date('Y-m-01'), $today],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'This quarter' => [date('Y-m-01', strtotime('first day of ' . date('Y') . '-' . (floor((date('n') - 1) / 3) * 3 + 1))), $today],
    'Last quarter' => [
        date('Y-m-01', strtotime('first day of -3 months')),
        date('Y-m-t', strtotime('last day of -1 month')),
    ],
    'YTD' => [date('Y-01-01'), $today],
];

$tabQuery = array_merge(['page' => 'reports'], $filters->toQueryParams());

$chartData = [
    'reportType' => $reportType,
    'primaryColor' => $primaryColor,
];

if ($reportType === 'overview') {
    $chartData['trendData'] = $trendData;
    $chartData['categoryData'] = $categoryData;
    $chartData['rsvpVsAttData'] = $rsvpVsAttendanceTrend;
    $chartData['newAttendees'] = $newAttendees;
    $chartData['returningAttendees'] = $returningAttendees;
}

if ($reportType === 'events' && $eventPerformanceList !== []) {
    $topEv = $eventPerformanceList;
    usort($topEv, static fn ($a, $b) => ((int) ($b['checked_in'] ?? 0)) <=> ((int) ($a['checked_in'] ?? 0)));
    $topEv = array_slice($topEv, 0, 15);
    $chartData['eventBarSeries'] = [
        'labels' => array_map(static function ($r) {
            $t = (string) ($r['title'] ?? '');
            return strlen($t) > 36 ? substr($t, 0, 35) . 'â€¦' : $t;
        }, $topEv),
        'checkedIn' => array_map(static fn ($r) => (int) ($r['checked_in'] ?? 0), $topEv),
    ];
    $withNs = array_values(array_filter($eventPerformanceList, static fn ($r) => ((int) ($r['rsvp_yes'] ?? 0)) > 0));
    usort($withNs, static fn ($a, $b) => ((float) ($b['no_show_pct'] ?? 0)) <=> ((float) ($a['no_show_pct'] ?? 0)));
    $topNs = array_slice($withNs, 0, 12);
    $chartData['eventNoShowSeries'] = [
        'labels' => array_map(static function ($r) {
            $t = (string) ($r['title'] ?? '');
            return strlen($t) > 28 ? substr($t, 0, 27) . 'â€¦' : $t;
        }, $topNs),
        'pcts' => array_map(static fn ($r) => (float) ($r['no_show_pct'] ?? 0), $topNs),
    ];
}

if ($reportType === 'rsvp' && $rsvpReportEvents !== []) {
    $slice = array_slice($rsvpReportEvents, 0, 25);
    $chartData['rsvpStacked'] = [
        'labels' => array_map(static function ($r) {
            $t = (string) ($r['title'] ?? '');
            return strlen($t) > 32 ? substr($t, 0, 31) . 'â€¦' : $t;
        }, $slice),
        'checkedIn' => array_map(static fn ($r) => (int) ($r['checked_in'] ?? 0), $slice),
        'noShows' => array_map(static fn ($r) => (int) ($r['no_show_count'] ?? 0), $slice),
    ];
}

if ($reportType === 'members' && $memberEngagementList !== []) {
    $buckets = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($memberEngagementList as $m) {
        $r = (float) ($m['attendance_rate'] ?? 0);
        $idx = (int) floor($r / 25);
        if ($idx > 4) {
            $idx = 4;
        }
        $buckets[$idx]++;
    }
    $chartData['memberHistogram'] = [
        'labels' => ['0â€“24%', '25â€“49%', '50â€“74%', '75â€“99%', '100%'],
        'counts' => array_values($buckets),
    ];
    $topM = $memberEngagementList;
    usort($topM, static fn ($a, $b) => ((int) ($b['events_attended'] ?? 0)) <=> ((int) ($a['events_attended'] ?? 0)));
    $topM = array_slice($topM, 0, 12);
    $chartData['memberTopSeries'] = [
        'labels' => array_map(static function ($m) {
            $t = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            return strlen($t) > 22 ? substr($t, 0, 21) . 'â€¦' : $t;
        }, $topM),
        'values' => array_map(static fn ($m) => (int) ($m['events_attended'] ?? 0), $topM),
    ];
}

if ($reportType === 'revenue') {
    $revRows = array_values(array_filter($revenueByEventList, static fn ($r) => ((float) ($r['revenue'] ?? 0)) > 0 || ((int) ($r['paid_count'] ?? 0)) > 0));
    usort($revRows, static fn ($a, $b) => ((float) ($b['revenue'] ?? 0)) <=> ((float) ($a['revenue'] ?? 0)));
    $revRows = array_slice($revRows, 0, 18);
    $chartData['revenueBar'] = [
        'labels' => array_map(static function ($r) {
            $t = (string) ($r['title'] ?? '');
            return strlen($t) > 30 ? substr($t, 0, 29) . '…' : $t;
        }, $revRows),
        'amounts' => array_map(static fn ($r) => round((float) ($r['revenue'] ?? 0), 2), $revRows),
    ];
    $chartData['revenueMonthly'] = [
        'labels' => array_map(static fn ($r) => (string) ($r['month'] ?? ''), $revenueMonthly),
        'amounts' => array_map(static fn ($r) => round((float) ($r['revenue'] ?? 0), 2), $revenueMonthly),
    ];
}

if ($reportType === 'facilities' && $facilityPerformanceList !== []) {
    $facRows = array_values(array_filter($facilityPerformanceList, static fn ($r) => ((int) ($r['booking_count'] ?? 0)) > 0));
    usort($facRows, static fn ($a, $b) => ((int) ($b['booking_count'] ?? 0)) <=> ((int) ($a['booking_count'] ?? 0)));
    $facRows = array_slice($facRows, 0, 15);
    $chartData['facilityBookingsBar'] = [
        'labels' => array_map(static fn ($r) => (string) ($r['name'] ?? ''), $facRows),
        'counts' => array_map(static fn ($r) => (int) ($r['booking_count'] ?? 0), $facRows),
    ];
}

$exportFilterQuery = http_build_query(array_merge(
    ['start_date' => $filters->startDate, 'end_date' => $filters->endDate],
    $filters->toQueryParams()
));

$reportsChartsJsPath = (empty($basePath) || $basePath === '/') ? '/public/js/reports-charts.js' : rtrim($basePath, '/') . '/public/js/reports-charts.js';
$reportsChartsJsPath = preg_replace('#/+#', '/', $reportsChartsJsPath);
$apexChartsLocalPath = (empty($basePath) || $basePath === '/') ? '/public/js/apexcharts.min.js' : rtrim($basePath, '/') . '/public/js/apexcharts.min.js';
$apexChartsLocalPath = preg_replace('#/+#', '/', $apexChartsLocalPath);
if (!isset($additionalJS) || !is_array($additionalJS)) {
    $additionalJS = [];
}
$additionalJS[] = $apexChartsLocalPath;
$additionalJS[] = $reportsChartsJsPath;

$pageTitle = 'Reports';
$currentPage = 'reports';

require_once __DIR__ . '/includes/reports/helpers.php';

include __DIR__ . '/includes/header.php';
?>

<div x-data="reportsApp()">

    <?php
    $pageHeaderTitle = 'Analytics & Insights';
    $pageHeaderSubtitle = 'Performance overview with filters, charts, and exports';
    ob_start();
    ?>
    <div class="relative" @keydown.escape.window="exportOpen = false">
        <button type="button" @click="exportOpen = !exportOpen" :disabled="exporting" class="btn-primary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0 disabled:opacity-70">
            <span>Export</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <div x-show="exportOpen" @click.outside="exportOpen = false" class="absolute right-0 z-30 mt-2 w-52 rounded-xl border border-gray-200 bg-white py-1 text-sm shadow-card-lg dark:border-gray-600 dark:bg-gray-800">
            <button type="button" @click="exportFormat('csv')" class="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700">Download CSV</button>
            <button type="button" @click="exportFormat('pdf_binary')" class="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700">Download branded PDF</button>
            <button type="button" @click="exportFormat('pdf')" class="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700">Open print view (HTML)</button>
        </div>
    </div>
    <?php $pageHeaderActions = ob_get_clean(); require __DIR__ . '/components/page-header.php'; ?>

    <div class="mb-6 flex flex-wrap items-center gap-8 border-b border-gray-200 pb-4 dark:border-gray-700">
        <?php foreach (['overview' => 'Overview', 'events' => 'Event performance', 'rsvp' => 'RSVP & no-show', 'members' => 'Member engagement', 'revenue' => 'Revenue', 'facilities' => 'Facilities', 'programs' => 'Programs'] as $rt => $label): ?>
            <a href="<?= e(hc_reports_url($reportsBaseUrl, array_merge($tabQuery, ['report' => $rt]))) ?>" class="border-b-2 pb-2 text-xs font-bold uppercase tracking-widest transition-all <?= $reportType === $rt ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="mb-6 bento-card p-6">
        <form method="GET" action="<?= e($reportsBaseUrl) ?>" class="space-y-4">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="report" value="<?= e($reportType) ?>">
            <?php hc_reports_filter_hidden_inputs($filters, ['start_date', 'end_date']); ?>
            <div class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Date range</label>
                    <div class="flex items-center gap-2">
                        <input type="date" name="start_date" value="<?= e($filters->startDate) ?>" class="flex-1 rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <span class="text-gray-300 dark:text-gray-600">to</span>
                        <input type="date" name="end_date" value="<?= e($filters->endDate) ?>" class="flex-1 rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <button type="submit" class="btn-primary min-w-[120px]">Update dates</button>
                    <a href="<?= e($reportsResetUrl) ?>" class="btn-secondary inline-flex items-center justify-center">Reset all</a>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 items-center">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Quick:</span>
                <?php foreach ($presets as $label => $range): ?>
                    <a href="<?= e(hc_reports_url($reportsBaseUrl, array_merge($tabQuery, ['start_date' => $range[0], 'end_date' => $range[1]]))) ?>" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <?php require __DIR__ . '/includes/reports/filter-panel.php'; ?>
    <?php require __DIR__ . '/includes/reports/insight-strip.php'; ?>

    <?php if ($reportType === 'overview'): ?>
        <?php require __DIR__ . '/includes/reports/tab-overview.php'; ?>
    <?php elseif ($reportType === 'events'): ?>
        <?php require __DIR__ . '/includes/reports/tab-events.php'; ?>
    <?php elseif ($reportType === 'rsvp'): ?>
        <?php require __DIR__ . '/includes/reports/tab-rsvp.php'; ?>
    <?php elseif ($reportType === 'members'): ?>
        <?php require __DIR__ . '/includes/reports/tab-members.php'; ?>
    <?php elseif ($reportType === 'revenue'): ?>
        <?php require __DIR__ . '/includes/reports/tab-revenue.php'; ?>
    <?php elseif ($reportType === 'facilities'): ?>
        <?php require __DIR__ . '/includes/reports/tab-facilities.php'; ?>
    <?php elseif ($reportType === 'programs'): ?>
        <?php require __DIR__ . '/includes/reports/tab-programs.php'; ?>
    <?php endif; ?>

</div>

<script>
window.REPORTS_CHART_DATA = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
window.REPORTS_EXPORT_QUERY = <?= json_encode($exportFilterQuery, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
function reportsApp() {
    return {
        exporting: false,
        exportOpen: false,
        exportFormat(fmt) {
            if (this.exporting) return;
            this.exporting = true;
            this.exportOpen = false;
            const reportType = <?= json_encode($reportType) ?>;
            const typeParam = reportType === 'overview' ? 'attendance'
                : (reportType === 'events' ? 'events'
                : (reportType === 'members' ? 'members'
                : (reportType === 'rsvp' ? 'rsvp_detail'
                : 'revenue')));
            const q = window.REPORTS_EXPORT_QUERY || '';
            const url = <?= json_encode($apiBaseUrl) ?> + '/export-report.php?' + q + '&type=' + encodeURIComponent(typeParam) + '&format=' + encodeURIComponent(fmt);
            window.location.href = url;
            setTimeout(() => { this.exporting = false; }, 4000);
        }
    };
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

