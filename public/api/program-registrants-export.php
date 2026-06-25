<?php
/**
 * Export active program registrants as CSV (includes registration question answers).
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
use Headcount\Services\ProgramService;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
if ($programId <= 0) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: text/plain');
    echo 'Program ID required';
    exit;
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);
$svc = new ProgramService();

$program = $svc->getByIdForOrg($programId, $organizationId);
if (!$program) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain');
    echo 'Program not found';
    exit;
}

$questions = $svc->getQuestions($programId);
$registrants = $svc->listActiveRegistrantsWithWeeks($programId, $organizationId);

$filename = 'program-' . $programId . '-registrants-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Program', $program['title'] ?? '']);

$headers = ['First name', 'Last name', 'Email', 'Joined', 'Status', 'Weeks'];
foreach ($questions as $q) {
    $headers[] = (string) ($q['question_text'] ?? 'Question');
}
fputcsv($output, $headers);

foreach ($registrants as $r) {
    $answersByQ = [];
    foreach ($r['question_answers'] ?? [] as $qa) {
        $qid = (int) ($qa['question_id'] ?? 0);
        if ($qid > 0) {
            $answersByQ[$qid] = ProgramService::formatRegistrationAnswerDisplay(
                (string) ($qa['answer_text'] ?? ''),
                (string) ($qa['question_type'] ?? '')
            );
        }
    }

    $row = [
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        $r['email'] ?? '',
        !empty($r['joined_at']) ? substr((string) $r['joined_at'], 0, 10) : '',
        ucfirst((string) ($r['reg_status'] ?? 'active')),
        $r['weeks_label'] ?? '',
    ];
    foreach ($questions as $q) {
        $qid = (int) ($q['id'] ?? 0);
        $row[] = $answersByQ[$qid] ?? '';
    }
    fputcsv($output, $row);
}

fclose($output);
