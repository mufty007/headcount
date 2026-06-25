<?php

namespace Headcount\Services;

use Headcount\Core\FileUpload;
use Headcount\Helpers\Database;

/**
 * Programs: CRUD, sessions, registration (free path), attendance, coupons validation.
 */
class ProgramService
{
    private $db;

    /** @var bool|null */
    private $programsPrayerColumns;

    /** @var bool|null */
    private $programsWeekColumns;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function programsTableHasPrayerColumns(): bool
    {
        if ($this->programsPrayerColumns !== null) {
            return $this->programsPrayerColumns;
        }
        try {
            $n = $this->db->queryOne(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs'
                 AND COLUMN_NAME IN ('prayer_name','prayer_offset')"
            );
            $this->programsPrayerColumns = isset($n['c']) && (int) $n['c'] >= 2;
        } catch (\Throwable $e) {
            $this->programsPrayerColumns = false;
        }
        return $this->programsPrayerColumns;
    }

    private function programsTableHasWeekColumns(): bool
    {
        if ($this->programsWeekColumns !== null) {
            return $this->programsWeekColumns;
        }
        try {
            $this->programsWeekColumns = $this->tableExists('program_weeks')
                && $this->db->hasColumn('programs', 'registration_mode');
        } catch (\Throwable $e) {
            $this->programsWeekColumns = false;
        }
        return $this->programsWeekColumns;
    }

    public function usesSelectWeeksMode(array $program): bool
    {
        return $this->programsTableHasWeekColumns()
            && (string) ($program['registration_mode'] ?? 'whole_program') === 'select_weeks';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listWeeks(int $programId): array
    {
        if (!$this->programsTableHasWeekColumns() || $programId <= 0) {
            return [];
        }
        return $this->db->query(
            'SELECT * FROM program_weeks WHERE program_id = :pid ORDER BY sort_order ASC, id ASC',
            ['pid' => $programId]
        ) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listWeeksWithSessions(int $programId): array
    {
        $weeks = $this->listWeeks($programId);
        if ($weeks === []) {
            return [];
        }
        $sessions = $this->db->query(
            'SELECT id, week_id, session_date, start_time, end_time, break_start_time, break_end_time, status
             FROM program_sessions WHERE program_id = :pid ORDER BY session_date ASC',
            ['pid' => $programId]
        ) ?: [];
        $byWeek = [];
        foreach ($sessions as $s) {
            $wid = (int) ($s['week_id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            if (!isset($byWeek[$wid])) {
                $byWeek[$wid] = [];
            }
            $byWeek[$wid][] = $s;
        }
        foreach ($weeks as &$w) {
            $wid = (int) ($w['id'] ?? 0);
            $dates = [];
            if (!empty($w['session_dates'])) {
                $dec = json_decode((string) $w['session_dates'], true);
                if (is_array($dec)) {
                    $dates = array_values(array_filter(array_map('strval', $dec)));
                }
            }
            $w['session_dates'] = $dates;
            $w['sessions'] = $byWeek[$wid] ?? [];
        }
        unset($w);
        return $weeks;
    }

    /**
     * @param list<array<string,mixed>> $weeksInput
     */
    public function saveWeeksFromAdmin(int $programId, int $organizationId, array $weeksInput): array
    {
        if (!$this->programsTableHasWeekColumns()) {
            return ['success' => true];
        }
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return ['success' => false, 'message' => 'Program not found'];
        }
        $existing = $this->listWeeks($programId);
        $keepIds = [];
        $sort = 0;
        foreach ($weeksInput as $w) {
            if (!is_array($w)) {
                continue;
            }
            $title = trim((string) ($w['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $sort++;
            $dates = isset($w['session_dates']) && is_array($w['session_dates']) ? $w['session_dates'] : [];
            $cleanDates = [];
            foreach ($dates as $d) {
                $d = trim((string) $d);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $cleanDates[] = $d;
                }
            }
            $cleanDates = array_values(array_unique($cleanDates));
            sort($cleanDates);
            $row = [
                'program_id' => $programId,
                'title' => substr($title, 0, 255),
                'description' => isset($w['description']) ? (string) $w['description'] : null,
                'sort_order' => (int) ($w['sort_order'] ?? $sort),
                'price_amount' => isset($w['price_amount']) ? round((float) $w['price_amount'], 2) : 0.0,
                'capacity' => isset($w['capacity']) && $w['capacity'] !== '' ? (int) $w['capacity'] : null,
                'session_dates' => $cleanDates !== [] ? json_encode($cleanDates) : null,
            ];
            $wid = (int) ($w['id'] ?? 0);
            if ($wid > 0) {
                $found = false;
                foreach ($existing as $ex) {
                    if ((int) ($ex['id'] ?? 0) === $wid) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    $this->db->update('program_weeks', $wid, $row);
                    $keepIds[] = $wid;
                    continue;
                }
            }
            $newId = (int) $this->db->insert('program_weeks', $row);
            if ($newId > 0) {
                $keepIds[] = $newId;
            }
        }
        foreach ($existing as $ex) {
            $eid = (int) ($ex['id'] ?? 0);
            if ($eid > 0 && !in_array($eid, $keepIds, true)) {
                $this->db->execute('UPDATE program_sessions SET week_id = NULL WHERE week_id = :wid', ['wid' => $eid]);
                $this->db->delete('program_weeks', $eid, 'id', false);
            }
        }
        $this->syncWeekSessions($programId, $organizationId);
        return ['success' => true];
    }

    /**
     * Create/update program_sessions from week session_dates.
     */
    public function syncWeekSessions(int $programId, int $organizationId): array
    {
        if (!$this->programsTableHasWeekColumns()) {
            return ['success' => true, 'created' => 0];
        }
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return ['success' => false, 'message' => 'Not found'];
        }
        $weeks = $this->listWeeks($programId);
        if ($weeks === []) {
            return ['success' => true, 'created' => 0];
        }
        $orgLoc = $this->db->queryOne('SELECT * FROM organizations WHERE id = ?', [$organizationId]);
        $city = trim((string) (($orgLoc['city'] ?? '') ?: ''));
        $country = trim((string) (($orgLoc['country'] ?? '') ?: ''));
        $created = 0;
        foreach ($weeks as $w) {
            $wid = (int) ($w['id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            $dates = [];
            if (!empty($w['session_dates'])) {
                $dec = json_decode((string) $w['session_dates'], true);
                if (is_array($dec)) {
                    $dates = $dec;
                }
            }
            foreach ($dates as $dateStr) {
                $dateStr = trim((string) $dateStr);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                    continue;
                }
                $times = $this->computeSessionTimesForDate($p, $dateStr, $city, $country);
                $existing = $this->db->queryOne(
                    'SELECT id FROM program_sessions WHERE program_id = :pid AND session_date = :d',
                    ['pid' => $programId, 'd' => $dateStr]
                );
                $sessRow = [
                    'week_id' => $wid,
                    'start_time' => $times['start_time'],
                    'end_time' => $times['end_time'],
                    'break_start_time' => $times['break_start_time'],
                    'break_end_time' => $times['break_end_time'],
                    'status' => 'scheduled',
                ];
                if ($existing) {
                    $this->db->update('program_sessions', (int) $existing['id'], $sessRow);
                } else {
                    $sessRow['program_id'] = $programId;
                    $sessRow['session_date'] = $dateStr;
                    $sessRow['generated'] = 0;
                    if ($this->db->insertIgnore('program_sessions', $sessRow)) {
                        $created++;
                    }
                }
            }
        }
        return ['success' => true, 'created' => $created];
    }

    /**
     * @return array{start_time:?string,end_time:?string,break_start_time:?string,break_end_time:?string}
     */
    private function computeSessionTimesForDate(array $p, string $dateStr, string $city, string $country): array
    {
        $normalizeTime = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            $s = trim((string) $v);
            if ($s === '') {
                return null;
            }
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
                return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : 0);
            }
            return $s;
        };

        $startTime = $normalizeTime($p['session_start_time'] ?? null);
        $usePrayerStart = $this->programsTableHasPrayerColumns()
            && !empty($p['prayer_name'])
            && $city !== ''
            && $country !== '';
        if ($usePrayerStart) {
            $computed = PrayerTimesService::timeAfterPrayer(
                $dateStr,
                $city,
                $country,
                (string) $p['prayer_name'],
                (int) ($p['prayer_offset'] ?? 0)
            );
            if ($computed !== null) {
                $startTime = $computed;
            }
        }

        $endTime = $normalizeTime($p['session_end_time'] ?? null);
        if ($this->programsTableHasWeekColumns()
            && (string) ($p['session_end_time_mode'] ?? 'clock') === 'prayer'
            && !empty($p['session_end_prayer_name'])
            && $city !== ''
            && $country !== '') {
            $computedEnd = PrayerTimesService::timeAfterPrayer(
                $dateStr,
                $city,
                $country,
                (string) $p['session_end_prayer_name'],
                (int) ($p['session_end_prayer_offset'] ?? 0)
            );
            if ($computedEnd !== null) {
                $endTime = $computedEnd;
            }
        }

        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_start_time' => $normalizeTime($p['break_start_time'] ?? null),
            'break_end_time' => $normalizeTime($p['break_end_time'] ?? null),
        ];
    }

