<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\NotificationHelper;
use Headcount\Services\EventPeopleService;

/**
 * Internal event checklist: roles, templates, leadership, generated tasks.
 */
class EventChecklistService
{
    public const PHASE_PRE = 'pre';
    public const PHASE_DAY_OF = 'day_of';
    public const PHASE_POST = 'post';

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETE = 'complete';

    public const MAX_ROLES_PER_USER = 3;

    /** @var array<string,string> */
    public const SYSTEM_ROLES = [
        'overall_lead' => 'Overall Event Lead / Director',
        'program_speakers' => 'Program & Speakers Lead',
        'logistics_venue' => 'Logistics & Venue Lead',
        'marketing_communications' => 'Marketing & Communications Lead',
        'protocol_vip' => 'Protocol & VIP / Guest Management Lead',
        'volunteers' => 'Volunteers Lead',
        'finance_fundraising' => 'Finance & Fundraising Lead',
    ];

    /** @var list<string> */
    public const OPS_CATEGORY_NAMES = [
        'Community',
        'Fundraising',
        'Religious',
        'Youth/Educational',
        'Social',
    ];

    private $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function tablesExist(): bool
    {
        return $this->db->tableExists('checklist_roles')
            && $this->db->tableExists('event_checklist_items');
    }

    /**
     * Event id whose checklist rows we read/write (series parent for recurring).
     */
    public static function storageEventId(array $eventRow): int
    {
        return EventPeopleService::peopleStorageEventId($eventRow);
    }

