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
     * @return array{hours_booked:float,hourly_rate:float,discount_percent:float,subtotal_amount:float,addons_amount:float,total_amount:float,is_paid:bool,addon_lines:list<array<string,mixed>>}
     */
    public function calculateBookingPrice(array $facility, $startDatetime, $endDatetime, array $addonSelections = [], ?array $coupon = null)
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
        $addonQuote = ['lines' => [], 'extra' => 0.0];
        try {
            $addonQuote = (new FacilityAddonService($this->db))->quote((int) ($facility['id'] ?? 0), $addonSelections);
        } catch (\Throwable $e) {
            $addonQuote = ['lines' => [], 'extra' => 0.0];
        }
        $addonsAmount = (float) ($addonQuote['extra'] ?? 0);
        $total = round($total + $addonsAmount, 2);
        if ($coupon) {
            $total = CouponService::applyDiscount($total, $coupon);
        }

        return [
            'hours_booked' => $hours,
            'hourly_rate' => $rate,
            'discount_percent' => $discount,
            'subtotal_amount' => $subtotal,
            'addons_amount' => $addonsAmount,
            'addon_lines' => $addonQuote['lines'] ?? [],
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
     * Append one manual blocked time without re-saving the full facility record.
     *
     * Supports one-time dates, multi-day date ranges, and weekly recurring windows
     * (e.g. school hours Mon–Fri within a term).
     *
     * @param array<string,mixed> $block
     * @return array{success:bool,message?:string,blocked_times?:list<array<string,mixed>>}
     */
    public function addBlockedTime(int $facilityId, int $organizationId, array $block): array
    {
        if (!$this->columnExists('facilities', 'blocked_times')) {
            return ['success' => false, 'message' => 'Blocked times are not available. Run migration 061.'];
        }
        $facility = $this->getByIdForOrg($facilityId, $organizationId);
        if (!$facility) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }
        $normalized = $this->normalizeBlockedTimes([$block]);
        if ($normalized === []) {
            return [
                'success' => false,
                'message' => 'Invalid block: provide a date (or start/end dates), valid times, and for weekly blocks at least one weekday.',
            ];
        }
        $existing = is_array($facility['blocked_times'] ?? null) ? $facility['blocked_times'] : [];
        $merged = $this->normalizeBlockedTimes(array_merge($existing, $normalized));
        $this->persistBlockedTimes($facilityId, $organizationId, $merged);

        return ['success' => true, 'blocked_times' => $merged];
    }

    /**
     * Remove a manual blocked time by index in the stored blocked_times array.
     *
     * @return array{success:bool,message?:string,blocked_times?:list<array<string,mixed>>}
     */
    public function removeBlockedTime(int $facilityId, int $organizationId, int $index): array
    {
        if (!$this->columnExists('facilities', 'blocked_times')) {
            return ['success' => false, 'message' => 'Blocked times are not available.'];
        }
        $facility = $this->getByIdForOrg($facilityId, $organizationId);
        if (!$facility) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }
        $existing = is_array($facility['blocked_times'] ?? null) ? $facility['blocked_times'] : [];
        if ($index < 0 || $index >= count($existing)) {
            return ['success' => false, 'message' => 'Block not found.'];
        }
        array_splice($existing, $index, 1);
        $merged = $this->normalizeBlockedTimes($existing);
        $this->persistBlockedTimes($facilityId, $organizationId, $merged);

        return ['success' => true, 'blocked_times' => $merged];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    private function persistBlockedTimes(int $facilityId, int $organizationId, array $blocks): void
    {
        $this->db->execute(
            'UPDATE facilities SET blocked_times = :bt WHERE id = :id AND organization_id = :org',
            [
                'bt' => json_encode($blocks),
                'id' => $facilityId,
                'org' => $organizationId,
            ]
        );
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
     * Expands range and weekly rules into per-day occurrences.
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
                $reason = trim((string) ($block['reason'] ?? ''));
                $title = $reason !== '' ? $reason : 'Reserved';
                foreach ($this->expandBlockedTimeOccurrences($block, $startDate, $endDate) as $occ) {
                    $blockStart = strtotime($occ['date'] . ' ' . $occ['start_time'] . ':00');
                    $blockEnd = strtotime($occ['date'] . ' ' . $occ['end_time'] . ':00');
                    if ($blockStart === false || $blockEnd === false || $blockEnd <= $blockStart) {
                        continue;
                    }
                    if ($blockStart >= $rangeEnd || $blockEnd <= $rangeStart) {
                        continue;
                    }
                    $out[] = [
                        'id' => 'blocked-' . $i . '-' . $occ['date'],
                        'title' => $title,
                        'start_datetime' => date('Y-m-d H:i:s', $blockStart),
                        'end_datetime' => date('Y-m-d H:i:s', $blockEnd),
                        'status' => 'blocked',
                    ];
                }
            }
        }

        $facilityId = (int) ($facility['id'] ?? 0);
        $orgId = (int) ($facility['organization_id'] ?? 0);
        if ($facilityId > 0 && $orgId > 0) {
            foreach ($this->getPublishedEventBlocksForFacility($facilityId, $orgId, $rangeStart, $rangeEnd) as $eb) {
                $out[] = $eb;
            }
            foreach ($this->getPublishedProgramBlocksForFacility($facilityId, $orgId, $rangeStart, $rangeEnd) as $pb) {
                $out[] = $pb;
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
            $bookingStartDate = date('Y-m-d', $startTs);
            $bookingEndDate = date('Y-m-d', $endTs);
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

                foreach ($this->expandBlockedTimeOccurrences($block, $bookingStartDate, $bookingEndDate) as $occ) {
                    $blockStart = strtotime($occ['date'] . ' ' . $occ['start_time'] . ':00');
                    $blockEnd = strtotime($occ['date'] . ' ' . $occ['end_time'] . ':00');
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
            foreach ($this->getPublishedProgramBlocksForFacility($facilityId, $orgId, $startTs, $endTs) as $pb) {
                $blockStart = strtotime($pb['start_datetime']);
                $blockEnd = strtotime($pb['end_datetime']);
                if ($blockStart !== false && $blockEnd !== false && $startTs < $blockEnd && $endTs > $blockStart) {
                    $title = trim((string) ($pb['title'] ?? ''));
                    $label = $title !== '' ? $title : 'a program';
                    return 'This time is reserved for a program: ' . $label . '. Please choose another slot.';
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
        $d0 = date('Y-m-d', $rangeStartTs);
        $d1 = date('Y-m-d', $rangeEndTs);
        $params = [
            'fid' => (int) $facilityId,
            'org' => (int) $organizationId,
            'd0' => $d0,
            'd1' => $d1,
        ];
        if ($this->db->tableExists('event_facilities')) {
            $sql = "SELECT e.id, e.title, e.event_date, e.start_time, e.end_time
                    FROM events e
                    INNER JOIN event_facilities ef ON ef.event_id = e.id
                    WHERE ef.facility_id = :fid
                      AND e.organization_id = :org
                      AND LOWER(TRIM(e.status)) = 'published'
                      AND e.event_date >= :d0 AND e.event_date <= :d1
                      AND e.start_time IS NOT NULL AND e.end_time IS NOT NULL";
        } elseif ($this->db->hasColumn('events', 'facility_id')) {
            $sql = "SELECT id, title, event_date, start_time, end_time
                    FROM events
                    WHERE facility_id = :fid
                      AND organization_id = :org
                      AND LOWER(TRIM(status)) = 'published'
                      AND event_date >= :d0 AND event_date <= :d1
                      AND start_time IS NOT NULL AND end_time IS NOT NULL";
        } else {
            return [];
        }
        if ($this->db->hasColumn('events', 'is_virtual')) {
            $sql .= $this->db->tableExists('event_facilities')
                ? " AND (e.is_virtual = 0 OR e.is_virtual IS NULL)"
                : " AND (is_virtual = 0 OR is_virtual IS NULL)";
        }

        $rows = $this->db->query($sql, $params);
        return $this->mapTimedBlocks($rows ?: [], 'event-', $rangeStartTs, $rangeEndTs);
    }

    /**
     * @return list<array{id:string,title:string,start_datetime:string,end_datetime:string,status:string}>
     */
    private function getPublishedProgramBlocksForFacility($facilityId, $organizationId, $rangeStartTs, $rangeEndTs)
    {
        if (!$this->db->tableExists('program_facilities') || !$this->db->tableExists('program_sessions')) {
            return [];
        }
        $d0 = date('Y-m-d', $rangeStartTs);
        $d1 = date('Y-m-d', $rangeEndTs);
        $sql = "SELECT CONCAT('p', p.id, '-', s.id) AS id, p.title, s.session_date AS event_date, s.start_time, s.end_time
                FROM program_sessions s
                INNER JOIN programs p ON p.id = s.program_id
                INNER JOIN program_facilities pf ON pf.program_id = p.id
                WHERE pf.facility_id = :fid
                  AND p.organization_id = :org
                  AND LOWER(TRIM(p.status)) = 'published'
                  AND (s.status IS NULL OR s.status <> 'cancelled')
                  AND s.session_date >= :d0 AND s.session_date <= :d1
                  AND s.start_time IS NOT NULL AND s.end_time IS NOT NULL";
        $rows = $this->db->query($sql, [
            'fid' => (int) $facilityId,
            'org' => (int) $organizationId,
            'd0' => $d0,
            'd1' => $d1,
        ]);
        return $this->mapTimedBlocks($rows ?: [], 'program-', $rangeStartTs, $rangeEndTs);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:string,title:string,start_datetime:string,end_datetime:string,status:string}>
     */
    private function mapTimedBlocks(array $rows, string $idPrefix, $rangeStartTs, $rangeEndTs): array
    {
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
            $rowId = (string) ($row['id'] ?? '');
            $out[] = [
                'id' => $idPrefix . $rowId,
                'title' => $title !== '' ? $title : trim($idPrefix, '-'),
                'start_datetime' => date('Y-m-d H:i:s', $blockStart),
                'end_datetime' => date('Y-m-d H:i:s', $blockEnd),
                'status' => 'blocked',
            ];
        }

        return $out;
    }

    /**
     * Normalize stored blocked-time rules.
     *
     * Shapes:
     * - once:   { repeat: "once", date, start_time, end_time, … }
     * - range:  { repeat: "range", start_date, end_date, start_time, end_time, … }
     * - weekly: { repeat: "weekly", start_date, end_date, days_of_week, start_time, end_time, … }
     *
     * Legacy single-date entries (date only) are kept as repeat "once".
     *
     * @param mixed $times
     * @return list<array<string,mixed>>
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
            $startTime = $this->normalizeTimeValue($block['start_time'] ?? '');
            $endTime = $this->normalizeTimeValue($block['end_time'] ?? '');
            if ($startTime === null || $endTime === null || $endTime <= $startTime) {
                continue;
            }

            $repeat = strtolower(trim((string) ($block['repeat'] ?? '')));
            $date = $this->normalizeDateValue($block['date'] ?? '');
            $startDate = $this->normalizeDateValue($block['start_date'] ?? '');
            $endDate = $this->normalizeDateValue($block['end_date'] ?? '');
            $daysOfWeek = $this->normalizeDaysOfWeek($block['days_of_week'] ?? null);

            if ($repeat === '' || !in_array($repeat, ['once', 'range', 'weekly'], true)) {
                if ($daysOfWeek !== []) {
                    $repeat = 'weekly';
                } elseif ($startDate !== null && $endDate !== null && ($date === null || $startDate !== $endDate)) {
                    $repeat = 'range';
                } else {
                    $repeat = 'once';
                }
            }

            if ($repeat === 'once') {
                if ($date === null) {
                    if ($startDate !== null && ($endDate === null || $endDate === $startDate)) {
                        $date = $startDate;
                    } else {
                        continue;
                    }
                }
                $entry = [
                    'repeat' => 'once',
                    'date' => $date,
                    'start_date' => $date,
                    'end_date' => $date,
                    'days_of_week' => [],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ];
            } elseif ($repeat === 'range') {
                if ($startDate === null && $date !== null) {
                    $startDate = $date;
                }
                if ($endDate === null && $date !== null) {
                    $endDate = $date;
                }
                if ($startDate === null || $endDate === null || $endDate < $startDate) {
                    continue;
                }
                // Cap extreme ranges to keep expansion bounded.
                if ($this->dateDiffDays($startDate, $endDate) > 366) {
                    continue;
                }
                $entry = [
                    'repeat' => 'range',
                    'date' => $startDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days_of_week' => [],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ];
            } else { // weekly
                if ($daysOfWeek === []) {
                    continue;
                }
                if ($startDate === null && $date !== null) {
                    $startDate = $date;
                }
                if ($endDate === null && $date !== null) {
                    $endDate = $date;
                }
                if ($startDate === null || $endDate === null || $endDate < $startDate) {
                    continue;
                }
                if ($this->dateDiffDays($startDate, $endDate) > 731) {
                    continue;
                }
                $entry = [
                    'repeat' => 'weekly',
                    'date' => $startDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days_of_week' => $daysOfWeek,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ];
            }

            $reason = trim((string) ($block['reason'] ?? ''));
            $entry['reason'] = $reason !== '' ? $reason : null;
            $entry['block_member'] = !array_key_exists('block_member', $block) || !empty($block['block_member']);
            $entry['block_guest'] = !array_key_exists('block_guest', $block) || !empty($block['block_guest']);
            $out[] = $entry;
        }

        usort($out, function ($a, $b) {
            $aKey = ($a['start_date'] ?? $a['date'] ?? '') . ' ' . ($a['start_time'] ?? '');
            $bKey = ($b['start_date'] ?? $b['date'] ?? '') . ' ' . ($b['start_time'] ?? '');
            return strcmp($aKey, $bKey);
        });

        return $out;
    }

    /**
     * Expand a block rule into concrete date occurrences within [rangeStart, rangeEnd].
     *
     * @param array<string,mixed> $block
     * @return list<array{date:string,start_time:string,end_time:string}>
     */
    private function expandBlockedTimeOccurrences(array $block, $rangeStartDate, $rangeEndDate): array
    {
        $startTime = $this->normalizeTimeValue($block['start_time'] ?? '');
        $endTime = $this->normalizeTimeValue($block['end_time'] ?? '');
        if ($startTime === null || $endTime === null || $endTime <= $startTime) {
            return [];
        }

        $rangeStart = $this->normalizeDateValue($rangeStartDate);
        $rangeEnd = $this->normalizeDateValue($rangeEndDate);
        if ($rangeStart === null || $rangeEnd === null || $rangeEnd < $rangeStart) {
            return [];
        }

        $repeat = strtolower(trim((string) ($block['repeat'] ?? 'once')));
        $date = $this->normalizeDateValue($block['date'] ?? '');
        $blockStart = $this->normalizeDateValue($block['start_date'] ?? '') ?? $date;
        $blockEnd = $this->normalizeDateValue($block['end_date'] ?? '') ?? $date;
        $daysOfWeek = $this->normalizeDaysOfWeek($block['days_of_week'] ?? null);

        if ($repeat === '' || !in_array($repeat, ['once', 'range', 'weekly'], true)) {
            if ($daysOfWeek !== []) {
                $repeat = 'weekly';
            } elseif ($blockStart !== null && $blockEnd !== null && $blockStart !== $blockEnd) {
                $repeat = 'range';
            } else {
                $repeat = 'once';
            }
        }

        if ($repeat === 'once') {
            if ($date === null) {
                return [];
            }
            if ($date < $rangeStart || $date > $rangeEnd) {
                return [];
            }
            return [['date' => $date, 'start_time' => $startTime, 'end_time' => $endTime]];
        }

        if ($blockStart === null || $blockEnd === null || $blockEnd < $blockStart) {
            return [];
        }

        $windowStart = max($blockStart, $rangeStart);
        $windowEnd = min($blockEnd, $rangeEnd);
        if ($windowEnd < $windowStart) {
            return [];
        }

        $dayFilter = null;
        if ($repeat === 'weekly') {
            if ($daysOfWeek === []) {
                return [];
            }
            $dayFilter = array_fill_keys($daysOfWeek, true);
        }

        $out = [];
        $cursor = strtotime($windowStart . ' 12:00:00');
        $endTs = strtotime($windowEnd . ' 12:00:00');
        if ($cursor === false || $endTs === false) {
            return [];
        }
        while ($cursor <= $endTs) {
            $d = date('Y-m-d', $cursor);
            $dow = (int) date('w', $cursor);
            if ($dayFilter === null || isset($dayFilter[$dow])) {
                $out[] = [
                    'date' => $d,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ];
            }
            $cursor = strtotime('+1 day', $cursor);
            if ($cursor === false) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private function normalizeDateValue($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $ts = strtotime($value . ' 12:00:00');
            return $ts === false ? null : date('Y-m-d', $ts);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    /**
     * @param mixed $days
     * @return list<int>
     */
    private function normalizeDaysOfWeek($days): array
    {
        if (!is_array($days)) {
            return [];
        }
        $out = [];
        foreach ($days as $d) {
            if (is_string($d) && !is_numeric($d)) {
                $map = [
                    'sun' => 0, 'sunday' => 0,
                    'mon' => 1, 'monday' => 1,
                    'tue' => 2, 'tues' => 2, 'tuesday' => 2,
                    'wed' => 3, 'wednesday' => 3,
                    'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
                    'fri' => 5, 'friday' => 5,
                    'sat' => 6, 'saturday' => 6,
                ];
                $key = strtolower(trim($d));
                if (!isset($map[$key])) {
                    continue;
                }
                $n = $map[$key];
            } else {
                $n = (int) $d;
            }
            if ($n >= 0 && $n <= 6) {
                $out[$n] = $n;
            }
        }
        $vals = array_values($out);
        sort($vals);

        return $vals;
    }

    private function dateDiffDays(string $startDate, string $endDate): int
    {
        $a = strtotime($startDate . ' 12:00:00');
        $b = strtotime($endDate . ' 12:00:00');
        if ($a === false || $b === false) {
            return 0;
        }

        return (int) round(($b - $a) / 86400);
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
