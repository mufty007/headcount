<?php
/**
 * Admin event feedback API — list, stats, CSV export for a single event.
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

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if ($eventId <= 0) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'event_id is required']);
    exit;
}

$event = $db->queryOne(
    'SELECT id, title' . (headcount_db_has_column($db, 'events', 'collect_feedback') ? ', collect_feedback' : ', 0 AS collect_feedback') . '
     FROM events WHERE id = :id AND organization_id = :org',
    ['id' => $eventId, 'org' => $organizationId]
);
if (!$event) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

$questionLabels = [
    'overall' => 'Overall experience',
    'content' => 'Quality of content / program',
    'venue' => 'Venue & organization',
    'recommend' => 'Likelihood to recommend',
];

$checkedInCount = (int) ($db->queryOne(
    'SELECT COUNT(DISTINCT user_id) AS c FROM attendance WHERE event_id = :eid',
    ['eid' => $eventId]
)['c'] ?? 0);

$rows = $db->query(
    'SELECT f.*, u.first_name, u.last_name, u.email
     FROM event_feedback f
     JOIN users u ON u.id = f.user_id
     WHERE f.event_id = :eid
     ORDER BY f.created_at DESC',
    ['eid' => $eventId]
);

foreach ($rows as &$row) {
    if (!empty($row['rating_scores']) && is_string($row['rating_scores'])) {
        $row['rating_scores'] = json_decode($row['rating_scores'], true) ?: [];
    } elseif (empty($row['rating_scores'])) {
        $row['rating_scores'] = ['overall' => (int) ($row['rating'] ?? 0)];
    }
}
unset($row);

$responseCount = count($rows);
$averages = [];
foreach (array_keys($questionLabels) as $key) {
    $sum = 0;
    $count = 0;
    foreach ($rows as $r) {
        $scores = is_array($r['rating_scores']) ? $r['rating_scores'] : [];
        if (isset($scores[$key]) && (int) $scores[$key] >= 1) {
            $sum += (int) $scores[$key];
            $count++;
        }
    }
    $averages[$key] = $count > 0 ? round($sum / $count, 2) : null;
}

$overallSum = 0;
$overallCount = 0;
foreach ($rows as $r) {
    if (!empty($r['rating'])) {
        $overallSum += (int) $r['rating'];
        $overallCount++;
    }
}
$avgOverall = $overallCount > 0 ? round($overallSum / $overallCount, 2) : null;
$responseRate = $checkedInCount > 0 ? round(($responseCount / $checkedInCount) * 100, 1) : 0;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'event-feedback-' . $eventId . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Submitted', 'Overall', 'Content', 'Venue', 'Recommend', 'Other feedback']);
    foreach ($rows as $r) {
        $scores = is_array($r['rating_scores']) ? $r['rating_scores'] : [];
        fputcsv($out, [
            trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            $r['email'] ?? '',
            $r['created_at'] ?? '',
            $scores['overall'] ?? $r['rating'] ?? '',
            $scores['content'] ?? '',
            $scores['venue'] ?? '',
            $scores['recommend'] ?? '',
            $r['feedback_text'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'event' => [
        'id' => (int) $event['id'],
        'title' => $event['title'],
        'collect_feedback' => !empty($event['collect_feedback']),
    ],
    'stats' => [
        'checked_in' => $checkedInCount,
        'responses' => $responseCount,
        'response_rate_pct' => $responseRate,
        'avg_overall' => $avgOverall,
        'averages' => $averages,
    ],
    'question_labels' => $questionLabels,
    'feedback' => $rows,
]);
