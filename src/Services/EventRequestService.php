<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\NotificationHelper;
use Headcount\Helpers\Permissions;
use InvalidArgumentException;
use RuntimeException;

/**
 * Event request workflow: submit, send back, resubmit, approve (draft event), decline.
 */
class EventRequestService
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
        return $this->db->tableExists('event_requests')
            && $this->db->tableExists('event_request_comments');
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
        $eventDate = trim((string) ($data['event_date'] ?? ''));
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || strtotime($eventDate) === false) {
            $errors[] = 'A valid event date is required.';
        }
        $start = trim((string) ($data['start_time'] ?? ''));
        $end = trim((string) ($data['end_time'] ?? ''));
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
    public static function buildEventInsert(array $request, array $eventColumns): array
    {
        $location = trim((string) ($request['location'] ?? ''));
        if ($location === '') {
            $location = 'TBD';
        }
        $audience = trim((string) ($request['target_audience'] ?? ''));
        $extra = $audience !== '' ? ('Target audience: ' . $audience) : null;

        $row = [
            'organization_id' => (int) $request['organization_id'],
            'title' => (string) $request['title'],
            'description' => (string) ($request['description'] ?? ''),
            'event_date' => $request['event_date'],
            'start_time' => self::emptyToNull($request['start_time'] ?? null),
            'end_time' => self::emptyToNull($request['end_time'] ?? null),
            'location' => $location,
            'category' => self::emptyToNull($request['category'] ?? null) ?? 'other',
            'status' => 'draft',
            'created_by' => (int) $request['submitted_by'],
        ];

        foreach (['extra_details', 'extra_details'] as $col) {
            if (in_array($col, $eventColumns, true) && $extra !== null) {
                $row[$col] = $extra;
                break;
            }
        }
        if (in_array('target_attendance', $eventColumns, true)
            && ($request['target_attendance'] ?? '') !== ''
            && $request['target_attendance'] !== null) {
            $row['target_attendance'] = (int) $request['target_attendance'];
        }
        if (in_array('budget', $eventColumns, true)
            && ($request['budget'] ?? '') !== ''
            && $request['budget'] !== null) {
            $row['budget'] = (float) $request['budget'];
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
             FROM event_requests r
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
                FROM event_requests r
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
            'SELECT COUNT(*) AS c FROM event_requests WHERE organization_id = :org AND status = :st',
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
             FROM event_request_comments c
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
            'event_requests',
            $this->proposalColumns($organizationId, $userId, $data, self::STATUS_PENDING)
        );
        $this->addComment($id, $userId, self::ACTION_SUBMITTED, trim((string) ($data['notes'] ?? '')) ?: 'Event request submitted.');
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
            throw new RuntimeException('You can only edit your own event requests.');
        }
        self::assertCanTransition($request['status'], 'update');
        $errors = self::validateProposal($data);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $cols = $this->proposalColumns($organizationId, $userId, $data, $request['status']);
        unset($cols['organization_id'], $cols['submitted_by'], $cols['status']);
        $this->db->update('event_requests', $id, $cols);
    }

    public function resubmit(int $id, int $organizationId, int $userId, string $message = ''): void
    {
        $request = $this->requireRequest($id, $organizationId);
        if ((int) $request['submitted_by'] !== $userId) {
            throw new RuntimeException('You can only resubmit your own event requests.');
        }
        self::assertCanTransition($request['status'], 'resubmit');
        $this->db->update('event_requests', $id, [
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
            throw new RuntimeException('You can only withdraw your own event requests.');
        }
        self::assertCanTransition($request['status'], 'withdraw');
        $this->addComment($id, $userId, self::ACTION_WITHDRAWN, 'Request withdrawn by submitter.');
        $this->db->execute(
            'DELETE FROM event_requests WHERE id = :id AND organization_id = :org',
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
        $this->db->update('event_requests', $id, [
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
        $this->db->update('event_requests', $id, [
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

        $eventColumns = [];
        try {
            $cols = $this->db->query('SHOW COLUMNS FROM events');
            $eventColumns = array_column($cols ?: [], 'Field');
        } catch (\Throwable $e) {
            $eventColumns = [];
        }

        $this->db->beginTransaction();
        try {
            $eventId = (int) $this->db->insert('events', self::buildEventInsert($request, $eventColumns));
            $this->db->update('event_requests', $id, [
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reviewer_comment' => $comment !== '' ? $comment : null,
                'event_id' => $eventId,
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
            $comment !== '' ? $comment : 'Request approved. Draft event created.'
        );
        $updated = $this->getById($id, $organizationId);
        if ($updated) {
            $this->notifySubmitter($updated, 'approved', $comment);
        }
        return $eventId;
    }

    public function userCanCompleteRequestEvent(int $organizationId, int $userId, int $eventId): bool
    {
        if ($eventId <= 0) {
            return false;
        }
        $row = $this->db->queryOne(
            'SELECT id FROM event_requests
             WHERE organization_id = :org AND event_id = :eid AND submitted_by = :uid AND status = :st',
            [
                'org' => $organizationId,
                'eid' => $eventId,
                'uid' => $userId,
                'st' => self::STATUS_APPROVED,
            ]
        );
        return !empty($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForEvent(int $organizationId, int $eventId): ?array
    {
        $row = $this->db->queryOne(
            'SELECT * FROM event_requests WHERE organization_id = :org AND event_id = :eid LIMIT 1',
            ['org' => $organizationId, 'eid' => $eventId]
        );
        return $row ?: null;
    }

    /**
     * @return list<array{id:int,email:string,first_name:string,last_name:string}>
     */
    public function listApprovers(int $organizationId): array
    {
        $users = $this->db->query(
            "SELECT id, email, first_name, last_name, role, is_super_admin
             FROM users
             WHERE organization_id = :org
               AND role IN ('admin', 'coordinator')
               AND status = 'active'
               AND email IS NOT NULL AND email != ''",
            ['org' => $organizationId]
        ) ?: [];

        $userOverrides = [];
        $roleOverrides = [];
        try {
            if ($this->db->tableExists('user_permissions')) {
                foreach ($this->db->query(
                    'SELECT user_id, granted FROM user_permissions
                     WHERE organization_id = :org AND permission_key = :k',
                    ['org' => $organizationId, 'k' => 'events.approve_requests']
                ) ?: [] as $r) {
                    $userOverrides[(int) $r['user_id']] = (bool) $r['granted'];
                }
            }
            if ($this->db->tableExists('role_permissions')) {
                foreach ($this->db->query(
                    'SELECT role, granted FROM role_permissions
                     WHERE organization_id = :org AND permission_key = :k',
                    ['org' => $organizationId, 'k' => 'events.approve_requests']
                ) ?: [] as $r) {
                    $roleOverrides[(string) $r['role']] = (bool) $r['granted'];
                }
            }
        } catch (\Throwable $e) {
            // Catalog defaults apply.
        }

        $out = [];
        foreach ($users as $u) {
            if ($this->userHasApproveCapability($u, $userOverrides, $roleOverrides)) {
                $out[] = [
                    'id' => (int) $u['id'],
                    'email' => (string) $u['email'],
                    'first_name' => (string) ($u['first_name'] ?? ''),
                    'last_name' => (string) ($u['last_name'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, bool> $userOverrides
     * @param array<string, bool> $roleOverrides
     */
    private function userHasApproveCapability(array $user, array $userOverrides, array $roleOverrides): bool
    {
        if (!empty($user['is_super_admin'])) {
            return true;
        }
        $uid = (int) $user['id'];
        if (array_key_exists($uid, $userOverrides)) {
            return $userOverrides[$uid];
        }
        $role = (string) ($user['role'] ?? '');
        if (array_key_exists($role, $roleOverrides)) {
            return $roleOverrides[$role];
        }
        return Permissions::roleDefault($role, 'events.approve_requests');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRequest(int $id, int $organizationId): array
    {
        $request = $this->getById($id, $organizationId);
        if (!$request) {
            throw new RuntimeException('Event request not found.');
        }
        return $request;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function proposalColumns(int $organizationId, int $userId, array $data, string $status): array
    {
        $budget = $data['budget'] ?? null;
        $attendance = $data['target_attendance'] ?? null;
        return [
            'organization_id' => $organizationId,
            'submitted_by' => $userId,
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'event_date' => $data['event_date'],
            'start_time' => self::emptyToNull($data['start_time'] ?? null),
            'end_time' => self::emptyToNull($data['end_time'] ?? null),
            'location' => self::emptyToNull($data['location'] ?? null),
            'category' => self::emptyToNull($data['category'] ?? null),
            'budget' => ($budget === '' || $budget === null) ? null : (float) $budget,
            'target_attendance' => ($attendance === '' || $attendance === null) ? null : (int) $attendance,
            'target_audience' => self::emptyToNull($data['target_audience'] ?? null),
            'notes' => self::emptyToNull($data['notes'] ?? null),
            'status' => $status,
        ];
    }

    private function addComment(int $requestId, int $userId, string $action, ?string $message): void
    {
        $this->db->insert('event_request_comments', [
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
        $link = '/admin/?page=event-request-details&id=' . $requestId;
        $inAppTitle = $resubmitted ? 'Event request resubmitted' : 'New event request';
        $inAppMsg = $submitter . ' ' . $verb . ' an event request: "' . $title . '".';
        $approvers = $this->listApprovers($orgId);

        foreach ($approvers as $approver) {
            NotificationHelper::create(
                $orgId,
                'event_request',
                $inAppTitle,
                $inAppMsg,
                (int) $approver['id'],
                $link
            );
        }

        try {
            $email = EventRequestEmailService::fromConfigFile();
            if ($email) {
                $email->notifyApprovers($request, $approvers, $resubmitted);
            }
        } catch (\Throwable $e) {
            error_log('Event request approver email failed: ' . $e->getMessage());
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
            $nTitle = 'Event request needs updates';
            $nMsg = 'Your event request "' . $title . '" was sent back. Please make the requested updates and resubmit.';
            $link = '/admin/?page=event-request-form&id=' . $requestId;
        } elseif ($kind === 'declined') {
            $nTitle = 'Event request declined';
            $nMsg = 'Unfortunately your event request "' . $title . '" has been declined.';
            $link = '/admin/?page=event-request-details&id=' . $requestId;
        } else {
            $nTitle = 'Event request approved';
            $nMsg = 'Your event request "' . $title . '" has been approved. Complete the remaining details and publish the draft event.';
            $eventId = (int) ($request['event_id'] ?? 0);
            $link = $eventId > 0
                ? '/admin/?page=event-edit&id=' . $eventId
                : '/admin/?page=event-request-details&id=' . $requestId;
        }

        NotificationHelper::create($orgId, 'event_request', $nTitle, $nMsg, $submitterId, $link);

        try {
            $email = EventRequestEmailService::fromConfigFile();
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
            error_log('Event request submitter email failed: ' . $e->getMessage());
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
