<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Facilities: CRUD, listing by role, booking rule validation.
 */
class FacilityService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function tableExists($name = 'facilities')
    {
        return $this->db->tableExists((string) $name);
    }

    public function getByIdForOrg($facilityId, $organizationId)
    {
        $row = $this->db->queryOne(
            "SELECT * FROM facilities WHERE id = :id AND organization_id = :org",
            ['id' => (int) $facilityId, 'org' => (int) $organizationId]
        );
        return $row ? $this->enrichFacility($this->decodeJsonFields($row)) : null;
    }

    public function getBySlugForOrg($slug, $organizationId)
    {
        $row = $this->db->queryOne(
            "SELECT * FROM facilities WHERE slug = :slug AND organization_id = :org",
            ['slug' => $slug, 'org' => (int) $organizationId]
        );
        return $row ? $this->enrichFacility($this->decodeJsonFields($row)) : null;
    }

    public function listForOrg($organizationId, $filters = [])
    {
        $sql = "SELECT * FROM facilities WHERE organization_id = :org";
        $params = ['org' => (int) $organizationId];
        if (!empty($filters['status'])) {
            $sql .= " AND status = :st";
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE :q OR description LIKE :q2 OR location LIKE :q3)";
            $q = '%' . $filters['search'] . '%';
            $params['q'] = $q;
            $params['q2'] = $q;
            $params['q3'] = $q;
        }
        $sql .= " ORDER BY name ASC";
        $rows = $this->db->query($sql, $params);
        return array_map(function ($row) {
            return $this->enrichFacility($this->decodeJsonFields($row));
        }, $rows);
    }

    /**
     * @param string $role guest|member|staff
     */
    public function listBookableForRole($organizationId, $role = 'member')
    {
        $sql = "SELECT * FROM facilities WHERE organization_id = :org AND status = 'active'";
        if ($role === 'guest') {
            $sql .= " AND allow_guest_booking = 1";
        } elseif ($role === 'member') {
            $sql .= " AND allow_member_booking = 1";
        }
        $sql .= " ORDER BY name ASC";
        $rows = $this->db->query($sql, ['org' => (int) $organizationId]);
        return array_map(function ($row) {
            return $this->enrichFacility($this->decodeJsonFields($row));
        }, $rows);
    }

    public function defaultOperatingHours()
    {
        $hours = [];
        for ($d = 0; $d <= 6; $d++) {
            $closed = ($d === 0 || $d === 6);
            $hours[(string) $d] = [
                'open' => '09:00',
                'close' => '21:00',
                'closed' => $closed,
            ];
        }
        return $hours;
    }

    /**
     * @return array{hours_booked:float,hourly_rate:float,discount_percent:float,subtotal_amount:float,total_amount:float,is_paid:bool}
     */
    public function calculateBookingPrice(array $facility, $startDatetime, $endDatetime)
    {
        $start = strtotime($startDatetime);
        $end = strtotime($endDatetime);
        $hours = ($start !== false && $end !== false && $end > $start)
            ? round(($end - $start) / 3600, 2)
            : 0.0;
        $isPaid = !empty($facility['is_paid']);
        $rate = $isPaid ? (float) ($facility['hourly_rate'] ?? 0) : 0.0;
        $discount = min(100, max(0, (float) ($facility['discount_percent'] ?? 0)));
        $subtotal = $isPaid ? round($hours * $rate, 2) : 0.0;
        $total = $isPaid ? round($subtotal * (1 - ($discount / 100)), 2) : 0.0;

        return [
            'hours_booked' => $hours,
            'hourly_rate' => $rate,
            'discount_percent' => $discount,
            'subtotal_amount' => $subtotal,
            'total_amount' => $total,
            'is_paid' => $isPaid,
        ];
    }

    public function saveFacility($organizationId, array $data, $facilityId = null)
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'Facility name is required.'];
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->slugify($name);
        } else {
            $slug = $this->slugify($slug);
        }
        $slug = $this->ensureUniqueSlug($organizationId, $slug, $facilityId);

        $payload = [
            'organization_id' => (int) $organizationId,
            'name' => $name,
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'capacity' => isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
            'image' => $data['image'] ?? null,
            'status' => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
            'allow_member_booking' => !empty($data['allow_member_booking']) ? 1 : 0,
            'allow_guest_booking' => !empty($data['allow_guest_booking']) ? 1 : 0,
            'member_max_duration_minutes' => max(15, (int) ($data['member_max_duration_minutes'] ?? 120)),
            'member_advance_days' => max(1, (int) ($data['member_advance_days'] ?? 30)),
            'member_operating_hours' => $this->encodeHours($data['member_operating_hours'] ?? null),
            'guest_max_duration_minutes' => max(15, (int) ($data['guest_max_duration_minutes'] ?? 120)),
            'guest_advance_days' => max(1, (int) ($data['guest_advance_days'] ?? 14)),
            'guest_operating_hours' => $this->encodeHours($data['guest_operating_hours'] ?? null),
            'staff_max_duration_minutes' => max(15, (int) ($data['staff_max_duration_minutes'] ?? 480)),
            'staff_advance_days' => max(1, (int) ($data['staff_advance_days'] ?? 90)),
            'min_duration_minutes' => max(15, (int) ($data['min_duration_minutes'] ?? 30)),
            'buffer_minutes' => max(0, (int) ($data['buffer_minutes'] ?? 0)),
            'slot_increment_minutes' => max(15, (int) ($data['slot_increment_minutes'] ?? 30)),
        ];

        if ($this->columnExists('facilities', 'is_paid')) {
            $payload['is_paid'] = !empty($data['is_paid']) ? 1 : 0;
            $payload['hourly_rate'] = !empty($data['is_paid']) && isset($data['hourly_rate']) && $data['hourly_rate'] !== ''
                ? round((float) $data['hourly_rate'], 2)
                : null;
            $payload['discount_percent'] = isset($data['discount_percent']) && $data['discount_percent'] !== ''
                ? min(100, max(0, round((float) $data['discount_percent'], 2)))
                : 0;
            $payload['discount_label'] = !empty($data['discount_label'])
                ? trim((string) $data['discount_label'])
                : null;
        }
        if ($this->columnExists('facilities', 'images')) {
            $images = $data['images'] ?? null;
            if (is_string($images)) {
                $dec = json_decode($images, true);
                $images = is_array($dec) ? $dec : [];
            }
            if (!is_array($images)) {
                $images = [];
            }
            $images = array_values(array_filter(array_map(function ($p) {
                $p = trim((string) $p);
                return $p !== '' ? $p : null;
            }, $images)));
            $payload['images'] = $images ? json_encode($images) : null;
            $payload['image'] = !empty($images[0]) ? $images[0] : ($data['image'] ?? null);
        }
        if ($this->columnExists('facilities', 'operating_hours')) {
            $payload['operating_hours'] = $this->encodeHours(
                $data['operating_hours'] ?? $this->defaultOperatingHours()
            );
        }
        if ($this->columnExists('facilities', 'blocked_times')) {
            $payload['blocked_times'] = json_encode(
                $this->normalizeBlockedTimes($data['blocked_times'] ?? [])
            );
        }

        if ($facilityId) {
            unset($payload['organization_id']);
            $this->db->update('facilities', (int) $facilityId, $payload);
            return ['success' => true, 'id' => (int) $facilityId];
        }

        $id = (int) $this->db->insert('facilities', $payload);
        return ['success' => true, 'id' => $id];
    }

    public function deleteFacility($facilityId, $organizationId)
    {
        $row = $this->getByIdForOrg($facilityId, $organizationId);
        if (!$row) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }
        $pending = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM facility_bookings WHERE facility_id = :fid AND status IN ('pending','approved')",
            ['fid' => (int) $facilityId]
        );
        if (!empty($pending['c'])) {
            return ['success' => false, 'message' => 'Cannot delete facility with active or pending bookings.'];
        }
        $this->db->execute(
            "DELETE FROM facilities WHERE id = :id AND organization_id = :org",
            ['id' => (int) $facilityId, 'org' => (int) $organizationId]
        );
        return ['success' => true];
    }

    public function managersTableExists(): bool
    {
        return $this->tableExists('facility_managers');
    }

    /**
     * @return list<array{id:int,first_name:string,last_name:string,email:string,role:string}>
     */
    public function getManagers(int $facilityId, int $organizationId): array
    {
        if (!$this->managersTableExists() || $facilityId <= 0) {
            return [];
        }
        $facility = $this->getByIdForOrg($facilityId, $organizationId);
        if (!$facility) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.role
             FROM facility_managers fm
             INNER JOIN users u ON u.id = fm.user_id
             WHERE fm.facility_id = :fid
               AND u.organization_id = :org
               AND u.status = 'active'
               AND u.role IN ('admin', 'coordinator')
             ORDER BY u.last_name ASC, u.first_name ASC, u.id ASC",
            ['fid' => $facilityId, 'org' => $organizationId]
        );

        return $rows ?: [];
    }

    /**
     * @param list<int> $userIds
     */
    public function setManagers(int $facilityId, int $organizationId, array $userIds): void
    {
        if (!$this->managersTableExists() || $facilityId <= 0) {
            return;
        }
        $facility = $this->db->queryOne(
            'SELECT id FROM facilities WHERE id = ? AND organization_id = ?',
            [$facilityId, $organizationId]
        );
        if (!$facility) {
            return;
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $validIds = [];
        foreach ($userIds as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $u = $this->db->queryOne(
                "SELECT id FROM users WHERE id = ? AND organization_id = ? AND status = 'active' AND role IN ('admin','coordinator')",
                [$uid, $organizationId]
            );
            if ($u) {
                $validIds[] = $uid;
            }
        }

        $this->db->execute('DELETE FROM facility_managers WHERE facility_id = ?', [$facilityId]);
        foreach ($validIds as $uid) {
            try {
                $this->db->insert('facility_managers', [
                    'facility_id' => $facilityId,
                    'user_id' => $uid,
                ]);
            } catch (\Throwable $e) {
                // duplicate or FK — skip
            }
        }
    }

    /**
     * @return list<int>
     */
    public function getManagedFacilityIds(int $userId, int $organizationId): array
    {
        if (!$this->managersTableExists() || $userId <= 0) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT fm.facility_id
             FROM facility_managers fm
             INNER JOIN facilities f ON f.id = fm.facility_id
             WHERE fm.user_id = :uid AND f.organization_id = :org",
            ['uid' => $userId, 'org' => $organizationId]
        );
        if (!$rows) {
            return [];
        }

        return array_values(array_map(static fn ($r) => (int) $r['facility_id'], $rows));
    }

    public function userCanManageFacility(int $userId, int $organizationId, int $facilityId, string $role): bool
    {
        if ($facilityId <= 0) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        if ($role !== 'coordinator') {
            return false;
        }
        if (!$this->managersTableExists()) {
            return false;
        }
        $r = $this->db->queryOne(
            "SELECT 1 AS ok
             FROM facility_managers fm
             INNER JOIN facilities f ON f.id = fm.facility_id
             WHERE fm.facility_id = ? AND fm.user_id = ? AND f.organization_id = ?",
            [$facilityId, $userId, $organizationId]
        );

        return !empty($r);
    }

    /**
     * Active admins/coordinators eligible for facility manager assignment.
     *
     * @return list<array{id:int,first_name:string,last_name:string,email:string,role:string}>
     */
    public function listEligibleManagers(int $organizationId): array
    {
        return $this->db->query(
            "SELECT id, first_name, last_name, email, role
             FROM users
             WHERE organization_id = :org
               AND status = 'active'
               AND role IN ('admin', 'coordinator')
             ORDER BY role ASC, last_name ASC, first_name ASC",
            ['org' => $organizationId]
        ) ?: [];
    }

    /**
     * Validate a booking request against facility rules.
     *
     * @param string $role guest|member|staff
     * @return array{ok:bool,message?:string}
     */
    public function validateBookingRequest(array $facility, $startDatetime, $endDatetime, $role = 'member')
    {
        $start = strtotime($startDatetime);
        $end = strtotime($endDatetime);
        if ($start === false || $end === false || $end <= $start) {
            return ['ok' => false, 'message' => 'Invalid start or end time.'];
        }

        if (($facility['status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'This facility is not available for booking.'];
        }

        if ($role === 'guest' && empty($facility['allow_guest_booking'])) {
            return ['ok' => false, 'message' => 'This facility does not accept guest bookings. Please log in as a member.'];
        }
        if ($role === 'member' && empty($facility['allow_member_booking'])) {
            return ['ok' => false, 'message' => 'This facility is not available for member self-service booking.'];
        }

        $durationMinutes = (int) round(($end - $start) / 60);
        $minDur = (int) ($facility['min_duration_minutes'] ?? 30);
        if ($durationMinutes < $minDur) {
            return ['ok' => false, 'message' => "Minimum booking duration is {$minDur} minutes."];
        }

        $maxDur = $this->maxDurationForRole($facility, $role);
        if ($durationMinutes > $maxDur) {
            return ['ok' => false, 'message' => "Maximum booking duration is {$maxDur} minutes for your account type."];
        }

        $advanceDays = $this->advanceDaysForRole($facility, $role);
        $maxStart = strtotime('+' . $advanceDays . ' days 23:59:59');
        if ($start > $maxStart) {
            return ['ok' => false, 'message' => "Bookings can only be made up to {$advanceDays} days in advance."];
        }
        if ($start < time() - 60) {
            return ['ok' => false, 'message' => 'Cannot book a time in the past.'];
        }

        if ($role !== 'staff') {
            $hours = $facility['operating_hours'] ?? null;
            if (empty($hours)) {
                $hours = $role === 'guest'
                    ? ($facility['guest_operating_hours'] ?? $facility['member_operating_hours'] ?? null)
                    : ($facility['member_operating_hours'] ?? null);
            }
            $hoursErr = $this->validateOperatingHours($hours, $start, $end);
            if ($hoursErr !== null) {
                return ['ok' => false, 'message' => $hoursErr];
            }

            $blockErr = $this->validateBlockedTimes($facility, $start, $end, $role);
            if ($blockErr !== null) {
                return ['ok' => false, 'message' => $blockErr];
            }
        }

        return ['ok' => true];
    }

    /**
     * Whether a slot overlaps manual blocks or published IMCA events on this facility.
     *
     * @param string $role guest|member|staff
     */
    public function getSlotBlockMessage(array $facility, $startDatetime, $endDatetime, $role = 'member')
    {
        if ($role === 'staff') {
            return null;
        }
        $startTs = strtotime($startDatetime);
        $endTs = strtotime($endDatetime);
        if ($startTs === false || $endTs === false) {
            return 'Invalid start or end time.';
        }
        return $this->validateBlockedTimes($facility, $startTs, $endTs, $role);
    }

    /**
     * Published and draft events linked to this facility (for admin schedule view).
     *
     * @return list<array<string, mixed>>
     */
    public function listLinkedEventsForFacility(int $facilityId, int $organizationId, $limit = 50)
    {
        if (!$this->db->hasColumn('events', 'facility_id')) {
            return [];
        }
        $limit = max(1, min(200, (int) $limit));
        return $this->db->query(
            "SELECT id, title, status, event_date, start_time, end_time, location
             FROM events
             WHERE facility_id = :fid AND organization_id = :org
             ORDER BY event_date DESC, start_time ASC
             LIMIT {$limit}",
            ['fid' => $facilityId, 'org' => $organizationId]
        );
    }

    /**
     * Blocked/reserved slots in a date range for availability calendars.
     *
     * @return list<array{id:string,title:string,start_datetime:string,end_datetime:string,status:string}>
     */
    public function getBlockedTimesInRange(array $facility, $startDate, $endDate)
    {
        $rangeStart = strtotime($startDate . ' 00:00:00');
        $rangeEnd = strtotime($endDate . ' 23:59:59');
        if ($rangeStart === false || $rangeEnd === false) {
            return [];
        }

        $out = [];
        $blocks = $facility['blocked_times'] ?? [];
        if (is_array($blocks)) {
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $date = trim((string) ($block['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $startTime = $this->normalizeTimeValue($block['start_time'] ?? '');
            $endTime = $this->normalizeTimeValue($block['end_time'] ?? '');
            if ($startTime === null || $endTime === null) {
                continue;
            }
            $blockStart = strtotime($date . ' ' . $startTime . ':00');
            $blockEnd = strtotime($date . ' ' . $endTime . ':00');
            if ($blockStart === false || $blockEnd === false || $blockEnd <= $blockStart) {
                continue;
            }
            if ($blockStart >= $rangeEnd || $blockEnd <= $rangeStart) {
                continue;
            }
            $reason = trim((string) ($block['reason'] ?? ''));
            $out[] = [
                'id' => 'blocked-' . $i,
                'title' => $reason !== '' ? $reason : 'Reserved',
                'start_datetime' => date('Y-m-d H:i:s', $blockStart),
                'end_datetime' => date('Y-m-d H:i:s', $blockEnd),
                'status' => 'blocked',
            ];
        }
        }

        $facilityId = (int) ($facility['id'] ?? 0);
        $orgId = (int) ($facility['organization_id'] ?? 0);
        if ($facilityId > 0 && $orgId > 0) {
            foreach ($this->getPublishedEventBlocksForFacility($facilityId, $orgId, $rangeStart, $rangeEnd) as $eb) {
                $out[] = $eb;
            }
        }

        usort($out, function ($a, $b) {
            return strcmp($a['start_datetime'], $b['start_datetime']);
        });

        return $out;
    }

    /**
     * @param string $role guest|member|staff
     */
    private function validateBlockedTimes(array $facility, $startTs, $endTs, $role)
    {
        $blocks = $facility['blocked_times'] ?? [];
        if (is_array($blocks)) {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if ($role === 'guest' && empty($block['block_guest']) && array_key_exists('block_guest', $block)) {
                continue;
            }
            if ($role === 'member' && empty($block['block_member']) && array_key_exists('block_member', $block)) {
                continue;
            }

            $date = trim((string) ($block['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $startTime = $this->normalizeTimeValue($block['start_time'] ?? '');
            $endTime = $this->normalizeTimeValue($block['end_time'] ?? '');
            if ($startTime === null || $endTime === null) {
                continue;
            }
            $blockStart = strtotime($date . ' ' . $startTime . ':00');
            $blockEnd = strtotime($date . ' ' . $endTime . ':00');
            if ($blockStart === false || $blockEnd === false || $blockEnd <= $blockStart) {
                continue;
            }
            if ($startTs < $blockEnd && $endTs > $blockStart) {
                $reason = trim((string) ($block['reason'] ?? ''));
                $label = $reason !== '' ? $reason : 'an internal reservation';
                return 'This time is reserved for ' . $label . '. Please choose another slot.';
            }
        }
        }

        $facilityId = (int) ($facility['id'] ?? 0);
        $orgId = (int) ($facility['organization_id'] ?? 0);
        if ($facilityId > 0 && $orgId > 0) {
            $eventBlocks = $this->getPublishedEventBlocksForFacility($facilityId, $orgId, $startTs, $endTs);
            foreach ($eventBlocks as $eb) {
                $blockStart = strtotime($eb['start_datetime']);
                $blockEnd = strtotime($eb['end_datetime']);
                if ($blockStart !== false && $blockEnd !== false && $startTs < $blockEnd && $endTs > $blockStart) {
                    $title = trim((string) ($eb['title'] ?? ''));
                    $label = $title !== '' ? $title : 'an IMCA event';
                    return 'This time is reserved for an IMCA event: ' . $label . '. Please choose another slot.';
                }
            }
        }

        return null;
    }

    /**
     * Published events linked to this facility block member/guest bookings.
     *
     * @return list<array{id:string,title:string,start_datetime:string,end_datetime:string,status:string}>
     */
    private function getPublishedEventBlocksForFacility($facilityId, $organizationId, $rangeStartTs, $rangeEndTs)
    {
        if (!$this->db->hasColumn('events', 'facility_id')) {
            return [];
        }

        $d0 = date('Y-m-d', $rangeStartTs);
        $d1 = date('Y-m-d', $rangeEndTs);
        $sql = "SELECT id, title, event_date, start_time, end_time
                FROM events
                WHERE facility_id = :fid
                  AND organization_id = :org
                  AND LOWER(TRIM(status)) = 'published'
                  AND event_date >= :d0 AND event_date <= :d1
                  AND start_time IS NOT NULL AND end_time IS NOT NULL";
        $params = [
            'fid' => (int) $facilityId,
            'org' => (int) $organizationId,
            'd0' => $d0,
            'd1' => $d1,
        ];
        if ($this->db->hasColumn('events', 'is_virtual')) {
            $sql .= " AND (is_virtual = 0 OR is_virtual IS NULL)";
        }

        $rows = $this->db->query($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $rawDate = $row['event_date'] ?? '';
            if ($rawDate instanceof \DateTimeInterface) {
                $date = $rawDate->format('Y-m-d');
            } else {
                $date = trim((string) $rawDate);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $ts = strtotime($date);
                    if ($ts === false) {
                        continue;
                    }
                    $date = date('Y-m-d', $ts);
                }
            }
            $startTime = $this->normalizeTimeValue($row['start_time'] ?? '');
            $endTime = $this->normalizeTimeValue($row['end_time'] ?? '');
            if ($startTime === null || $endTime === null) {
                continue;
            }
            $blockStart = strtotime($date . ' ' . $startTime . ':00');
            $blockEnd = strtotime($date . ' ' . $endTime . ':00');
            if ($blockStart === false || $blockEnd === false || $blockEnd <= $blockStart) {
                continue;
            }
            if ($blockStart >= $rangeEndTs || $blockEnd <= $rangeStartTs) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $out[] = [
                'id' => 'event-' . (int) $row['id'],
                'title' => $title !== '' ? $title : 'IMCA event',
                'start_datetime' => date('Y-m-d H:i:s', $blockStart),
                'end_datetime' => date('Y-m-d H:i:s', $blockEnd),
                'status' => 'blocked',
            ];
        }

        return $out;
    }

    /**
     * @param mixed $times
     * @return list<array{date:string,start_time:string,end_time:string,reason:?string,block_member:bool,block_guest:bool}>
     */
    private function normalizeBlockedTimes($times)
    {
        if (!is_array($times)) {
            return [];
        }
        $out = [];
        foreach ($times as $block) {
            if (!is_array($block)) {
                continue;
            }
            $date = trim((string) ($block['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $startTime = $this->normalizeTimeValue($block['start_time'] ?? '');
            $endTime = $this->normalizeTimeValue($block['end_time'] ?? '');
            if ($startTime === null || $endTime === null || $endTime <= $startTime) {
                continue;
            }
            $reason = trim((string) ($block['reason'] ?? ''));
            $out[] = [
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'reason' => $reason !== '' ? $reason : null,
                'block_member' => !array_key_exists('block_member', $block) || !empty($block['block_member']),
                'block_guest' => !array_key_exists('block_guest', $block) || !empty($block['block_guest']),
            ];
        }

        usort($out, function ($a, $b) {
            return strcmp($a['date'] . ' ' . $a['start_time'], $b['date'] . ' ' . $b['start_time']);
        });

        return $out;
    }

    private function normalizeTimeValue($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $h, $min);
            }
        }
        $ts = strtotime('1970-01-01 ' . $value);
        if ($ts === false) {
            return null;
        }

        return date('H:i', $ts);
    }

    private function maxDurationForRole(array $facility, $role)
    {
        if ($role === 'staff') {
            return (int) ($facility['staff_max_duration_minutes'] ?? 480);
        }
        if ($role === 'guest') {
            return (int) ($facility['guest_max_duration_minutes'] ?? 120);
        }
        return (int) ($facility['member_max_duration_minutes'] ?? 120);
    }

    private function advanceDaysForRole(array $facility, $role)
    {
        if ($role === 'staff') {
            return (int) ($facility['staff_advance_days'] ?? 90);
        }
        if ($role === 'guest') {
            return (int) ($facility['guest_advance_days'] ?? 14);
        }
        return (int) ($facility['member_advance_days'] ?? 30);
    }

    private function validateOperatingHours($hours, $startTs, $endTs)
    {
        if (empty($hours) || !is_array($hours)) {
            return null;
        }
        $day = (int) date('w', $startTs);
        $endDay = (int) date('w', $endTs);
        if ($day !== $endDay || date('Y-m-d', $startTs) !== date('Y-m-d', $endTs)) {
            return 'Booking must start and end on the same day within operating hours.';
        }
        $dayKey = (string) $day;
        if (empty($hours[$dayKey]) && empty($hours[$day])) {
            return 'Facility is closed on this day.';
        }
        $cfg = $hours[$dayKey] ?? $hours[$day] ?? null;
        if (!is_array($cfg)) {
            return 'Facility is closed on this day.';
        }
        if (!empty($cfg['closed'])) {
            return 'Facility is closed on this day.';
        }
        if (empty($cfg['open']) || empty($cfg['close'])) {
            return 'Facility is closed on this day.';
        }
        $open = strtotime(date('Y-m-d', $startTs) . ' ' . $cfg['open']);
        $close = strtotime(date('Y-m-d', $startTs) . ' ' . $cfg['close']);
        if ($startTs < $open || $endTs > $close) {
            return 'Booking must be within facility operating hours (' . $cfg['open'] . ' – ' . $cfg['close'] . ').';
        }
        return null;
    }

    private function decodeJsonFields(array $row)
    {
        foreach (['member_operating_hours', 'guest_operating_hours', 'operating_hours', 'images', 'blocked_times'] as $col) {
            if (!empty($row[$col]) && is_string($row[$col])) {
                $dec = json_decode($row[$col], true);
                $row[$col] = is_array($dec) ? $dec : null;
            }
        }
        if (empty($row['images']) && !empty($row['image'])) {
            $row['images'] = [(string) $row['image']];
        }
        if (empty($row['operating_hours']) && $this->columnExists('facilities', 'operating_hours')) {
            $row['operating_hours'] = $this->defaultOperatingHours();
        }
        if (!isset($row['blocked_times']) || !is_array($row['blocked_times'])) {
            $row['blocked_times'] = [];
        }
        $row['allow_member_booking'] = !empty($row['allow_member_booking']);
        $row['allow_guest_booking'] = !empty($row['allow_guest_booking']);
        $row['is_paid'] = !empty($row['is_paid']);
        return $row;
    }

    private function enrichFacility(array $row)
    {
        $paths = [];
        if (!empty($row['images']) && is_array($row['images'])) {
            $paths = $row['images'];
        } elseif (!empty($row['image'])) {
            $paths = [(string) $row['image']];
        }
        $row['image_urls'] = array_values(array_filter(array_map(function ($path) {
            $path = trim((string) $path);
            if ($path === '') {
                return null;
            }
            return function_exists('hc_public_api_image_url')
                ? hc_public_api_image_url($path)
                : $path;
        }, $paths)));
        $row['thumbnail_url'] = !empty($row['image_urls'][0]) ? $row['image_urls'][0] : null;
        if (isset($row['description']) && is_string($row['description']) && $row['description'] !== '') {
            $row['description'] = headcount_undo_nested_html_entity_encoding($row['description']);
        }
        return $row;
    }

    public function columnExistsPublic($table, $column)
    {
        return $this->columnExists($table, $column);
    }

    private function columnExists($table, $column)
    {
        return $this->db->hasColumn($table, $column);
    }

    private function encodeHours($hours)
    {
        if ($hours === null || $hours === '') {
            return null;
        }
        if (is_string($hours)) {
            $dec = json_decode($hours, true);
            $hours = is_array($dec) ? $dec : null;
        }
        return $hours ? json_encode($hours) : null;
    }

    private function slugify($text)
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-') ?: 'facility';
    }

    private function ensureUniqueSlug($organizationId, $slug, $excludeId = null)
    {
        $base = $slug;
        $i = 1;
        while (true) {
            $params = ['org' => (int) $organizationId, 'slug' => $slug];
            $sql = "SELECT id FROM facilities WHERE organization_id = :org AND slug = :slug";
            if ($excludeId) {
                $sql .= " AND id != :ex";
                $params['ex'] = (int) $excludeId;
            }
            $existing = $this->db->queryOne($sql, $params);
            if (!$existing) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }
}