    /**
     * Full IMCA-style seed tasks: title, phase, section, role_key, due_offset_days.
     *
     * @return list<array{title:string,phase:string,section:string,role_key:string,due_offset_days:int}>
     */
    public static function imcaSeedTasks(): array
    {
        return [
            // Phase 1 — Pre-event: Program & Content
            ['title' => 'Finalize event theme, agenda, and minute-by-minute timeline.', 'phase' => 'pre', 'section' => 'Program & Content', 'role_key' => 'program_speakers', 'due_offset_days' => -21],
            ['title' => 'Identify and invite keynote speakers, scholars, or guest presenters.', 'phase' => 'pre', 'section' => 'Program & Content', 'role_key' => 'program_speakers', 'due_offset_days' => -28],
            ['title' => 'Collect speaker bios, headshots, travel itineraries, and presentation files.', 'phase' => 'pre', 'section' => 'Program & Content', 'role_key' => 'program_speakers', 'due_offset_days' => -14],
            ['title' => 'Confirm Emcee / MC and draft the script/cues.', 'phase' => 'pre', 'section' => 'Program & Content', 'role_key' => 'program_speakers', 'due_offset_days' => -7],
            ['title' => 'Plan youth/kids activities or childcare program if applicable.', 'phase' => 'pre', 'section' => 'Program & Content', 'role_key' => 'program_speakers', 'due_offset_days' => -14],
            // Venue & Setup
            ['title' => 'Conduct site visit and venue walkthrough.', 'phase' => 'pre', 'section' => 'Venue & Setup', 'role_key' => 'logistics_venue', 'due_offset_days' => -28],
            ['title' => 'Arrange and layout prayer area.', 'phase' => 'pre', 'section' => 'Venue & Setup', 'role_key' => 'logistics_venue', 'due_offset_days' => -7],
            ['title' => 'Confirm AV, sound, and lighting requirements.', 'phase' => 'pre', 'section' => 'Venue & Setup', 'role_key' => 'logistics_venue', 'due_offset_days' => -14],
            ['title' => 'Stage setup and seating layout plan.', 'phase' => 'pre', 'section' => 'Venue & Setup', 'role_key' => 'logistics_venue', 'due_offset_days' => -7],
            ['title' => 'Parking, signage, and wayfinding plan.', 'phase' => 'pre', 'section' => 'Venue & Setup', 'role_key' => 'logistics_venue', 'due_offset_days' => -3],
            // Marketing & Registration
            ['title' => 'Set up tickets and pricing.', 'phase' => 'pre', 'section' => 'Marketing & Registration', 'role_key' => 'marketing_communications', 'due_offset_days' => -21],
            ['title' => 'Design and print flyers/posters.', 'phase' => 'pre', 'section' => 'Marketing & Registration', 'role_key' => 'marketing_communications', 'due_offset_days' => -21],
            ['title' => 'Launch social media campaign and announcements.', 'phase' => 'pre', 'section' => 'Marketing & Registration', 'role_key' => 'marketing_communications', 'due_offset_days' => -14],
            ['title' => 'Monitor and track RSVPs through registration deadline.', 'phase' => 'pre', 'section' => 'Marketing & Registration', 'role_key' => 'marketing_communications', 'due_offset_days' => -1],
            // Catering, Protocol & Sponsorships
            ['title' => 'Confirm halal menu and catering vendor.', 'phase' => 'pre', 'section' => 'Catering, Protocol & Sponsorships', 'role_key' => 'protocol_vip', 'due_offset_days' => -14],
            ['title' => 'Identify VIP guests and special requirements.', 'phase' => 'pre', 'section' => 'Catering, Protocol & Sponsorships', 'role_key' => 'protocol_vip', 'due_offset_days' => -14],
            ['title' => 'Seating plan for VIPs and dignitaries.', 'phase' => 'pre', 'section' => 'Catering, Protocol & Sponsorships', 'role_key' => 'protocol_vip', 'due_offset_days' => -7],
            ['title' => 'Secure sponsors and confirm deliverables.', 'phase' => 'pre', 'section' => 'Catering, Protocol & Sponsorships', 'role_key' => 'finance_fundraising', 'due_offset_days' => -21],
            ['title' => 'Prepare sponsor recognition materials.', 'phase' => 'pre', 'section' => 'Catering, Protocol & Sponsorships', 'role_key' => 'marketing_communications', 'due_offset_days' => -3],
            // Manpower & Logistics
            ['title' => 'Recruit and schedule volunteers.', 'phase' => 'pre', 'section' => 'Manpower & Logistics', 'role_key' => 'volunteers', 'due_offset_days' => -14],
            ['title' => 'Order volunteer shirts/uniforms.', 'phase' => 'pre', 'section' => 'Manpower & Logistics', 'role_key' => 'volunteers', 'due_offset_days' => -21],
            ['title' => 'Prepare guest gifts/welcome bags.', 'phase' => 'pre', 'section' => 'Manpower & Logistics', 'role_key' => 'volunteers', 'due_offset_days' => -7],
            ['title' => 'Arrange radios/communication devices.', 'phase' => 'pre', 'section' => 'Manpower & Logistics', 'role_key' => 'logistics_venue', 'due_offset_days' => -3],
            ['title' => 'Confirm first aid and safety coverage.', 'phase' => 'pre', 'section' => 'Manpower & Logistics', 'role_key' => 'logistics_venue', 'due_offset_days' => -7],
            // Phase 2 — Day-of
            ['title' => 'Complete venue and technical setup.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'logistics_venue', 'due_offset_days' => 0],
            ['title' => 'Registration desk staffed and ready.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'marketing_communications', 'due_offset_days' => 0],
            ['title' => 'Prayer area prepared and accessible.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'logistics_venue', 'due_offset_days' => 0],
            ['title' => 'VIP room prepared.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'protocol_vip', 'due_offset_days' => 0],
            ['title' => 'Volunteer briefing completed.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'volunteers', 'due_offset_days' => 0],
            ['title' => 'Speaker flow and timing confirmed.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'program_speakers', 'due_offset_days' => 0],
            ['title' => 'Catering service on schedule.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'protocol_vip', 'due_offset_days' => 0],
            ['title' => 'Photography/videography in place.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'marketing_communications', 'due_offset_days' => 0],
            ['title' => 'Final walkthrough with all leads.', 'phase' => 'day_of', 'section' => 'Day-of Execution', 'role_key' => 'overall_lead', 'due_offset_days' => 0],
            // Phase 3 — Post-event
            ['title' => 'Teardown and equipment return.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'logistics_venue', 'due_offset_days' => 1],
            ['title' => 'Send thank-you notes to speakers, sponsors, and volunteers.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'marketing_communications', 'due_offset_days' => 3],
            ['title' => 'Distribute post-event surveys.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'marketing_communications', 'due_offset_days' => 3],
            ['title' => 'Reconcile budget and expenses.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'finance_fundraising', 'due_offset_days' => 7],
            ['title' => 'Complete event summary report.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'overall_lead', 'due_offset_days' => 7],
            ['title' => 'Archive photos and media.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'marketing_communications', 'due_offset_days' => 14],
            ['title' => 'Debrief meeting with leadership team.', 'phase' => 'post', 'section' => 'Post-Event Wrap-up', 'role_key' => 'overall_lead', 'due_offset_days' => 7],
        ];
    }

    /**
     * Ensure org has system roles, default template, and ops category templates.
     */
    public function ensureOrgDefaults(int $organizationId): void
    {
        if (!$this->tablesExist() || $organizationId <= 0) {
            return;
        }

        $roleIds = $this->ensureRoles($organizationId);
        $this->ensureDefaultTemplate($organizationId, $roleIds, null, 'Default Event Checklist');
        $this->ensureOpsCategories($organizationId);

        foreach (self::OPS_CATEGORY_NAMES as $catName) {
            $cat = $this->db->queryOne(
                'SELECT id FROM categories WHERE organization_id = :oid AND name = :name LIMIT 1',
                ['oid' => $organizationId, 'name' => $catName]
            );
            if ($cat) {
                $existing = $this->db->queryOne(
                    'SELECT id FROM checklist_templates WHERE organization_id = :oid AND category_id = :cid LIMIT 1',
                    ['oid' => $organizationId, 'cid' => (int) $cat['id']]
                );
                if (!$existing) {
                    $this->ensureDefaultTemplate($organizationId, $roleIds, (int) $cat['id'], $catName . ' Checklist');
                }
            }
        }
    }

    /**
     * @return array<string,int> role_key => role id
     */
    private function ensureRoles(int $organizationId): array
    {
        $roleIds = [];
        $sort = 0;
        foreach (self::SYSTEM_ROLES as $key => $label) {
            $existing = $this->db->queryOne(
                'SELECT id FROM checklist_roles WHERE organization_id = :oid AND role_key = :k LIMIT 1',
                ['oid' => $organizationId, 'k' => $key]
            );
            if ($existing) {
                $roleIds[$key] = (int) $existing['id'];
            } else {
                $roleIds[$key] = (int) $this->db->insert('checklist_roles', [
                    'organization_id' => $organizationId,
                    'role_key' => $key,
                    'label' => $label,
                    'is_system' => 1,
                    'sort_order' => $sort,
                ]);
            }
            $sort++;
        }
        return $roleIds;
    }

    private function ensureOpsCategories(int $organizationId): void
    {
        if (!$this->db->tableExists('categories')) {
            return;
        }
        $sort = 100;
        foreach (self::OPS_CATEGORY_NAMES as $name) {
            $existing = $this->db->queryOne(
                'SELECT id FROM categories WHERE organization_id = :oid AND name = :name LIMIT 1',
                ['oid' => $organizationId, 'name' => $name]
            );
            if (!$existing) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                $slug = trim($slug, '-');
                try {
                    $this->db->insert('categories', [
                        'organization_id' => $organizationId,
                        'name' => $name,
                        'slug' => $slug,
                        'color' => '#059669',
                        'is_active' => 1,
                        'sort_order' => $sort,
                    ]);
                } catch (\Throwable $e) {
                    // ignore duplicate slug etc.
                }
            }
            $sort++;
        }
    }

    /**
     * @param array<string,int> $roleIds
     */
    private function ensureDefaultTemplate(int $organizationId, array $roleIds, ?int $categoryId, string $name): int
    {
        $existing = $this->db->queryOne(
            $categoryId === null
                ? 'SELECT id FROM checklist_templates WHERE organization_id = :oid AND category_id IS NULL LIMIT 1'
                : 'SELECT id FROM checklist_templates WHERE organization_id = :oid AND category_id = :cid LIMIT 1',
            $categoryId === null
                ? ['oid' => $organizationId]
                : ['oid' => $organizationId, 'cid' => $categoryId]
        );

        if ($existing) {
            $templateId = (int) $existing['id'];
            $count = $this->db->queryOne(
                'SELECT COUNT(*) AS c FROM checklist_template_tasks WHERE template_id = :tid',
                ['tid' => $templateId]
            );
            if ((int) ($count['c'] ?? 0) > 0) {
                return $templateId;
            }
        } else {
            $templateId = (int) $this->db->insert('checklist_templates', [
                'organization_id' => $organizationId,
                'category_id' => $categoryId,
                'name' => $name,
                'is_active' => 1,
            ]);
        }

        $sort = 0;
        foreach (self::imcaSeedTasks() as $task) {
            $roleId = $roleIds[$task['role_key']] ?? null;
            $this->db->insert('checklist_template_tasks', [
                'template_id' => $templateId,
                'title' => $task['title'],
                'phase' => $task['phase'],
                'section' => $task['section'],
                'default_role_id' => $roleId,
                'due_offset_days' => $task['due_offset_days'],
                'sort_order' => $sort++,
            ]);
        }

        return $templateId;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRoles(int $organizationId): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        $this->ensureOrgDefaults($organizationId);
        return $this->db->query(
            'SELECT id, role_key, label, is_system, sort_order
             FROM checklist_roles WHERE organization_id = :oid ORDER BY sort_order ASC, id ASC',
            ['oid' => $organizationId]
        ) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEligibleAssignees(int $organizationId, ?string $search = null): array
    {
        $sql = "SELECT id, first_name, last_name, email, role
                FROM users
                WHERE organization_id = :org AND status = 'active' AND role IN ('admin','coordinator')";
        $params = ['org' => $organizationId];
        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (first_name LIKE :q OR last_name LIKE :q OR email LIKE :q OR CONCAT(first_name,' ',last_name) LIKE :q)";
            $params['q'] = '%' . trim($search) . '%';
        }
        $sql .= ' ORDER BY role ASC, last_name ASC, first_name ASC LIMIT 50';
        return $this->db->query($sql, $params) ?: [];
    }

    public function resolveTemplateId(int $organizationId, int $eventId): ?int
    {
        if (!$this->tablesExist()) {
            return null;
        }
        $this->ensureOrgDefaults($organizationId);

        $catRow = $this->db->queryOne(
            'SELECT c.id FROM event_categories ec
             INNER JOIN categories c ON c.id = ec.category_id
             WHERE ec.event_id = :eid AND c.organization_id = :oid
             ORDER BY c.sort_order ASC, c.id ASC LIMIT 1',
            ['eid' => $eventId, 'oid' => $organizationId]
        );
        if ($catRow) {
            $tpl = $this->db->queryOne(
                'SELECT id FROM checklist_templates WHERE organization_id = :oid AND category_id = :cid AND is_active = 1 LIMIT 1',
                ['oid' => $organizationId, 'cid' => (int) $catRow['id']]
            );
            if ($tpl) {
                return (int) $tpl['id'];
            }
        }

        $event = $this->db->queryOne('SELECT category FROM events WHERE id = :id', ['id' => $eventId]);
        if ($event && !empty($event['category'])) {
            $cat = $this->db->queryOne(
                'SELECT id FROM categories WHERE organization_id = :oid AND name = :name LIMIT 1',
                ['oid' => $organizationId, 'name' => $event['category']]
            );
            if ($cat) {
                $tpl = $this->db->queryOne(
                    'SELECT id FROM checklist_templates WHERE organization_id = :oid AND category_id = :cid AND is_active = 1 LIMIT 1',
                    ['oid' => $organizationId, 'cid' => (int) $cat['id']]
                );
                if ($tpl) {
                    return (int) $tpl['id'];
                }
            }
        }

        $default = $this->db->queryOne(
            'SELECT id FROM checklist_templates WHERE organization_id = :oid AND category_id IS NULL AND is_active = 1 LIMIT 1',
            ['oid' => $organizationId]
        );
        return $default ? (int) $default['id'] : null;
    }

    /**
     * @param array<int,int|string> $assignments role_id => user_id
     * @return array{ok:bool,error?:string}
     */
    public function setLeadership(int $storageEventId, int $organizationId, array $assignments): array
    {
        if (!$this->tablesExist()) {
            return ['ok' => false, 'error' => 'Checklist tables not installed.'];
        }

        $roles = $this->listRoles($organizationId);
        $roleById = [];
        $overallRoleId = null;
        foreach ($roles as $r) {
            $roleById[(int) $r['id']] = $r;
            if (($r['role_key'] ?? '') === 'overall_lead') {
                $overallRoleId = (int) $r['id'];
            }
        }

        $clean = [];
        foreach ($assignments as $roleId => $userId) {
            $roleId = (int) $roleId;
            $userId = (int) $userId;
            if ($roleId <= 0 || $userId <= 0 || !isset($roleById[$roleId])) {
                continue;
            }
            $u = $this->db->queryOne(
                "SELECT id FROM users WHERE id = :uid AND organization_id = :oid AND status = 'active' AND role IN ('admin','coordinator')",
                ['uid' => $userId, 'oid' => $organizationId]
            );
            if (!$u) {
                return ['ok' => false, 'error' => 'Invalid assignee for role.'];
            }
            $clean[$roleId] = $userId;
        }

        if ($overallRoleId === null || empty($clean[$overallRoleId])) {
            return ['ok' => false, 'error' => 'Overall Event Lead is required.'];
        }

        $userRoleCount = [];
        foreach ($clean as $roleId => $userId) {
            $userRoleCount[$userId] = ($userRoleCount[$userId] ?? 0) + 1;
            if ($userRoleCount[$userId] > self::MAX_ROLES_PER_USER) {
                return ['ok' => false, 'error' => 'Each person may hold at most ' . self::MAX_ROLES_PER_USER . ' leadership roles.'];
            }
        }

        $this->db->execute('DELETE FROM event_leadership WHERE event_id = :eid', ['eid' => $storageEventId]);
        foreach ($clean as $roleId => $userId) {
            $this->db->insert('event_leadership', [
                'event_id' => $storageEventId,
                'role_id' => $roleId,
                'user_id' => $userId,
            ]);
        }

        return ['ok' => true];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getLeadership(int $storageEventId): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        return $this->db->query(
            'SELECT el.role_id, el.user_id, cr.role_key, cr.label AS role_label,
                    u.first_name, u.last_name, u.email
             FROM event_leadership el
             INNER JOIN checklist_roles cr ON cr.id = el.role_id
             INNER JOIN users u ON u.id = el.user_id
             WHERE el.event_id = :eid
             ORDER BY cr.sort_order ASC',
            ['eid' => $storageEventId]
        ) ?: [];
    }

    /**
     * @return array{ok:bool,error?:string,template_id?:int,template_name?:string,tasks?:list<array>,added_task_ids?:list<int>}
     */
    public function getTemplatePreviewForEvent(int $eventId, int $organizationId): array
    {
        if (!$this->tablesExist()) {
            return ['ok' => false, 'error' => 'Checklist tables not installed.'];
        }

        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event not found.'];
        }

        $storageId = self::storageEventId($event);
        $templateId = $this->resolveTemplateId($organizationId, $storageId);
        if (!$templateId) {
            return ['ok' => false, 'error' => 'No checklist template found.'];
        }

        $tpl = $this->db->queryOne(
            'SELECT id, name FROM checklist_templates WHERE id = :id AND organization_id = :oid',
            ['id' => $templateId, 'oid' => $organizationId]
        );

        $addedRows = $this->db->query(
            'SELECT template_task_id FROM event_checklist_items
             WHERE event_id = :eid AND template_task_id IS NOT NULL',
            ['eid' => $storageId]
        ) ?: [];
        $addedIds = [];
        foreach ($addedRows as $row) {
            $addedIds[] = (int) $row['template_task_id'];
        }

        return [
            'ok' => true,
            'template_id' => $templateId,
            'template_name' => $tpl['name'] ?? 'Event checklist template',
            'tasks' => $this->listTemplateTasks($templateId, $organizationId),
            'added_task_ids' => $addedIds,
        ];
    }

    /**
     * @param list<int>|null $templateTaskIds When set, only these template task IDs are added.
     * @return array{ok:bool,error?:string,created?:int,skipped?:int}
     */
    public function generateForEvent(
        int $eventId,
        int $organizationId,
        bool $notify = true,
        ?array $templateTaskIds = null,
        bool $merge = false
    ): array {
        if (!$this->tablesExist()) {
            return ['ok' => false, 'error' => 'Checklist tables not installed.'];
        }

        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event not found.'];
        }

        $storageId = self::storageEventId($event);
        $existing = $this->db->queryOne(
            'SELECT COUNT(*) AS c FROM event_checklist_items WHERE event_id = :eid',
            ['eid' => $storageId]
        );
        if (!$merge && (int) ($existing['c'] ?? 0) > 0) {
            return ['ok' => true, 'created' => 0, 'skipped' => 0];
        }

        $templateId = $this->resolveTemplateId($organizationId, $storageId);
        if (!$templateId) {
            return ['ok' => false, 'error' => 'No checklist template found.'];
        }

        $tasks = $this->db->query(
            'SELECT * FROM checklist_template_tasks WHERE template_id = :tid ORDER BY phase ASC, sort_order ASC',
            ['tid' => $templateId]
        ) ?: [];

        if ($templateTaskIds !== null) {
            $allowed = array_flip(array_map('intval', $templateTaskIds));
            if ($allowed === []) {
                return ['ok' => false, 'error' => 'Select at least one task.'];
            }
            $tasks = array_values(array_filter($tasks, static function ($t) use ($allowed) {
                return isset($allowed[(int) $t['id']]);
            }));
            if ($tasks === []) {
                return ['ok' => false, 'error' => 'No matching template tasks selected.'];
            }
        }

        $existingTemplateIds = [];
        if ($merge) {
            $existingRows = $this->db->query(
                'SELECT template_task_id FROM event_checklist_items
                 WHERE event_id = :eid AND template_task_id IS NOT NULL',
                ['eid' => $storageId]
            ) ?: [];
            foreach ($existingRows as $row) {
                $existingTemplateIds[(int) $row['template_task_id']] = true;
            }
        }

        $leadMap = $this->leadershipUserByRoleId($storageId);
        $eventDate = $event['event_date'] ?? null;
        $created = 0;
        $skipped = 0;
        $notifyUsers = [];

        foreach ($tasks as $t) {
            $templateTaskId = (int) $t['id'];
            if ($merge && isset($existingTemplateIds[$templateTaskId])) {
                $skipped++;
                continue;
            }

            $roleId = $t['default_role_id'] ? (int) $t['default_role_id'] : null;
            $assigneeId = $roleId && isset($leadMap[$roleId]) ? $leadMap[$roleId] : null;
            $dueDate = $this->computeDueDate($eventDate, (int) $t['due_offset_days']);

            $this->db->insert('event_checklist_items', [
                'event_id' => $storageId,
                'template_task_id' => $templateTaskId,
                'title' => $t['title'],
                'phase' => $t['phase'],
                'section' => $t['section'],
                'role_id' => $roleId,
                'assignee_user_id' => $assigneeId,
                'status' => self::STATUS_NOT_STARTED,
                'due_date' => $dueDate,
                'sort_order' => (int) $t['sort_order'],
            ]);
            $created++;

            if ($assigneeId) {
                $notifyUsers[$assigneeId] = true;
            }
        }

        if ($created === 0 && $skipped > 0) {
            return ['ok' => false, 'error' => 'All selected tasks are already on this checklist.'];
        }

        if ($notify && $created > 0) {
            $this->notifyAssignees(array_keys($notifyUsers), $organizationId, $event, $storageId, 'generated');
        }

        return ['ok' => true, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param list<int>|null $templateTaskIds
     * @return array{ok:bool,error?:string,replaced?:int,created?:int}
     */
    public function replaceFromTemplate(
        int $eventId,
        int $organizationId,
        bool $notify = true,
        ?array $templateTaskIds = null
    ): array {
        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event not found.'];
        }
        $storageId = self::storageEventId($event);
        $this->db->execute('DELETE FROM event_checklist_items WHERE event_id = :eid', ['eid' => $storageId]);
        $result = $this->generateForEvent($eventId, $organizationId, $notify, $templateTaskIds, false);
        if (!$result['ok']) {
            return $result;
        }
        return [
            'ok' => true,
            'replaced' => $result['created'] ?? 0,
            'created' => $result['created'] ?? 0,
        ];
    }

    public function recalculateDueDates(int $eventId, int $organizationId): array
    {
        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event not found.'];
        }
        $storageId = self::storageEventId($event);
        $eventDate = $event['event_date'] ?? null;
        if (!$eventDate) {
            return ['ok' => false, 'error' => 'Event date required.'];
        }

        $items = $this->db->query(
            "SELECT i.id, i.status, t.due_offset_days
             FROM event_checklist_items i
             LEFT JOIN checklist_template_tasks t ON t.id = i.template_task_id
             WHERE i.event_id = :eid AND i.status IN ('not_started','in_progress')",
            ['eid' => $storageId]
        ) ?: [];

        $updated = 0;
        foreach ($items as $item) {
            $offset = isset($item['due_offset_days']) ? (int) $item['due_offset_days'] : null;
            if ($offset === null) {
                continue;
            }
            $due = $this->computeDueDate($eventDate, $offset);
            $this->db->update('event_checklist_items', (int) $item['id'], ['due_date' => $due]);
            $updated++;
        }

        return ['ok' => true, 'updated' => $updated];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listItemsForEvent(int $eventId, int $organizationId): array
    {
        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event || !$this->tablesExist()) {
            return [];
        }
        $storageId = self::storageEventId($event);
        return $this->db->query(
            'SELECT i.*, cr.label AS role_label, cr.role_key,
                    u.first_name AS assignee_first_name, u.last_name AS assignee_last_name
             FROM event_checklist_items i
             LEFT JOIN checklist_roles cr ON cr.id = i.role_id
             LEFT JOIN users u ON u.id = i.assignee_user_id
             WHERE i.event_id = :eid
             ORDER BY FIELD(i.phase, \'pre\', \'day_of\', \'post\'), i.sort_order ASC, i.id ASC',
            ['eid' => $storageId]
        ) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listItemsForAssignee(int $organizationId, int $userId, ?string $statusFilter = null): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        $sql = "SELECT i.*, e.title AS event_title, e.event_date, e.status AS event_status,
                       cr.label AS role_label
                FROM event_checklist_items i
                INNER JOIN events e ON e.id = i.event_id AND e.organization_id = :oid
                LEFT JOIN checklist_roles cr ON cr.id = i.role_id
                WHERE i.assignee_user_id = :uid";
        $params = ['oid' => $organizationId, 'uid' => $userId];
        if ($statusFilter === 'open') {
            $sql .= " AND i.status IN ('not_started','in_progress')";
        } elseif ($statusFilter === 'complete') {
            $sql .= " AND i.status = 'complete'";
        }
        $sql .= ' ORDER BY i.due_date ASC, e.event_date ASC, i.sort_order ASC';
        return $this->db->query($sql, $params) ?: [];
    }

    /**
     * @return array{total:int,complete:int,in_progress:int,not_started:int,pct:int}
     */
    public function progressForEvent(int $eventId, int $organizationId): array
    {
        $items = $this->listItemsForEvent($eventId, $organizationId);
        $total = count($items);
        $complete = 0;
        $inProgress = 0;
        foreach ($items as $i) {
            if ($i['status'] === self::STATUS_COMPLETE) {
                $complete++;
            } elseif ($i['status'] === self::STATUS_IN_PROGRESS) {
                $inProgress++;
            }
        }
        $pct = $total > 0 ? (int) round(($complete / $total) * 100) : 0;
        return [
            'total' => $total,
            'complete' => $complete,
            'in_progress' => $inProgress,
            'not_started' => $total - $complete - $inProgress,
            'pct' => $pct,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,error?:string}
     */
    public function updateItem(int $itemId, int $organizationId, int $actorUserId, array $payload, bool $canManageAll): array
    {
        $item = $this->db->queryOne(
            'SELECT i.*, e.organization_id, e.title AS event_title, e.id AS raw_event_id
             FROM event_checklist_items i
             INNER JOIN events e ON e.id = i.event_id
             WHERE i.id = :id AND e.organization_id = :oid',
            ['id' => $itemId, 'oid' => $organizationId]
        );
        if (!$item) {
            return ['ok' => false, 'error' => 'Task not found.'];
        }

        $isAssignee = (int) ($item['assignee_user_id'] ?? 0) === $actorUserId;
        if (!$canManageAll && !$isAssignee) {
            return ['ok' => false, 'error' => 'You cannot edit this task.'];
        }

        $update = [];
        if (isset($payload['status']) && in_array($payload['status'], [self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETE], true)) {
            $update['status'] = $payload['status'];
            if ($payload['status'] === self::STATUS_COMPLETE) {
                $update['completed_at'] = date('Y-m-d H:i:s');
                $update['completed_by'] = $actorUserId;
            } else {
                $update['completed_at'] = null;
                $update['completed_by'] = null;
            }
        }

        if (array_key_exists('notes', $payload)) {
            $update['notes'] = $payload['notes'] !== '' ? (string) $payload['notes'] : null;
        }

        if ($canManageAll) {
            if (array_key_exists('assignee_user_id', $payload)) {
                $newAssignee = $payload['assignee_user_id'] ? (int) $payload['assignee_user_id'] : null;
                if ($newAssignee) {
                    $valid = $this->db->queryOne(
                        "SELECT id FROM users WHERE id = :uid AND organization_id = :oid AND status = 'active' AND role IN ('admin','coordinator')",
                        ['uid' => $newAssignee, 'oid' => $organizationId]
                    );
                    if (!$valid) {
                        return ['ok' => false, 'error' => 'Invalid assignee.'];
                    }
                }
                $oldAssignee = (int) ($item['assignee_user_id'] ?? 0);
                $update['assignee_user_id'] = $newAssignee;
                if ($newAssignee && $newAssignee !== $oldAssignee) {
                    $event = $this->getEventRow((int) $item['raw_event_id'], $organizationId);
                    if ($event) {
                        $this->notifyAssignees([$newAssignee], $organizationId, $event, (int) $item['event_id'], 'reassigned');
                    }
                }
            }
            if (array_key_exists('due_date', $payload)) {
                $update['due_date'] = $payload['due_date'] ?: null;
            }
            if (isset($payload['title']) && trim((string) $payload['title']) !== '') {
                $update['title'] = trim((string) $payload['title']);
            }
        }

        if ($update === []) {
            return ['ok' => true];
        }

        $this->db->update('event_checklist_items', $itemId, $update);
        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function addCustomItem(int $eventId, int $organizationId, array $data): array
    {
        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event not found.'];
        }
        $storageId = self::storageEventId($event);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'Title required.'];
        }
        $phase = in_array($data['phase'] ?? '', [self::PHASE_PRE, self::PHASE_DAY_OF, self::PHASE_POST], true)
            ? $data['phase'] : self::PHASE_PRE;

        $maxSort = $this->db->queryOne(
            'SELECT COALESCE(MAX(sort_order), 0) AS m FROM event_checklist_items WHERE event_id = :eid',
            ['eid' => $storageId]
        );
        $roleId = !empty($data['role_id']) ? (int) $data['role_id'] : null;
        $assigneeId = !empty($data['assignee_user_id']) ? (int) $data['assignee_user_id'] : null;
        if (!$assigneeId && $roleId) {
            $leadMap = $this->leadershipUserByRoleId($storageId);
            $assigneeId = $leadMap[$roleId] ?? null;
        }

        $id = (int) $this->db->insert('event_checklist_items', [
            'event_id' => $storageId,
            'template_task_id' => null,
            'title' => $title,
            'phase' => $phase,
            'section' => trim((string) ($data['section'] ?? 'Custom')),
            'role_id' => $roleId,
            'assignee_user_id' => $assigneeId,
            'status' => self::STATUS_NOT_STARTED,
            'due_date' => !empty($data['due_date']) ? $data['due_date'] : $this->computeDueDate($event['event_date'] ?? null, -7),
            'sort_order' => (int) ($maxSort['m'] ?? 0) + 1,
        ]);

        return ['ok' => true, 'id' => $id];
    }

    public function deleteItem(int $itemId, int $organizationId): array
    {
        $item = $this->db->queryOne(
            'SELECT i.id FROM event_checklist_items i
             INNER JOIN events e ON e.id = i.event_id
             WHERE i.id = :id AND e.organization_id = :oid',
            ['id' => $itemId, 'oid' => $organizationId]
        );
        if (!$item) {
            return ['ok' => false, 'error' => 'Task not found.'];
        }
        $this->db->execute('DELETE FROM event_checklist_items WHERE id = :id', ['id' => $itemId]);
        return ['ok' => true];
    }

    public function isOverallLead(int $storageEventId, int $userId, int $organizationId): bool
    {
        if (!$this->tablesExist()) {
            return false;
        }
        $row = $this->db->queryOne(
            'SELECT el.id FROM event_leadership el
             INNER JOIN checklist_roles cr ON cr.id = el.role_id
             WHERE el.event_id = :eid AND el.user_id = :uid AND cr.role_key = :rk AND cr.organization_id = :oid',
            ['eid' => $storageEventId, 'uid' => $userId, 'rk' => 'overall_lead', 'oid' => $organizationId]
        );
        return !empty($row);
    }

    public function canManageEventChecklist(int $eventId, int $organizationId, int $userId, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }
        $event = $this->getEventRow($eventId, $organizationId);
        if (!$event) {
            return false;
        }
        return $this->isOverallLead(self::storageEventId($event), $userId, $organizationId);
    }

    // ---- Template management (Settings) ------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public function listTemplates(int $organizationId): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        $this->ensureOrgDefaults($organizationId);
        return $this->db->query(
            'SELECT t.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM checklist_template_tasks tt WHERE tt.template_id = t.id) AS task_count
             FROM checklist_templates t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.organization_id = :oid
             ORDER BY t.category_id IS NULL DESC, c.name ASC',
            ['oid' => $organizationId]
        ) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTemplateTasks(int $templateId, int $organizationId): array
    {
        $tpl = $this->db->queryOne(
            'SELECT id FROM checklist_templates WHERE id = :id AND organization_id = :oid',
            ['id' => $templateId, 'oid' => $organizationId]
        );
        if (!$tpl) {
            return [];
        }
        return $this->db->query(
            'SELECT tt.*, cr.label AS role_label, cr.role_key
             FROM checklist_template_tasks tt
             LEFT JOIN checklist_roles cr ON cr.id = tt.default_role_id
             WHERE tt.template_id = :tid
             ORDER BY FIELD(tt.phase, \'pre\', \'day_of\', \'post\'), tt.sort_order ASC',
            ['tid' => $templateId]
        ) ?: [];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveTemplateTask(int $organizationId, array $data): array
    {
        $templateId = (int) ($data['template_id'] ?? 0);
        $tpl = $this->db->queryOne(
            'SELECT id FROM checklist_templates WHERE id = :id AND organization_id = :oid',
            ['id' => $templateId, 'oid' => $organizationId]
        );
        if (!$tpl) {
            return ['ok' => false, 'error' => 'Template not found.'];
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'Title required.'];
        }

        $row = [
            'template_id' => $templateId,
            'title' => $title,
            'phase' => in_array($data['phase'] ?? '', [self::PHASE_PRE, self::PHASE_DAY_OF, self::PHASE_POST], true) ? $data['phase'] : self::PHASE_PRE,
            'section' => trim((string) ($data['section'] ?? '')),
            'default_role_id' => !empty($data['default_role_id']) ? (int) $data['default_role_id'] : null,
            'due_offset_days' => (int) ($data['due_offset_days'] ?? -7),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        $taskId = (int) ($data['id'] ?? 0);
        if ($taskId > 0) {
            unset($row['template_id']);
            $this->db->update('checklist_template_tasks', $taskId, $row);
            return ['ok' => true, 'id' => $taskId];
        }

        $id = (int) $this->db->insert('checklist_template_tasks', $row);
        return ['ok' => true, 'id' => $id];
    }

    public function deleteTemplateTask(int $taskId, int $organizationId): array
    {
        $task = $this->db->queryOne(
            'SELECT tt.id FROM checklist_template_tasks tt
             INNER JOIN checklist_templates t ON t.id = tt.template_id
             WHERE tt.id = :id AND t.organization_id = :oid',
            ['id' => $taskId, 'oid' => $organizationId]
        );
        if (!$task) {
            return ['ok' => false, 'error' => 'Task not found.'];
        }
        $this->db->execute('DELETE FROM checklist_template_tasks WHERE id = :id', ['id' => $taskId]);
        return ['ok' => true];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveCustomRole(int $organizationId, array $data): array
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return ['ok' => false, 'error' => 'Label required.'];
        }
        $key = trim((string) ($data['role_key'] ?? ''));
        if ($key === '') {
            $key = 'custom_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
        }
        $existing = $this->db->queryOne(
            'SELECT id FROM checklist_roles WHERE organization_id = :oid AND role_key = :k',
            ['oid' => $organizationId, 'k' => $key]
        );
        if ($existing) {
            return ['ok' => false, 'error' => 'Role key already exists.'];
        }
        $max = $this->db->queryOne('SELECT COALESCE(MAX(sort_order),0) AS m FROM checklist_roles WHERE organization_id = :oid', ['oid' => $organizationId]);
        $id = (int) $this->db->insert('checklist_roles', [
            'organization_id' => $organizationId,
            'role_key' => $key,
            'label' => $label,
            'is_system' => 0,
            'sort_order' => (int) ($max['m'] ?? 0) + 1,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public function expectedTaskCount(int $organizationId, int $eventId): int
    {
        $templateId = $this->resolveTemplateId($organizationId, $eventId);
        if (!$templateId) {
            return count(self::imcaSeedTasks());
        }
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS c FROM checklist_template_tasks WHERE template_id = :tid',
            ['tid' => $templateId]
        );
        return (int) ($row['c'] ?? 0);
    }

    // ---- Internals -----------------------------------------------------------

    private function getEventRow(int $eventId, int $organizationId): ?array
    {
        $cols = 'id, organization_id, title, event_date, status, parent_event_id';
        if ($this->db->hasColumn('events', 'target_attendance')) {
            $cols .= ', target_attendance, budget';
        }
        return $this->db->queryOne(
            "SELECT {$cols} FROM events WHERE id = :id AND organization_id = :oid",
            ['id' => $eventId, 'oid' => $organizationId]
        ) ?: null;
    }

    /**
     * @return array<int,int> role_id => user_id
     */
    private function leadershipUserByRoleId(int $storageEventId): array
    {
        $rows = $this->getLeadership($storageEventId);
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['role_id']] = (int) $r['user_id'];
        }
        return $map;
    }

    private function computeDueDate(?string $eventDate, int $offsetDays): ?string
    {
        if (!$eventDate) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($eventDate);
            if ($offsetDays !== 0) {
                $dt = $dt->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' days');
            }
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param list<int> $userIds
     * @param array<string,mixed> $event
     */
    private function notifyAssignees(array $userIds, int $organizationId, array $event, int $storageEventId, string $reason): void
    {
        $title = $event['title'] ?? 'Event';
        $link = '/admin/?page=event-checklist&event_id=' . $storageEventId;
        $msg = $reason === 'reassigned'
            ? "You have been assigned checklist tasks for \"{$title}\"."
            : "Checklist tasks for \"{$title}\" are ready for you.";

        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            try {
                NotificationHelper::create($organizationId, 'checklist_assigned', 'Event checklist', $msg, $uid, $link);
            } catch (\Throwable $e) {
                error_log('Checklist notification failed: ' . $e->getMessage());
            }
            $this->sendAssignEmail($uid, $organizationId, $title, $link, $msg);
        }
    }

    private function sendAssignEmail(int $userId, int $organizationId, string $eventTitle, string $link, string $message): void
    {
        try {
            $user = $this->db->queryOne('SELECT email, first_name FROM users WHERE id = :id', ['id' => $userId]);
            if (!$user || empty($user['email'])) {
                return;
            }
            $config = require (defined('CONFIG_PATH') ? CONFIG_PATH : dirname(__DIR__, 2) . '/config') . '/config.php';
            $org = $this->db->queryOne('SELECT name, logo_url FROM organizations WHERE id = :id', ['id' => $organizationId]);
            $emailCfg = $config['email'] ?? [];
            if (empty($emailCfg['api_key'])) {
                $orgEmail = $this->db->queryOne(
                    'SELECT smtp_api_key, smtp_from_email, smtp_from_name FROM organizations WHERE id = :id',
                    ['id' => $organizationId]
                );
                if ($orgEmail && !empty($orgEmail['smtp_api_key'])) {
                    $emailCfg['api_key'] = $orgEmail['smtp_api_key'];
                    $emailCfg['from_email'] = $orgEmail['smtp_from_email'] ?? $emailCfg['from_email'] ?? '';
                    $emailCfg['from_name'] = $orgEmail['smtp_from_name'] ?? $org['name'] ?? '';
                }
            }
            $svc = new EmailService($emailCfg);
            $body = '<p>Hi ' . htmlspecialchars($user['first_name'] ?? '') . ',</p>'
                . '<p>' . htmlspecialchars($message) . '</p>'
                . '<p><strong>Event:</strong> ' . htmlspecialchars($eventTitle) . '</p>'
                . '<p><a href="' . htmlspecialchars($link) . '">View your tasks</a></p>';
            $svc->sendEmail(
                $user['email'],
                'Event checklist: ' . $eventTitle,
                $body,
                $organizationId,
                ['template' => 'checklist_assigned', 'user_id' => $userId, 'org_name' => $org['name'] ?? '']
            );
        } catch (\Throwable $e) {
            error_log('Checklist email failed: ' . $e->getMessage());
        }
    }
}
