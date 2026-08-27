<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\NotificationHelper;
use InvalidArgumentException;
use RuntimeException;

/**
 * Program request workflow: submit, send back, resubmit, approve (draft program), decline.
 */
class ProgramRequestService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';

    public const ACTION_SUBMITTED = 'submitted';
    public const ACTION_RESUBMITTED = 'resubmitted';
    public const ACTION_SENT_BACK = 'sent_back';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_DECLINED = 'declined';
    public const ACTION_WITHDRAWN = 'withdrawn';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::STATUS_PENDING => ['send_back', 'approve', 'decline', 'withdraw'],
        self::STATUS_CHANGES_REQUESTED => ['update', 'resubmit', 'withdraw'],
        self::STATUS_APPROVED => [],
        self::STATUS_DECLINED => [],
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function tablesExist(): bool
    {
        return $this->db->tableExists('program_requests')
            && $this->db->tableExists('program_request_comments');
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function validateProposal(array $data): array
    {
        $errors = [];
        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors[] = 'Title is required.';
        }
        if (trim((string) ($data['description'] ?? '')) === '') {
            $errors[] = 'Description is required.';
        }
        $eventDate = trim((string) ($data['starts_on'] ?? $data['event_date'] ?? ''));
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || strtotime($eventDate) === false) {
            $errors[] = 'A valid start date is required.';
        }
        $start = trim((string) ($data['session_start_time'] ?? $data['start_time'] ?? ''));
        $end = trim((string) ($data['session_end_time'] ?? $data['end_time'] ?? ''));
        if ($start !== '' && !preg_match('/^\d{1,2}:\d{2}/', $start)) {
            $errors[] = 'Start time is not valid.';
        }
        if ($end !== '' && !preg_match('/^\d{1,2}:\d{2}/', $end)) {
            $errors[] = 'End time is not valid.';
        }
        if (isset($data['budget']) && $data['budget'] !== '' && $data['budget'] !== null) {
            if (!is_numeric($data['budget']) || (float) $data['budget'] < 0) {
                $errors[] = 'Budget must be a number greater than or equal to zero.';
            }
        }
        if (isset($data['target_attendance']) && $data['target_attendance'] !== '' && $data['target_attendance'] !== null) {
            if (!is_numeric($data['target_attendance']) || (int) $data['target_attendance'] < 1) {
                $errors[] = 'Expected attendance must be a positive whole number.';
            }
        }
        return $errors;
    }

    public static function canTransition(string $fromStatus, string $action): bool
    {
        return in_array($action, self::TRANSITIONS[$fromStatus] ?? [], true);
    }

    public static function assertCanTransition(string $fromStatus, string $action): void
    {
        if (!self::canTransition($fromStatus, $action)) {
            throw new RuntimeException('This request cannot be ' . str_replace('_', ' ', $action) . ' in its current status.');
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param list<string> $eventColumns
     * @return array<string, mixed>
     */
    public static function buildProgramInsert(array $request, array $programColumns): array
    {
        $location = trim((string) ($request['location'] ?? ''));
        if ($location === '') {
            $location = 'TBD';
        }
        $row = [
            'organization_id' => (int) $request['organization_id'],
            'title' => (string) $request['title'],
            'description' => (string) ($request['description'] ?? ''),
            'location' => $location,
            'status' => 'draft',
            'created_by' => (int) $request['submitted_by'],
            'pricing_type' => 'free',
            'recurrence_type' => 'weekly',
        ];
        if (in_array('starts_on', $programColumns, true)) {
            $row['starts_on'] = $request['starts_on'] ?? $request['event_date'] ?? null;
        }
        if (in_array('session_start_time', $programColumns, true)) {
            $row['session_start_time'] = self::emptyToNull($request['session_start_time'] ?? $request['start_time'] ?? null);
        }
        if (in_array('session_end_time', $programColumns, true)) {
            $row['session_end_time'] = self::emptyToNull($request['session_end_time'] ?? $request['end_time'] ?? null);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id, int $organizationId): ?array
    {
        $row = $this->db->queryOne(
            "SELECT r.*,
                    CONCAT(s.first_name, ' ', s.last_name) AS submitter_name,
                    s.email AS submitter_email,
                    CONCAT(rv.first_name, ' ', rv.last_name) AS reviewer_name
             FROM program_requests r
             INNER JOIN users s ON s.id = r.submitted_by
             LEFT JOIN users rv ON rv.id = r.reviewed_by
             WHERE r.id = :id AND r.organization_id = :org",
            ['id' => $id, 'org' => $organizationId]
        );
        return $row ?: null;
    }

    /**
     * @param array{status?:string, submitted_by?:int} $filters
     * @return list<array<string, mixed>>
     */
    public function listForOrg(int $organizationId, array $filters = []): array
    {
        $sql = "SELECT r.*,
                       CONCAT(s.first_name, ' ', s.last_name) AS submitter_name,
                       s.email AS submitter_email
                FROM program_requests r
                INNER JOIN users s ON s.id = r.submitted_by
                WHERE r.organization_id = :org";
        $params = ['org' => $organizationId];
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $sql .= ' AND r.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['submitted_by'])) {
            $sql .= ' AND r.submitted_by = :sid';
            $params['sid'] = (int) $filters['submitted_by'];
        }
        $sql .= ' ORDER BY r.created_at DESC';
        return $this->db->query($sql, $params) ?: [];
    }

    public function countPending(int $organizationId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS c FROM program_requests WHERE organization_id = :org AND status = :st',
            ['org' => $organizationId, 'st' => self::STATUS_PENDING]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commentsFor(int $requestId): array
    {
        return $this->db->query(
            "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name
             FROM program_request_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.request_id = :id
             ORDER BY c.created_at ASC",
            ['id' => $requestId]
        ) ?: [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $organizationId, int $userId, array $data): int
    {
        $errors = self::validateProposal($data);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $id = (int) $this->db->insert(
            'program_requests',
            $this->proposalColumns($organizationId, $userId, $data, self::STATUS_PENDING)
        );
            $this->addComment($id, $userId, self::ACTION_SUBMITTED, trim((string) ($data['notes'] ?? '')) ?: 'Program request submitted.');
        $request = $this->getById($id, $organizationId);
        if ($request) {
            $this->notifyApprovers($request, false);
        }
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProposal(int $id, int $organizationId, int $userId, array $data): void
    {
        $request = $this->requireRequest($id, $organizationId);
        if ((int) $request['submitted_by'] !== $userId) {
            throw new RuntimeException('You can only edit your own program requests.');
        }
        self::assertCanTransition($request['status'], 'update');
        $errors = self::validateProposal($data);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $cols = $this->proposalColumns($organizationId, $userId, $data, $request['status']);
        unset($cols['organization_id'], $cols['submitted_by'], $cols['status']);
        $this->db->update('program_requests', $id, $cols);
    }

    public function resubmit(int $id, int $organizationId, int $userId, string $message = ''): void
    {
        $request = $this->requireRequest($id, $organizationId);
        if ((int) $request['submitted_by'] !== $userId) {
            throw new RuntimeException('You can only resubmit your own program requests.');
        }
        self::assertCanTransition($request['status'], 'resubmit');
        $this->db->update('program_requests', $id, [
            'status' => self::STATUS_PENDING,
            'reviewer_comment' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $this->addComment($id, $userId, self::ACTION_RESUBMITTED, $message !== '' ? $message : 'Request resubmitted for review.');
        $updated = $this->getById($id, $organizationId);
        if ($updated) {
            $this->notifyApprovers($updated, true);
        }
    }

    public function withdraw(int $id, int $organizationId, int $userId): void
    {
        $request = $this->requireRequest($id, $organizationId);
        if ((int) $request['submitted_by'] !== $userId) {
            throw new RuntimeException('You can only withdraw your own program requests.');
        }
        self::assertCanTransition($request['status'], 'withdraw');
        $this->addComment($id, $userId, self::ACTION_WITHDRAWN, 'Request withdrawn by submitter.');
        $this->db->execute(
            'DELETE FROM program_requests WHERE id = :id AND organization_id = :org',
            ['id' => $id, 'org' => $organizationId]
        );
    }

    public function sendBack(int $id, int $organizationId, int $reviewerId, string $comment): void
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new InvalidArgumentException('Please explain what needs to be updated.');
        }
        $request = $this->requireRequest($id, $organizationId);
        self::assertCanTransition($request['status'], 'send_back');
        $this->db->update('program_requests', $id, [
            'status' => self::STATUS_CHANGES_REQUESTED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'reviewer_comment' => $comment,
        ]);
        $this->addComment($id, $reviewerId, self::ACTION_SENT_BACK, $comment);
        $updated = $this->getById($id, $organizationId);
        if ($updated) {
            $this->notifySubmitter($updated, 'sent_back', $comment);
        }
    }

    public function decline(int $id, int $organizationId, int $reviewerId, string $comment): void
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new InvalidArgumentException('Please provide a reason for declining.');
        }
        $request = $this->requireRequest($id, $organizationId);
        self::assertCanTransition($request['status'], 'decline');
        $this->db->update('program_requests', $id, [
            'status' => self::STATUS_DECLINED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'reviewer_comment' => $comment,
        ]);
        $this->addComment($id, $reviewerId, self::ACTION_DECLINED, $comment);
        $updated = $this->getById($id, $organizationId);
        if ($updated) {
            $this->notifySubmitter($updated, 'declined', $comment);
        }
    }

    public function approve(int $id, int $organizationId, int $reviewerId, string $comment = ''): int
    {
        $request = $this->requireRequest($id, $organizationId);
        self::assertCanTransition($request['status'], 'approve');

        $programColumns = [];
        try {
            $cols = $this->db->query('SHOW COLUMNS FROM programs');
            $programColumns = array_column($cols ?: [], 'Field');
        } catch (\Throwable $e) {
            $programColumns = [];
        }

        $this->db->beginTransaction();
        try {
            $programId = (int) $this->db->insert('programs', self::buildProgramInsert($request, $programColumns));
            $ids = json_decode((string) ($request['facility_ids'] ?? '[]'), true);
            if (is_array($ids) && $ids !== []) {
                (new EventFacilityService($this->db))->syncProgram($programId, $organizationId, $ids);
            }
            $this->db->update('program_requests', $id, [
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reviewer_comment' => $comment !== '' ? $comment : null,
                'program_id' => $programId,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->addComment(
            $id,
            $reviewerId,
            self::ACTION_APPROVED,
            $comment !== '' ? $comment : 'Request approved. Draft program created.'
        );
        $updated = $this->getById($id, $organizationId);
        if ($updated) {
            $this->notifySubmitter($updated, 'approved', $comment);
        }
        return $programId;
    }

    public function userCanCompleteRequestProgram(int $organizationId, int $userId, int $programId): bool
    {
        if ($programId <= 0) {
            return false;
        }
        $row = $this->db->queryOne(
            'SELECT id FROM program_requests
             WHERE organization_id = :org AND program_id = :pid AND submitted_by = :uid AND status = :st',
            [
                'org' => $organizationId,
                'pid' => $programId,
                'uid' => $userId,
                'st' => self::STATUS_APPROVED,
            ]
        );
        return !empty($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForProgram(int $organizationId, int $programId): ?array
    {
        $row = $this->db->queryOne(
            'SELECT * FROM program_requests WHERE organization_id = :org AND program_id = :pid LIMIT 1',
            ['org' => $organizationId, 'pid' => $programId]
        );
        return $row ?: null;
    }

    /**
     * @return list<array{id:int,email:string,first_name:string,last_name:string}>
     */
    public function listApprovers(int $organizationId): array
    {
        return (new OwnerService($this->db))->listApprovers($organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRequest(int $id, int $organizationId): array
    {
        $request = $this->getById($id, $organizationId);
        if (!$request) {
            throw new RuntimeException('Program request not found.');
        }
        return $request;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function proposalColumns(int $organizationId, int $userId, array $data, string $status): array
    {
        return [
            'organization_id' => $organizationId,
            'submitted_by' => $userId,
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'starts_on' => $data['starts_on'] ?? $data['event_date'] ?? null,
            'session_start_time' => self::emptyToNull($data['session_start_time'] ?? $data['start_time'] ?? null),
            'session_end_time' => self::emptyToNull($data['session_end_time'] ?? $data['end_time'] ?? null),
            'location' => self::emptyToNull($data['location'] ?? null),
            'facility_ids' => isset($data['facility_ids'])
                ? (is_string($data['facility_ids']) ? $data['facility_ids'] : json_encode(array_values(array_map('intval', (array) $data['facility_ids']))))
                : null,
            'notes' => self::emptyToNull($data['notes'] ?? null),
            'status' => $status,
        ];
    }

    private function addComment(int $requestId, int $userId, string $action, ?string $message): void
    {
        $this->db->insert('program_request_comments', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'action' => $action,
            'message' => ($message !== null && $message !== '') ? $message : null,
        ]);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function notifyApprovers(array $request, bool $resubmitted): void
    {
        $orgId = (int) $request['organization_id'];
        $requestId = (int) $request['id'];
        $title = (string) $request['title'];
        $submitter = trim((string) ($request['submitter_name'] ?? 'A staff member'));
        $verb = $resubmitted ? 'resubmitted' : 'submitted';
        $link = '/admin/?page=program-request-details&id=' . $requestId;
        $inAppTitle = $resubmitted ? 'Program request resubmitted' : 'New program request';
        $inAppMsg = $submitter . ' ' . $verb . ' a program request: "' . $title . '".';
        $approvers = $this->listApprovers($orgId);

        foreach ($approvers as $approver) {
            NotificationHelper::create(
                $orgId,
                'program_request',
                $inAppTitle,
                $inAppMsg,
                (int) $approver['id'],
                $link
            );
        }

        try {
            $email = ProgramRequestEmailService::fromConfigFile();
            if ($email) {
                $email->notifyApprovers($request, $approvers, $resubmitted);
            }
        } catch (\Throwable $e) {
            error_log('Program request approver email failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function notifySubmitter(array $request, string $kind, string $comment): void
    {
        $orgId = (int) $request['organization_id'];
        $submitterId = (int) $request['submitted_by'];
        $title = (string) $request['title'];
        $requestId = (int) $request['id'];

        if ($kind === 'sent_back') {
            $nTitle = 'Program request needs updates';
            $nMsg = 'Your program request "' . $title . '" was sent back. Please make the requested updates and resubmit.';
            $link = '/admin/?page=program-request-form&id=' . $requestId;
        } elseif ($kind === 'declined') {
            $nTitle = 'Program request declined';
            $nMsg = 'Unfortunately your program request "' . $title . '" has been declined.';
            $link = '/admin/?page=program-request-details&id=' . $requestId;
        } else {
            $nTitle = 'Program request approved';
            $nMsg = 'Your program request "' . $title . '" has been approved. Complete the remaining details and publish the draft program.';
            $programId = (int) ($request['program_id'] ?? 0);
            $link = $programId > 0
                ? '/admin/?page=program-edit&id=' . $programId
                : '/admin/?page=program-request-details&id=' . $requestId;
        }

        NotificationHelper::create($orgId, 'program_request', $nTitle, $nMsg, $submitterId, $link);

        try {
            $email = ProgramRequestEmailService::fromConfigFile();
            if ($email) {
                if ($kind === 'sent_back') {
                    $email->notifySentBack($request, $comment);
                } elseif ($kind === 'declined') {
                    $email->notifyDeclined($request, $comment);
                } else {
                    $email->notifyApproved($request);
                }
            }
        } catch (\Throwable $e) {
            error_log('Program request submitter email failed: ' . $e->getMessage());
        }
    }

    private static function emptyToNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }
}
