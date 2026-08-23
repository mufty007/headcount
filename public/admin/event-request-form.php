<?php
/**
 * Submit or update an event request
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\EventRequestService;

AuthMiddleware::requireCan('events.request');
$organizationId = (int) AuthMiddleware::getOrganizationId();
$userId = (int) AuthMiddleware::getUserId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);
$service = new EventRequestService();

if (!$service->tablesExist()) {
    http_response_code(503);
    echo 'Event requests are not installed. Run migration 084_event_requests.sql.';
    exit;
}

$id = (int) get('id', 0);
$existing = $id > 0 ? $service->getById($id, $organizationId) : null;
if ($id > 0 && !$existing) {
    setFlash('error', 'Event request not found.');
    redirect($adminBase ?? '/admin' . '/index.php?page=event-requests');
    exit;
}
if ($existing && (int) $existing['submitted_by'] !== $userId) {
    http_response_code(403);
    echo 'You can only edit your own event requests.';
    exit;
}

$categories = [];
try {
    $categories = $db->query(
        'SELECT name FROM categories WHERE organization_id = :org AND is_active = 1 ORDER BY sort_order, name',
        ['org' => $organizationId]
    ) ?: [];
} catch (\Throwable $e) {
    $categories = [];
}

$form = [
    'title' => $existing['title'] ?? '',
    'description' => $existing['description'] ?? '',
    'event_date' => $existing['event_date'] ?? '',
    'start_time' => !empty($existing['start_time']) ? substr((string) $existing['start_time'], 0, 5) : '',
    'end_time' => !empty($existing['end_time']) ? substr((string) $existing['end_time'], 0, 5) : '',
    'location' => $existing['location'] ?? '',
    'category' => $existing['category'] ?? '',
    'budget' => $existing['budget'] ?? '',
    'target_attendance' => $existing['target_attendance'] ?? '',
    'target_audience' => $existing['target_audience'] ?? '',
    'notes' => $existing['notes'] ?? '',
];
$errors = [];

if (isPost()) {
    CsrfMiddleware::verify();
    $form = [
        'title' => sanitize(post('title', '')),
        'description' => trim((string) post('description', '')),
        'event_date' => trim((string) post('event_date', '')),
        'start_time' => trim((string) post('start_time', '')),
        'end_time' => trim((string) post('end_time', '')),
        'location' => sanitize(post('location', '')),
        'category' => sanitize(post('category', '')),
        'budget' => post('budget', ''),
        'target_attendance' => post('target_attendance', ''),
        'target_audience' => sanitize(post('target_audience', '')),
        'notes' => trim((string) post('notes', '')),
    ];
    $errors = EventRequestService::validateProposal($form);
    if (empty($errors)) {
        try {
            if ($existing) {
                $service->updateProposal($id, $organizationId, $userId, $form);
                $service->resubmit($id, $organizationId, $userId, trim((string) $form['notes']));
                setFlash('success', 'Your event request was updated and resubmitted for review.');
            } else {
                $id = $service->create($organizationId, $userId, $form);
                setFlash('success', 'Your event request was submitted for review.');
            }
            if (!isset($adminBase)) {
                require_once __DIR__ . '/includes/layout-vars.php';
            }
            redirect($adminBase . '/index.php?page=event-request-details&id=' . (int) $id);
            exit;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if (!isset($adminBase)) {
    require_once __DIR__ . '/includes/layout-vars.php';
}
$pageTitle = $existing ? 'Update Event Request' : 'Request Event';
$currentPage = 'event-request-form';
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in max-w-3xl mx-auto">
    <?php
    $pageHeaderTitle = $existing ? 'Update event request' : 'Request an event';
    $pageHeaderSubtitle = $existing
        ? 'Update the proposal using the reviewer comments, then resubmit.'
        : 'Describe the event you want to host. An operational admin will review it before a draft event is created.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Events', 'url' => $adminBase . '/index.php?page=events'],
        ['label' => 'Event Requests', 'url' => $adminBase . '/index.php?page=event-requests'],
        ['label' => $existing ? 'Update' : 'New'],
    ];
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if ($existing && ($existing['status'] ?? '') === EventRequestService::STATUS_CHANGES_REQUESTED && !empty($existing['reviewer_comment'])): ?>
        <div class="ta-alert ta-alert-warning mb-6">
            <p class="font-semibold mb-1">Updates requested</p>
            <p><?= nl2br(e($existing['reviewer_comment'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="ta-alert ta-alert-error mb-6 flex-col items-start">
            <p class="font-semibold mb-1">Please fix the following:</p>
            <ul class="list-disc list-inside text-sm space-y-0.5">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e(CsrfMiddleware::getToken()) ?>">

        <div>
            <label class="form-label" for="title">Event title <span class="text-red-500">*</span></label>
            <input class="ta-input w-full" type="text" id="title" name="title" required value="<?= e($form['title']) ?>">
        </div>
        <div>
            <label class="form-label" for="description">Description <span class="text-red-500">*</span></label>
            <textarea class="ta-input w-full" id="description" name="description" rows="5" required><?= e($form['description']) ?></textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="form-label" for="event_date">Date <span class="text-red-500">*</span></label>
                <input class="ta-input w-full" type="date" id="event_date" name="event_date" required value="<?= e($form['event_date']) ?>">
            </div>
            <div>
                <label class="form-label" for="start_time">Start time</label>
                <input class="ta-input w-full" type="time" id="start_time" name="start_time" value="<?= e($form['start_time']) ?>">
            </div>
            <div>
                <label class="form-label" for="end_time">End time</label>
                <input class="ta-input w-full" type="time" id="end_time" name="end_time" value="<?= e($form['end_time']) ?>">
            </div>
        </div>
        <div>
            <label class="form-label" for="location">Location</label>
            <input class="ta-input w-full" type="text" id="location" name="location" value="<?= e($form['location']) ?>" placeholder="Leave blank if still TBD">
        </div>
        <div>
            <label class="form-label" for="category">Category</label>
            <input class="ta-input w-full" type="text" id="category" name="category" list="event-request-categories" value="<?= e($form['category']) ?>">
            <datalist id="event-request-categories">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['name'] ?? '') ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label" for="budget">Requested budget</label>
                <input class="ta-input w-full" type="number" min="0" step="0.01" id="budget" name="budget" value="<?= e((string) $form['budget']) ?>">
            </div>
            <div>
                <label class="form-label" for="target_attendance">Expected attendance</label>
                <input class="ta-input w-full" type="number" min="1" id="target_attendance" name="target_attendance" value="<?= e((string) $form['target_attendance']) ?>">
            </div>
        </div>
        <div>
            <label class="form-label" for="target_audience">Target audience</label>
            <input class="ta-input w-full" type="text" id="target_audience" name="target_audience" value="<?= e($form['target_audience']) ?>" placeholder="e.g. Youth, families, sisters">
        </div>
        <div>
            <label class="form-label" for="notes">Notes for reviewers</label>
            <textarea class="ta-input w-full" id="notes" name="notes" rows="3"><?= e($form['notes']) ?></textarea>
        </div>
        <div class="flex flex-wrap gap-3 pt-2">
            <button type="submit" class="btn-primary"><?= $existing ? 'Save and resubmit' : 'Submit request' ?></button>
            <a class="btn-secondary" href="<?= e($adminBase . '/index.php?page=event-requests') ?>">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