    /**
     * @param list<int> $weekIds
     * @return array{success:bool,message?:string,week_ids?:list<int>}
     */
    public function validateWeekSelection(array $program, array $weekIds): array
    {
        if (!$this->usesSelectWeeksMode($program)) {
            return ['success' => true, 'week_ids' => []];
        }
        $pid = (int) ($program['id'] ?? 0);
        $allWeeks = $this->listWeeks($pid);
        if ($allWeeks === []) {
            return ['success' => false, 'message' => 'No enrollment weeks configured'];
        }
        $allowed = [];
        foreach ($allWeeks as $w) {
            $allowed[(int) ($w['id'] ?? 0)] = $w;
        }
        unset($allowed[0]);
        $selected = [];
        foreach ($weekIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($allowed[$id])) {
                $selected[$id] = true;
            }
        }
        $selectedIds = array_keys($selected);
        if ($selectedIds === []) {
            return ['success' => false, 'message' => 'Select at least one week'];
        }
        foreach ($selectedIds as $wid) {
            $cap = $allowed[$wid]['capacity'] ?? null;
            if ($cap !== null && $cap !== '') {
                $n = $this->countActiveRegistrationsForWeek($wid);
                if ($n >= (int) $cap) {
                    return ['success' => false, 'message' => 'One or more selected weeks is full'];
                }
            }
        }
        return ['success' => true, 'week_ids' => $selectedIds];
    }

    public function countActiveRegistrationsForWeek(int $weekId): int
    {
        if (!$this->programsTableHasWeekColumns() || $weekId <= 0) {
            return 0;
        }
        $r = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM program_registration_weeks prw
             INNER JOIN program_registrations r ON r.id = prw.registration_id
             WHERE prw.week_id = :wid AND r.status IN ('active','pending')",
            ['wid' => $weekId]
        );
        return (int) ($r['c'] ?? 0);
    }

    /**
     * @param list<int> $weekIds
     */
    public function saveRegistrationWeeks(int $registrationId, array $weekIds): void
    {
        if (!$this->programsTableHasWeekColumns() || $registrationId <= 0) {
            return;
        }
        $this->db->execute('DELETE FROM program_registration_weeks WHERE registration_id = :rid', ['rid' => $registrationId]);
        foreach ($weekIds as $wid) {
            $wid = (int) $wid;
            if ($wid <= 0) {
                continue;
            }
            $this->db->insertIgnore('program_registration_weeks', [
                'registration_id' => $registrationId,
                'week_id' => $wid,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    public function getEnrolledWeekIds(int $registrationId): array
    {
        if (!$this->programsTableHasWeekColumns() || $registrationId <= 0) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT week_id FROM program_registration_weeks WHERE registration_id = :rid',
            ['rid' => $registrationId]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) ($r['week_id'] ?? 0);
        }
        return array_values(array_filter($out, static fn ($id) => $id > 0));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getRegistrationWeeksDetail(int $registrationId): array
    {
        if (!$this->programsTableHasWeekColumns() || $registrationId <= 0) {
            return [];
        }
        return $this->db->query(
            'SELECT w.* FROM program_registration_weeks prw
             INNER JOIN program_weeks w ON w.id = prw.week_id
             WHERE prw.registration_id = :rid
             ORDER BY w.sort_order ASC, w.id ASC',
            ['rid' => $registrationId]
        ) ?: [];
    }

    public function userHasSessionAccess(int $programId, int $userId, int $sessionId): bool
    {
        $p = $this->db->queryOne('SELECT * FROM programs WHERE id = :id', ['id' => $programId]);
        if (!$p) {
            return false;
        }
        if (!$this->usesSelectWeeksMode($p)) {
            $reg = $this->getRegistration($programId, $userId);
            return $reg && ($reg['status'] ?? '') === 'active';
        }
        $reg = $this->getRegistration($programId, $userId);
        if (!$reg || ($reg['status'] ?? '') !== 'active') {
            return false;
        }
        $sess = $this->db->queryOne(
            'SELECT week_id FROM program_sessions WHERE id = :id AND program_id = :pid',
            ['id' => $sessionId, 'pid' => $programId]
        );
        if (!$sess) {
            return false;
        }
        $weekId = (int) ($sess['week_id'] ?? 0);
        if ($weekId <= 0) {
            return true;
        }
        $enrolled = $this->getEnrolledWeekIds((int) $reg['id']);
        return in_array($weekId, $enrolled, true);
    }

    public function tableExists($name)
    {
        return $this->db->tableExists((string) $name);
    }

    public function getByIdForOrg($programId, $organizationId)
    {
        return $this->db->queryOne(
            "SELECT p.*, pc.name AS category_name, pc.slug AS category_slug
             FROM programs p
             LEFT JOIN program_categories pc ON p.category_id = pc.id
             WHERE p.id = :id AND p.organization_id = :org",
            ['id' => $programId, 'org' => $organizationId]
        );
    }

    public function listForOrg($organizationId, $filters = [])
    {
        $sql = "SELECT p.*, pc.name AS category_name
                FROM programs p
                LEFT JOIN program_categories pc ON p.category_id = pc.id
                WHERE p.organization_id = :org";
        $params = ['org' => $organizationId];
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $sql .= " AND p.status = :st";
            $params['st'] = $filters['status'];
        } else {
            $sql .= " AND p.status != 'archived'";
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (p.title LIKE :q OR p.description LIKE :q2)";
            $q = '%' . $filters['search'] . '%';
            $params['q'] = $q;
            $params['q2'] = $q;
        }
        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = :cid";
            $params['cid'] = (int) $filters['category_id'];
        }
        $sql .= " ORDER BY p.updated_at DESC";
        return $this->db->query($sql, $params);
    }

    /**
     * Published programs for portal (member org).
     */
    public function listPublishedForMemberOrg($organizationId, $filters = [])
    {
        $sql = "SELECT p.*, pc.name AS category_name
                FROM programs p
                LEFT JOIN program_categories pc ON p.category_id = pc.id
                WHERE p.organization_id = :org AND p.status = 'published'";
        $params = ['org' => $organizationId];
        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = :cid";
            $params['cid'] = (int) $filters['category_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (p.title LIKE :q OR p.description LIKE :q2)";
            $q = '%' . $filters['search'] . '%';
            $params['q'] = $q;
            $params['q2'] = $q;
        }
        $sql .= " ORDER BY p.title ASC";
        return $this->db->query($sql, $params);
    }

    public function saveProgram($organizationId, $userId, array $data, $programId = null)
    {
        $bannerImage = null;
        if (array_key_exists('banner_image', $data)) {
            $bannerImage = $data['banner_image'];
            if ($bannerImage !== null && $bannerImage !== '') {
                $bannerImage = trim((string) $bannerImage);
            }
            if ($bannerImage === '' || $bannerImage === null) {
                $bannerImage = null;
            }
        } elseif ($programId) {
            $exBanner = $this->getByIdForOrg($programId, $organizationId);
            $bannerImage = $exBanner['banner_image'] ?? null;
        }

        $normalizeTime = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            $s = trim((string) $v);
            if ($s === '') {
                return null;
            }
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
                return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : 0);
            }
            return $s;
        };

        $normalizeDate = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            $s = trim((string) $v);
            if ($s === '') {
                return null;
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
                return $m[1];
            }
            return $s;
        };

        $hasPrayerCols = $this->programsTableHasPrayerColumns();
        $pnForRow = $hasPrayerCols && isset($data['prayer_name']) ? trim((string) $data['prayer_name']) : '';
        $usePrayerSchedule = $hasPrayerCols && $pnForRow !== '';

        $row = [
            'organization_id' => $organizationId,
            'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'title' => trim($data['title'] ?? ''),
            'description' => $data['description'] ?? null,
            'banner_image' => $bannerImage,
            'status' => $data['status'] ?? 'draft',
            'show_on_public_site' => !empty($data['show_on_public_site']) ? 1 : 0,
            'location' => $data['location'] ?? null,
            'is_virtual' => !empty($data['is_virtual']) ? 1 : 0,
            'capacity' => isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
            'pricing_type' => $data['pricing_type'] ?? 'free',
            'price_amount' => isset($data['price_amount']) ? (float) $data['price_amount'] : null,
            'billing_interval' => $data['billing_interval'] ?? 'once',
            'recurrence_type' => $data['recurrence_type'] ?? 'weekly',
            // Prayer-based schedules: start time is computed per session; do not keep a misleading clock value
            'session_start_time' => $usePrayerSchedule ? null : $normalizeTime($data['session_start_time'] ?? null),
            'session_end_time' => $normalizeTime($data['session_end_time'] ?? null),
            'session_days_of_week' => isset($data['session_days_of_week']) && is_array($data['session_days_of_week'])
                ? json_encode(array_map('intval', $data['session_days_of_week']))
                : ($data['session_days_of_week'] ?? null),
            'starts_on' => $normalizeDate($data['starts_on'] ?? null),
            'ends_on' => $normalizeDate($data['ends_on'] ?? null),
            'enrollment_starts_at' => $data['enrollment_starts_at'] ?? null,
            'enrollment_ends_at' => $data['enrollment_ends_at'] ?? null,
        ];

        if ($hasPrayerCols) {
            $row['prayer_name'] = $usePrayerSchedule ? $pnForRow : null;
            $row['prayer_offset'] = $usePrayerSchedule && isset($data['prayer_offset']) ? (int) $data['prayer_offset'] : 0;
        }

        if ($this->programsTableHasWeekColumns()) {
            $regMode = (string) ($data['registration_mode'] ?? 'whole_program');
            if (!in_array($regMode, ['whole_program', 'select_weeks'], true)) {
                $regMode = 'whole_program';
            }
            $row['registration_mode'] = $regMode;
            $row['bundle_all_weeks_price'] = isset($data['bundle_all_weeks_price']) && $data['bundle_all_weeks_price'] !== ''
                ? round((float) $data['bundle_all_weeks_price'], 2)
                : null;
            $row['allow_guest_registration'] = !empty($data['allow_guest_registration']) ? 1 : 0;
            $endMode = (string) ($data['session_end_time_mode'] ?? 'clock');
            if (!in_array($endMode, ['clock', 'prayer'], true)) {
                $endMode = 'clock';
            }
            $row['session_end_time_mode'] = $endMode;
            $endPrayer = $endMode === 'prayer' && isset($data['session_end_prayer_name'])
                ? trim((string) $data['session_end_prayer_name'])
                : '';
            $row['session_end_prayer_name'] = $endPrayer !== '' ? $endPrayer : null;
            $row['session_end_prayer_offset'] = $endMode === 'prayer' && isset($data['session_end_prayer_offset'])
                ? (int) $data['session_end_prayer_offset']
                : 0;
            $row['break_start_time'] = $normalizeTime($data['break_start_time'] ?? null);
            $row['break_end_time'] = $normalizeTime($data['break_end_time'] ?? null);
            if ($endMode === 'prayer') {
                $row['session_end_time'] = null;
            }
        }

        if ($row['title'] === '') {
            return ['success' => false, 'message' => 'Title is required'];
        }

        if ($row['starts_on'] !== null && $row['ends_on'] !== null && $row['ends_on'] < $row['starts_on']) {
            return ['success' => false, 'message' => '"Ends On" must be on or after "Starts On".'];
        }

        if ($programId) {
            $existing = $this->getByIdForOrg($programId, $organizationId);
            if (!$existing) {
                return ['success' => false, 'message' => 'Program not found'];
            }
            unset($row['organization_id']);
            $row['created_by'] = $existing['created_by'];
            $this->db->update('programs', $programId, $row);
            $id = $programId;
        } else {
            $row['created_by'] = $userId;
            $id = (int) $this->db->insert('programs', $row);
        }

        return ['success' => true, 'id' => $id];
    }

    public function deleteProgram($programId, $organizationId)
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return ['success' => false, 'message' => 'Not found'];
        }
        $this->db->update('programs', $programId, ['status' => 'archived']);
        return ['success' => true];
    }

    public function getQuestions($programId)
    {
        $rows = $this->db->query(
            "SELECT * FROM program_questions WHERE program_id = :pid ORDER BY sort_order ASC, id ASC",
            ['pid' => $programId]
        );
        if (!$this->tableExists('program_question_options')) {
            foreach ($rows as &$r) {
                $r['options'] = [];
            }
            unset($r);
            return $rows;
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
        }
        if (empty($ids)) {
            return $rows;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $opts = $this->db->query(
            "SELECT * FROM program_question_options WHERE question_id IN ($placeholders) ORDER BY sort_order ASC, id ASC",
            $ids
        );
        $byQ = [];
        foreach ($opts as $o) {
            $qid = (int) $o['question_id'];
            if (!isset($byQ[$qid])) {
                $byQ[$qid] = [];
            }
            $byQ[$qid][] = $o;
        }
        foreach ($rows as &$r) {
            $r['options'] = $byQ[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    public function saveQuestions($programId, $organizationId, array $questions)
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return ['success' => false, 'message' => 'Program not found'];
        }

        $existingRows = $this->db->query(
            'SELECT id FROM program_questions WHERE program_id = :pid',
            ['pid' => $programId]
        );
        $existingIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $existingRows ?: []);
        $keptIds = [];
        $sort = 0;
        $allowedTypes = ['text', 'short_text', 'checkbox', 'number', 'radio', 'dropdown', 'multi_checkbox'];
        $hasOptionsTable = $this->tableExists('program_question_options');

        foreach ($questions as $q) {
            $sort++;
            $qt = $q['question_type'] ?? 'short_text';
            if (!in_array($qt, $allowedTypes, true)) {
                $qt = 'short_text';
            }
            $text = trim($q['question_text'] ?? '');
            if ($text === '') {
                continue;
            }
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
            if (in_array($qt, ['radio', 'dropdown', 'multi_checkbox'], true)) {
                $labels = [];
                foreach ($options as $opt) {
                    $label = isset($opt['option_label']) ? trim((string) $opt['option_label']) : (is_string($opt) ? trim($opt) : '');
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
                if ($labels === []) {
                    continue;
                }
            }

            $incomingId = isset($q['id']) ? (int) $q['id'] : 0;
            $rowData = [
                'question_text' => $text,
                'question_type' => $qt,
                'is_required' => !empty($q['is_required']) ? 1 : 0,
                'sort_order' => (int) ($q['sort_order'] ?? $sort),
            ];

            if ($incomingId > 0 && in_array($incomingId, $existingIds, true)) {
                $this->db->update('program_questions', $incomingId, $rowData);
                $questionId = $incomingId;
            } else {
                $questionId = (int) $this->db->insert('program_questions', array_merge($rowData, [
                    'program_id' => $programId,
                ]));
            }

            if ($questionId <= 0) {
                continue;
            }
            $keptIds[] = $questionId;
            $this->syncProgramQuestionOptions($questionId, $options, $hasOptionsTable, $qt);
        }

        foreach (array_diff($existingIds, $keptIds) as $removeId) {
            $this->db->delete('program_questions', (int) $removeId, 'id', false);
        }

        return ['success' => true];
    }

    /**
     * Replace option rows for a question (answers reference question_id, not option id).
     *
     * @param array<int, array<string, mixed>|string> $options
     */
    private function syncProgramQuestionOptions(int $questionId, array $options, bool $hasOptionsTable, string $questionType): void
    {
        if (!$hasOptionsTable) {
            return;
        }
        $this->db->execute('DELETE FROM program_question_options WHERE question_id = :qid', ['qid' => $questionId]);
        if (!in_array($questionType, ['radio', 'dropdown', 'multi_checkbox'], true) || $options === []) {
            return;
        }
        foreach ($options as $oi => $opt) {
            $label = isset($opt['option_label']) ? trim((string) $opt['option_label']) : (is_string($opt) ? trim($opt) : '');
            if ($label === '') {
                continue;
            }
            $this->db->insert('program_question_options', [
                'question_id' => $questionId,
                'option_label' => substr($label, 0, 255),
                'sort_order' => is_numeric($oi) ? (int) $oi : 0,
            ]);
        }
    }

    public function listCategories($organizationId)
    {
        return $this->db->query(
            "SELECT * FROM program_categories WHERE organization_id = :org ORDER BY sort_order ASC, name ASC",
            ['org' => $organizationId]
        );
    }

    public function saveCategory($organizationId, $data, $id = null)
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'message' => 'Name required'];
        }
        $slug = trim($data['slug'] ?? '');
        if ($slug === '') {
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
            $slug = trim($slug, '-');
        }
        if ($id) {
            $row = $this->db->queryOne(
                "SELECT id FROM program_categories WHERE id = :id AND organization_id = :org",
                ['id' => $id, 'org' => $organizationId]
            );
            if (!$row) {
                return ['success' => false, 'message' => 'Category not found'];
            }
            $this->db->update('program_categories', $id, [
                'name' => $name,
                'slug' => $slug,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        } else {
            $id = (int) $this->db->insert('program_categories', [
                'organization_id' => $organizationId,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        }
        return ['success' => true, 'id' => $id];
    }

    /**
     * Delete a program category if no programs use it for this organization.
     */
    public function deleteCategory($organizationId, $categoryId)
    {
        $categoryId = (int) $categoryId;
        if ($categoryId <= 0) {
            return ['success' => false, 'message' => 'Invalid category'];
        }
        $row = $this->db->queryOne(
            "SELECT id FROM program_categories WHERE id = :id AND organization_id = :org",
            ['id' => $categoryId, 'org' => $organizationId]
        );
        if (!$row) {
            return ['success' => false, 'message' => 'Category not found'];
        }
        $n = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM programs WHERE category_id = :cid AND organization_id = :org",
            ['cid' => $categoryId, 'org' => $organizationId]
        );
        if ((int) ($n['c'] ?? 0) > 0) {
            return ['success' => false, 'message' => 'This category is assigned to one or more programs. Change those programs first, then try again.'];
        }
        $this->db->delete('program_categories', $categoryId, 'id', false);
        return ['success' => true];
    }

    public function getRegistration($programId, $userId)
    {
        return $this->db->queryOne(
            "SELECT * FROM program_registrations WHERE program_id = :p AND user_id = :u",
            ['p' => $programId, 'u' => $userId]
        );
    }

    public function countActiveRegistrations($programId)
    {
        $r = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM program_registrations WHERE program_id = :p AND status IN ('active','pending')",
            ['p' => $programId]
        );
        return (int) ($r['c'] ?? 0);
    }

    /**
     * Free registration: create active registration and store answers.
     *
     * @param list<int> $weekIds Required when program uses select_weeks mode
     */
    public function registerFree($programId, $userId, array $answers = [], array $weekIds = [])
    {
        $p = $this->db->queryOne("SELECT * FROM programs WHERE id = :id AND status = 'published'", ['id' => $programId]);
        if (!$p) {
            return ['success' => false, 'message' => 'Program not available'];
        }
        if (($p['pricing_type'] ?? '') !== 'free') {
            return ['success' => false, 'message' => 'This program requires payment'];
        }
        $weekValidation = $this->validateWeekSelection($p, $weekIds);
        if (!$weekValidation['success']) {
            return ['success' => false, 'message' => $weekValidation['message'] ?? 'Invalid week selection'];
        }
        $validatedWeekIds = $weekValidation['week_ids'] ?? [];
        $now = date('Y-m-d H:i:s');
        if (!empty($p['enrollment_starts_at']) && $now < $p['enrollment_starts_at']) {
            return ['success' => false, 'message' => 'Enrollment has not opened yet'];
        }
        if (!empty($p['enrollment_ends_at']) && $now > $p['enrollment_ends_at']) {
            return ['success' => false, 'message' => 'Enrollment is closed'];
        }
        $cap = $p['capacity'] ?? null;
        if ($cap !== null && $cap !== '') {
            $n = $this->countActiveRegistrations($programId);
            if ($n >= (int) $cap) {
                return ['success' => false, 'message' => 'Program is full'];
            }
        }
        $existing = $this->getRegistration($programId, $userId);
        if ($existing && in_array($existing['status'], ['active', 'pending'], true)) {
            return ['success' => false, 'message' => 'Already registered'];
        }
        $answerCheck = $this->validateRegistrationAnswers((int) $programId, $answers);
        if (empty($answerCheck['success'])) {
            return $answerCheck;
        }
        if ($existing && $existing['status'] === 'cancelled') {
            $this->db->update('program_registrations', $existing['id'], [
                'status' => 'active',
                'joined_at' => date('Y-m-d H:i:s'),
                'cancelled_at' => null,
            ]);
            $regId = (int) $existing['id'];
            if ($validatedWeekIds !== []) {
                $this->saveRegistrationWeeks($regId, $validatedWeekIds);
            }
        } else {
            $regId = (int) $this->db->insert('program_registrations', [
                'program_id' => $programId,
                'user_id' => $userId,
                'status' => 'active',
                'joined_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if ($validatedWeekIds !== []) {
            $this->saveRegistrationWeeks($regId, $validatedWeekIds);
        }
        $this->saveAnswers($regId, $programId, $answers);
        return ['success' => true, 'registration_id' => $regId];
    }

    /**
     * Validate required registration questions server-side.
     *
     * @return array{success:bool,message?:string}
     */
    public function validateRegistrationAnswers(int $programId, array $answers): array
    {
        foreach ($this->getQuestions($programId) as $q) {
            if (empty($q['is_required'])) {
                continue;
            }
            $qid = (int) ($q['id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $qt = (string) ($q['question_type'] ?? 'short_text');
            $val = $answers[$qid] ?? $answers[(string) $qid] ?? null;
            if ($qt === 'multi_checkbox') {
                if (!is_array($val) || $val === []) {
                    return ['success' => false, 'message' => 'Please answer all required questions.'];
                }
                continue;
            }
            if ($qt === 'checkbox') {
                if ($val !== '1' && $val !== 1 && $val !== true && $val !== 'yes') {
                    return ['success' => false, 'message' => 'Please answer all required questions.'];
                }
                continue;
            }
            if ($val === null || trim((string) $val) === '') {
                return ['success' => false, 'message' => 'Please answer all required questions.'];
            }
        }
        return ['success' => true];
    }

    /**
     * Human-readable answer for admin display / CSV export.
     */
    public static function formatRegistrationAnswerDisplay(?string $answerText, ?string $questionType = null): string
    {
        $text = trim((string) $answerText);
        if ($text === '') {
            return '';
        }
        if (($questionType ?? '') === 'multi_checkbox' || ($text[0] ?? '') === '[') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return implode(', ', array_map('strval', $decoded));
            }
        }
        if (($questionType ?? '') === 'checkbox') {
            return in_array(strtolower($text), ['1', 'yes', 'true'], true) ? 'Yes' : $text;
        }
        return $text;
    }

    public function saveAnswers($registrationId, $programId, array $answers)
    {
        $questions = $this->getQuestions($programId);
        $qById = [];
        foreach ($questions as $q) {
            $qById[(int) $q['id']] = $q;
        }
        foreach ($answers as $qid => $val) {
            $qid = (int) $qid;
            if (!isset($qById[$qid])) {
                continue;
            }
            $q = $qById[$qid];
            $qt = $q['question_type'] ?? 'short_text';
            $opts = [];
            if (!empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $o) {
                    $opts[] = isset($o['option_label']) ? (string) $o['option_label'] : '';
                }
            }
            if (in_array($qt, ['radio', 'dropdown'], true)) {
                $s = is_array($val) ? '' : trim((string) $val);
                if ($s !== '' && !empty($opts) && !in_array($s, $opts, true)) {
                    continue;
                }
            } elseif ($qt === 'multi_checkbox') {
                if (is_array($val)) {
                    $clean = [];
                    foreach ($val as $one) {
                        $one = (string) $one;
                        if ($one !== '' && (empty($opts) || in_array($one, $opts, true))) {
                            $clean[] = $one;
                        }
                    }
                    $val = $clean;
                } else {
                    $val = [];
                }
            }
            $text = is_array($val) ? json_encode($val) : (string) $val;
            $existing = $this->db->queryOne(
                "SELECT id FROM program_registration_answers WHERE registration_id = :r AND question_id = :q",
                ['r' => $registrationId, 'q' => $qid]
            );
            if ($existing) {
                $this->db->update('program_registration_answers', $existing['id'], ['answer_text' => $text]);
            } else {
                $this->db->insert('program_registration_answers', [
                    'registration_id' => $registrationId,
                    'question_id' => $qid,
                    'answer_text' => $text,
                ]);
            }
        }
    }

    /**
     * Create pending registration row before Stripe checkout.
     *
     * @param list<int> $weekIds Required when program uses select_weeks mode
     */
    public function createPendingRegistration($programId, $userId, array $answers = [], $couponCode = null, array $weekIds = [])
    {
        $p = $this->db->queryOne("SELECT * FROM programs WHERE id = :id AND status = 'published'", ['id' => $programId]);
        if (!$p) {
            return ['success' => false, 'message' => 'Program not available'];
        }
        $weekValidation = $this->validateWeekSelection($p, $weekIds);
        if (!$weekValidation['success']) {
            return ['success' => false, 'message' => $weekValidation['message'] ?? 'Invalid week selection'];
        }
        $validatedWeekIds = $weekValidation['week_ids'] ?? [];
        $now = date('Y-m-d H:i:s');
        if (!empty($p['enrollment_starts_at']) && $now < $p['enrollment_starts_at']) {
            return ['success' => false, 'message' => 'Enrollment has not opened yet'];
        }
        if (!empty($p['enrollment_ends_at']) && $now > $p['enrollment_ends_at']) {
            return ['success' => false, 'message' => 'Enrollment is closed'];
        }
        $cap = $p['capacity'] ?? null;
        if ($cap !== null && $cap !== '') {
            $n = $this->countActiveRegistrations($programId);
            if ($n >= (int) $cap) {
                return ['success' => false, 'message' => 'Program is full'];
            }
        }
        $existing = $this->getRegistration($programId, $userId);
        if ($existing && in_array($existing['status'], ['active', 'pending'], true)) {
            if ($existing['status'] === 'pending') {
                $answerCheck = $this->validateRegistrationAnswers((int) $programId, $answers);
                if (empty($answerCheck['success'])) {
                    return $answerCheck;
                }
                $this->saveAnswers((int) $existing['id'], $programId, $answers);
                if ($validatedWeekIds !== []) {
                    $this->saveRegistrationWeeks((int) $existing['id'], $validatedWeekIds);
                }
                return ['success' => true, 'registration_id' => (int) $existing['id'], 'existing' => true];
            }
            return ['success' => false, 'message' => 'Already registered'];
        }
        $answerCheck = $this->validateRegistrationAnswers((int) $programId, $answers);
        if (empty($answerCheck['success'])) {
            return $answerCheck;
        }
        $regId = (int) $this->db->insert('program_registrations', [
            'program_id' => $programId,
            'user_id' => $userId,
            'status' => 'pending',
            'coupon_code' => $couponCode ? strtoupper(trim($couponCode)) : null,
        ]);
        if ($validatedWeekIds !== []) {
            $this->saveRegistrationWeeks($regId, $validatedWeekIds);
        }
        $this->saveAnswers($regId, $programId, $answers);
        return ['success' => true, 'registration_id' => $regId];
    }

    public function validateCoupon($organizationId, $programId, $code)
    {
        $code = strtoupper(trim($code ?? ''));
        if ($code === '') {
            return ['valid' => false, 'message' => 'Empty code'];
        }
        $c = $this->db->queryOne(
            "SELECT * FROM program_coupons WHERE organization_id = :org AND UPPER(code) = :code AND active = 1",
            ['org' => $organizationId, 'code' => $code]
        );
        if (!$c) {
            return ['valid' => false, 'message' => 'Invalid code'];
        }
        if ($c['program_id'] !== null && (int) $c['program_id'] !== (int) $programId) {
            return ['valid' => false, 'message' => 'Code does not apply to this program'];
        }
        $today = date('Y-m-d');
        if (!empty($c['valid_from']) && $today < $c['valid_from']) {
            return ['valid' => false, 'message' => 'Code not yet valid'];
        }
        if (!empty($c['valid_until']) && $today > $c['valid_until']) {
            return ['valid' => false, 'message' => 'Code expired'];
        }
        if ($c['max_redemptions'] !== null && (int) $c['redemptions_count'] >= (int) $c['max_redemptions']) {
            return ['valid' => false, 'message' => 'Code fully redeemed'];
        }
        return ['valid' => true, 'coupon' => $c];
    }

    /**
     * Generate session rows from program recurrence (horizon months ahead).
     */
    public function generateSessions($programId, $organizationId, $horizonMonths = 6)
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return ['success' => false, 'message' => 'Not found'];
        }
        $type = $p['recurrence_type'] ?? 'weekly';
        if ($type === 'none') {
            return [
                'success' => true,
                'created' => 0,
                'message' => 'Session Recurrence is set to "None". Choose Weekly, Bi-weekly, or Monthly, save, then generate again.',
            ];
        }
        $usableDate = static function ($v): bool {
            if ($v === null) {
                return false;
            }
            $s = trim((string) $v);
            if ($s === '' || $s === '0000-00-00' || strncmp($s, '0000-00', 7) === 0) {
                return false;
            }
            return true;
        };

        if (!$usableDate($p['starts_on'] ?? null)) {
            return [
                'success' => true,
                'created' => 0,
                'message' => 'Set "Starts On" (Schedule step), save the program, then generate sessions.',
            ];
        }

        try {
            $start = new \DateTime($p['starts_on']);
        } catch (\Throwable $e) {
            return [
                'success' => true,
                'created' => 0,
                'message' => 'Set a valid "Starts On" date, save the program, then generate sessions.',
            ];
        }

        $horizonMonths = max(1, min(36, (int) $horizonMonths));

        $endCap = null;
        if ($usableDate($p['ends_on'] ?? null)) {
            try {
                $endCap = new \DateTime($p['ends_on']);
            } catch (\Throwable $e) {
                $endCap = null;
            }
        }
        if ($endCap !== null && $endCap < $start) {
            return [
                'success' => true,
                'created' => 0,
                'message' => '"Ends On" must be on or after "Starts On". Fix the dates, save, then generate again.',
            ];
        }

        $days = [];
        if (!empty($p['session_days_of_week'])) {
            $decoded = json_decode($p['session_days_of_week'], true);
            if (is_array($decoded)) {
                $days = array_map('intval', $decoded);
            }
        }
        if (empty($days)) {
            $fallbackW = strtotime($p['starts_on']);
            $days = [(int) ($fallbackW !== false ? date('w', $fallbackW) : date('w'))];
        }

        $end = (clone $start)->modify('+' . $horizonMonths . ' months');
        if ($endCap !== null && $endCap < $end) {
            $end = $endCap;
        }
        $interval = $type === 'weekly' ? '1 week' : ($type === 'biweekly' ? '2 weeks' : '1 month');
        $created = 0;
        $orgLoc = $this->db->queryOne('SELECT * FROM organizations WHERE id = ?', [$organizationId]);
        $city = trim((string) (($orgLoc['city'] ?? '') ?: ''));
        $country = trim((string) (($orgLoc['country'] ?? '') ?: ''));
        $usePrayer = $this->programsTableHasPrayerColumns()
            && !empty($p['prayer_name'])
            && $city !== ''
            && $country !== '';

        $d = clone $start;
        while ($d <= $end) {
            $w = (int) $d->format('w');
            if (in_array($w, $days, true)) {
                $dateStr = $d->format('Y-m-d');
                $startTime = $p['session_start_time'] ?? null;
                if ($usePrayer) {
                    $computed = PrayerTimesService::timeAfterPrayer(
                        $dateStr,
                        $city,
                        $country,
                        (string) $p['prayer_name'],
                        (int) ($p['prayer_offset'] ?? 0)
                    );
                    if ($computed !== null) {
                        $startTime = $computed;
                    }
                }
                $times = $this->computeSessionTimesForDate($p, $dateStr, $city, $country);
                if ($startTime !== null) {
                    $times['start_time'] = $startTime;
                }
                $inserted = $this->db->insertIgnore('program_sessions', [
                    'program_id' => $programId,
                    'session_date' => $dateStr,
                    'start_time' => $times['start_time'],
                    'end_time' => $times['end_time'],
                    'break_start_time' => $times['break_start_time'],
                    'break_end_time' => $times['break_end_time'],
                    'status' => 'scheduled',
                    'generated' => true,
                ]);
                if ($inserted) {
                    $created++;
                }
            }
            if ($type === 'monthly') {
                $d->modify('first day of next month');
            } else {
                $d->modify('+1 day');
            }
        }
        $this->db->update('programs', $programId, ['sessions_generated_until' => $end->format('Y-m-d')]);
        $out = ['success' => true, 'created' => $created];
        if ($created === 0) {
            $out['message'] = 'No new session rows were added. Either every date in range already has a session, or no day in the range matches the selected weekdays.';
        }
        return $out;
    }

    public function listSessions($programId, $organizationId, $from = null, $to = null)
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return [];
        }
        $sql = "SELECT * FROM program_sessions WHERE program_id = :pid";
        $params = ['pid' => $programId];
        if ($from) {
            $sql .= " AND session_date >= :from";
            $params['from'] = $from;
        }
        if ($to) {
            $sql .= " AND session_date <= :to";
            $params['to'] = $to;
        }
        $sql .= " ORDER BY session_date ASC, start_time ASC";
        return $this->db->query($sql, $params);
    }

    public function listStaff($programId)
    {
        return $this->db->query(
            "SELECT ps.*, u.first_name, u.last_name, u.email
             FROM program_staff ps
             JOIN users u ON u.id = ps.user_id
             WHERE ps.program_id = :pid",
            ['pid' => $programId]
        );
    }

    public function setStaff($programId, $organizationId, $userIds, $role = 'coordinator')
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return ['success' => false, 'message' => 'Not found'];
        }
        $this->db->execute('DELETE FROM program_staff WHERE program_id = :pid', ['pid' => $programId]);
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            $this->db->insert('program_staff', [
                'program_id' => $programId,
                'user_id' => $uid,
                'role' => $role,
            ]);
        }
        return ['success' => true];
    }

    public function userCanManageProgram($userId, $userRole, $programId, $organizationId)
    {
        if (in_array($userRole, ['admin', 'coordinator'], true)) {
            return $this->getByIdForOrg($programId, $organizationId) !== null;
        }
        $row = $this->db->queryOne(
            "SELECT 1 FROM program_staff WHERE program_id = :p AND user_id = :u",
            ['p' => $programId, 'u' => $userId]
        );
        return !empty($row);
    }

    /**
     * Session details plus active registrants and current attendance rows (for admin roster UI).
     *
     * @return array{session: array, registrants: array}|null
     */
    public function getSessionAttendanceRoster($sessionId, $organizationId)
    {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) {
            return null;
        }
        $sess = $this->db->queryOne(
            "SELECT s.*, p.title AS program_title, p.organization_id
             FROM program_sessions s
             INNER JOIN programs p ON p.id = s.program_id
             WHERE s.id = :id",
            ['id' => $sessionId]
        );
        if (!$sess || (int) $sess['organization_id'] !== (int) $organizationId) {
            return null;
        }
        $pid = (int) $sess['program_id'];
        $weekId = (int) ($sess['week_id'] ?? 0);
        $sql = "SELECT u.id AS user_id, u.first_name, u.last_name, u.email,
                    a.status AS attendance_status, a.recorded_at AS attendance_recorded_at
             FROM program_registrations r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN program_session_attendance a
               ON a.program_session_id = :sid AND a.user_id = u.id
             WHERE r.program_id = :pid AND r.status = 'active'";
        $params = ['sid' => $sessionId, 'pid' => $pid];
        if ($this->programsTableHasWeekColumns()) {
            $prog = $this->db->queryOne('SELECT registration_mode FROM programs WHERE id = :id', ['id' => $pid]);
            if ($prog && (string) ($prog['registration_mode'] ?? '') === 'select_weeks' && $weekId > 0) {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM program_registration_weeks prw
                    WHERE prw.registration_id = r.id AND prw.week_id = :week_id
                )";
                $params['week_id'] = $weekId;
            }
        }
        $sql .= " ORDER BY u.last_name ASC, u.first_name ASC";
        $registrants = $this->db->query($sql, $params);
        return [
            'session' => $sess,
            'registrants' => $registrants,
        ];
    }

    public function recordAttendance($sessionId, $memberUserId, $status, $recordedBy, $organizationId)
    {
        $sess = $this->db->queryOne(
            "SELECT s.*, p.organization_id FROM program_sessions s
             JOIN programs p ON p.id = s.program_id
             WHERE s.id = :id",
            ['id' => $sessionId]
        );
        if (!$sess || (int) $sess['organization_id'] !== (int) $organizationId) {
            return ['success' => false, 'message' => 'Session not found'];
        }
        if (!$this->userHasSessionAccess((int) $sess['program_id'], $memberUserId, $sessionId)) {
            return ['success' => false, 'message' => 'Member is not enrolled for this session week'];
        }
        $reg = $this->getRegistration((int) $sess['program_id'], $memberUserId);
        if (!$reg || $reg['status'] !== 'active') {
            return ['success' => false, 'message' => 'Member is not actively registered'];
        }
        $existing = $this->db->queryOne(
            "SELECT id FROM program_session_attendance WHERE program_session_id = :sid AND user_id = :uid",
            ['sid' => $sessionId, 'uid' => $memberUserId]
        );
        $data = [
            'status' => $status,
            'recorded_by' => $recordedBy,
            'recorded_at' => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $this->db->update('program_session_attendance', $existing['id'], $data);
        } else {
            $this->db->insert('program_session_attendance', array_merge($data, [
                'program_session_id' => $sessionId,
                'user_id' => $memberUserId,
            ]));
        }
        return ['success' => true];
    }

    public function listCoupons($organizationId)
    {
        return $this->db->query(
            "SELECT c.*, p.title AS program_title FROM program_coupons c
             LEFT JOIN programs p ON p.id = c.program_id
             WHERE c.organization_id = :org ORDER BY c.code ASC",
            ['org' => $organizationId]
        );
    }

    public function saveCoupon($organizationId, $data, $id = null)
    {
        $row = [
            'organization_id' => $organizationId,
            'program_id' => !empty($data['program_id']) ? (int) $data['program_id'] : null,
            'code' => strtoupper(trim($data['code'] ?? '')),
            'percent_off' => isset($data['percent_off']) && $data['percent_off'] !== '' ? (float) $data['percent_off'] : null,
            'amount_off' => isset($data['amount_off']) && $data['amount_off'] !== '' ? (float) $data['amount_off'] : null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'max_redemptions' => isset($data['max_redemptions']) && $data['max_redemptions'] !== '' ? (int) $data['max_redemptions'] : null,
            'active' => !empty($data['active']) ? 1 : 0,
        ];
        if ($row['code'] === '') {
            return ['success' => false, 'message' => 'Code required'];
        }
        if ($id) {
            $this->db->update('program_coupons', $id, $row);
        } else {
            $id = (int) $this->db->insert('program_coupons', $row);
        }
        return ['success' => true, 'id' => $id];
    }

    public function incrementCouponRedemption($couponId)
    {
        $this->db->execute(
            'UPDATE program_coupons SET redemptions_count = redemptions_count + 1 WHERE id = :id',
            ['id' => $couponId]
        );
    }

    /**
     * Member's registrations with program info.
     */
    public function listMyPrograms($userId, $organizationId)
    {
        return $this->db->query(
            "SELECT r.*, p.title, p.description, p.banner_image, p.location, p.is_virtual,
                    p.status AS program_status, p.pricing_type, p.price_amount, pc.name AS category_name
             FROM program_registrations r
             INNER JOIN programs p ON p.id = r.program_id
             LEFT JOIN program_categories pc ON pc.id = p.category_id
             WHERE r.user_id = :uid AND p.organization_id = :org
             ORDER BY r.joined_at DESC, r.created_at DESC",
            ['uid' => $userId, 'org' => $organizationId]
        );
    }

    /**
     * Active registrants for attendance UI.
     */
    public function listActiveRegistrants($programId, $organizationId)
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return [];
        }
        return $this->db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email, r.status AS reg_status, r.id AS registration_id,
                    r.created_at AS joined_at
             FROM program_registrations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.program_id = :pid AND r.status = 'active'
             ORDER BY u.last_name, u.first_name",
            ['pid' => $programId]
        );
    }

    /**
     * Active and pending registrants for admin (includes paid checkout still syncing).
     */
    public function listRegistrantsForAdmin($programId, $organizationId)
    {
        $p = $this->getByIdForOrg($programId, $organizationId);
        if (!$p) {
            return [];
        }
        return $this->db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email, r.status AS reg_status, r.id AS registration_id,
                    COALESCE(r.joined_at, r.created_at) AS joined_at
             FROM program_registrations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.program_id = :pid AND r.status IN ('active', 'pending')
             ORDER BY u.last_name, u.first_name",
            ['pid' => $programId]
        );
    }

    /**
     * Registrants with enrolled week titles (select_weeks programs).
     *
     * @return list<array<string,mixed>>
     */
    public function listActiveRegistrantsWithWeeks($programId, $organizationId)
    {
        $rows = $this->listRegistrantsForAdmin($programId, $organizationId);
        if (empty($rows)) {
            return $rows;
        }
        if ($this->programsTableHasWeekColumns()) {
            foreach ($rows as &$r) {
                $rid = (int) ($r['registration_id'] ?? 0);
                $weeks = $rid > 0 ? $this->getRegistrationWeeksDetail($rid) : [];
                $r['weeks'] = array_map(static function ($w) {
                    return [
                        'id' => (int) ($w['id'] ?? 0),
                        'title' => (string) ($w['title'] ?? ''),
                    ];
                }, $weeks);
                $r['weeks_label'] = implode(', ', array_column($r['weeks'], 'title'));
            }
            unset($r);
        }
        $this->attachRegistrationAnswers($rows, $programId);
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function attachRegistrationAnswers(array &$rows, int $programId): void
    {
        if (!$this->tableExists('program_registration_answers')) {
            foreach ($rows as &$r) {
                $r['question_answers'] = [];
            }
            unset($r);
            return;
        }

        $regIds = [];
        foreach ($rows as $r) {
            $rid = (int) ($r['registration_id'] ?? 0);
            if ($rid > 0) {
                $regIds[] = $rid;
            }
        }
        if ($regIds === []) {
            foreach ($rows as &$r) {
                $r['question_answers'] = [];
            }
            unset($r);
            return;
        }

        $qMap = [];
        foreach ($this->getQuestions($programId) as $q) {
            $qMap[(int) ($q['id'] ?? 0)] = $q;
        }

        $placeholders = implode(',', array_fill(0, count($regIds), '?'));
        $answers = $this->db->query(
            "SELECT registration_id, question_id, answer_text
             FROM program_registration_answers
             WHERE registration_id IN ($placeholders)",
            $regIds
        ) ?: [];

        $byReg = [];
        foreach ($answers as $a) {
            $rid = (int) ($a['registration_id'] ?? 0);
            $qid = (int) ($a['question_id'] ?? 0);
            $q = $qMap[$qid] ?? null;
            if (!isset($byReg[$rid])) {
                $byReg[$rid] = [];
            }
            $rawAnswer = (string) ($a['answer_text'] ?? '');
            $qType = $q ? (string) ($q['question_type'] ?? '') : '';
            $byReg[$rid][] = [
                'question_id' => $qid,
                'question_text' => $q ? (string) ($q['question_text'] ?? '') : '',
                'question_type' => $qType,
                'question_sort_order' => $q ? (int) ($q['sort_order'] ?? 0) : 0,
                'answer_text' => $rawAnswer,
                'answer_display' => self::formatRegistrationAnswerDisplay($rawAnswer, $qType),
            ];
        }

        foreach ($rows as &$r) {
            $rid = (int) ($r['registration_id'] ?? 0);
            $r['question_answers'] = $byReg[$rid] ?? [];
        }
        unset($r);
    }

    /**
     * Next upcoming session for display on cards.
     *
     * @param int|null $userId When set, filters to enrolled weeks for select_weeks programs
     */
    public function getNextSessionDate($programId, $userId = null)
    {
        $sql = "SELECT s.session_date, s.start_time, s.end_time, s.break_start_time, s.break_end_time
             FROM program_sessions s
             INNER JOIN programs p ON p.id = s.program_id
             WHERE s.program_id = :pid AND s.session_date >= CURDATE() AND s.status = 'scheduled'";
        $params = ['pid' => $programId];
        if ($userId !== null && $this->programsTableHasWeekColumns()) {
            $p = $this->db->queryOne('SELECT registration_mode FROM programs WHERE id = :id', ['id' => $programId]);
            if ($p && (string) ($p['registration_mode'] ?? '') === 'select_weeks') {
                $reg = $this->getRegistration($programId, (int) $userId);
                if ($reg && ($reg['status'] ?? '') === 'active') {
                    $weekIds = $this->getEnrolledWeekIds((int) $reg['id']);
                    if ($weekIds !== []) {
                        $phParts = [];
                        foreach ($weekIds as $i => $wid) {
                            $key = 'wid' . $i;
                            $phParts[] = ':' . $key;
                            $params[$key] = $wid;
                        }
                        $sql .= ' AND (s.week_id IS NULL OR s.week_id IN (' . implode(',', $phParts) . '))';
                    }
                }
            }
        }
        $sql .= " ORDER BY s.session_date ASC LIMIT 1";
        $row = $this->db->queryOne($sql, $params);
        return $row ?: null;
    }

    public function presentersTableExists(): bool
    {
        return $this->tableExists('program_presenters');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPresenters($programId): array
    {
        if (!$this->presentersTableExists() || (int) $programId <= 0) {
            return [];
        }
        return $this->db->query(
            "SELECT id, program_id, sort_order, display_name, title, image_path
             FROM program_presenters WHERE program_id = :pid
             ORDER BY sort_order ASC, id ASC",
            ['pid' => (int) $programId]
        ) ?: [];
    }

    public function deletePresenters($programId): void
    {
        if (!$this->presentersTableExists() || (int) $programId <= 0) {
            return;
        }
        $this->db->execute('DELETE FROM program_presenters WHERE program_id = :pid', ['pid' => (int) $programId]);
    }

    /**
     * @param array<string,mixed> $input Request payload (expects presenters JSON array or string)
     * @param array<string,mixed> $files $_FILES — keys presenter_image_{index}
     * @param array<string,mixed> $config App config
     */
    public function replacePresentersFromAdminInput(int $programId, array $input, array $files, array $config): void
    {
        if (!$this->presentersTableExists() || $programId <= 0) {
            return;
        }
        if (!array_key_exists('presenters', $input)) {
            return;
        }
        $raw = $input['presenters'];
        if (is_string($raw)) {
            $people = json_decode($raw, true);
        } else {
            $people = is_array($raw) ? $raw : [];
        }
        if (!is_array($people)) {
            $people = [];
        }

        $uploadConfig = $config['uploads'] ?? [];
        $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $uploadConfig['max_size'] = 5242880;
        if (empty($uploadConfig['upload_path'])) {
            $uploadConfig['upload_path'] = dirname(__DIR__, 2) . '/uploads/';
        }
        $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';

        $this->deletePresenters($programId);

        foreach ($people as $idx => $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = isset($p['display_name']) ? trim((string) $p['display_name']) : '';
            if ($name === '') {
                continue;
            }
            $title = isset($p['title']) ? trim((string) $p['title']) : '';
            $title = $title === '' ? null : substr($title, 0, 255);
            $sortOrder = isset($p['sort_order']) ? (int) $p['sort_order'] : (int) $idx;
            $imagePath = isset($p['image_path']) ? trim((string) $p['image_path']) : '';
            $imagePath = $imagePath === '' ? null : substr($imagePath, 0, 500);

            if (!empty($p['remove_image'])) {
                $imagePath = null;
            }

            $fileKey = 'presenter_image_' . $idx;
            if (!empty($files[$fileKey]) && isset($files[$fileKey]['error']) && (int) $files[$fileKey]['error'] === UPLOAD_ERR_OK) {
                try {
                    $fileUpload = new FileUpload($uploadConfig);
                    $uploadResult = $fileUpload->upload($files[$fileKey], 'program-presenters');
                    $imagePath = 'program-presenters/' . $uploadResult['filename'];
                    $full = $uploadConfig['upload_path'] . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
                    if (!file_exists($full) || !is_file($full)) {
                        $imagePath = isset($p['image_path']) ? trim((string) $p['image_path']) : null;
                        if ($imagePath === '') {
                            $imagePath = null;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('program presenter image upload: ' . $e->getMessage());
                }
            }

            $this->db->insert('program_presenters', [
                'program_id' => $programId,
                'sort_order' => $sortOrder,
                'display_name' => substr($name, 0, 255),
                'title' => $title,
                'image_path' => $imagePath,
            ]);
        }
    }

    /**
     * @param list<int> $programIds
     * @return array<int, list<array<string,mixed>>>
     */
    public function listPresentersForPrograms(array $programIds): array
    {
        $out = [];
        if (!$this->presentersTableExists() || empty($programIds)) {
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $programIds))));
        if (empty($ids)) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query(
            "SELECT id, program_id, sort_order, display_name, title, image_path
             FROM program_presenters WHERE program_id IN ($ph)
             ORDER BY program_id ASC, sort_order ASC, id ASC",
            $ids
        );
        foreach ($rows as $r) {
            $pid = (int) $r['program_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][] = $r;
        }

        return $out;
    }
}
