<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * Facility bookings: create, overlap prevention, approve/reject, availability.
 */
class FacilityBookingService
{
    private $db;
    private $facilityService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->facilityService = new FacilityService();
    }

    public function getByIdForOrg($bookingId, $organizationId)
    {
        return $this->db->queryOne(
            "SELECT b.*, f.name AS facility_name, f.slug AS facility_slug, f.location AS facility_location,
                    u.first_name, u.last_name, u.email, u.phone, u.password_hash
             FROM facility_bookings b
             INNER JOIN facilities f ON f.id = b.facility_id
             INNER JOIN users u ON u.id = b.booked_by_user_id
             WHERE b.id = :id AND b.organization_id = :org",
            ['id' => (int) $bookingId, 'org' => (int) $organizationId]
        );
    }

    public function listForOrg($organizationId, $filters = [])
    {
        $sql = "SELECT b.*, f.name AS facility_name, f.slug AS facility_slug,
                       u.first_name, u.last_name, u.email
                FROM facility_bookings b
                INNER JOIN facilities f ON f.id = b.facility_id
                INNER JOIN users u ON u.id = b.booked_by_user_id
                WHERE b.organization_id = :org";
        $params = ['org' => (int) $organizationId];

        if (!empty($filters['status'])) {
            $sql .= " AND b.status = :st";
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['facility_id'])) {
            $sql .= " AND b.facility_id = :fid";
            $params['fid'] = (int) $filters['facility_id'];
        }
        if (!empty($filters['facility_ids']) && is_array($filters['facility_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['facility_ids'])));
            if ($ids !== []) {
                $ph = [];
                foreach ($ids as $i => $fid) {
                    $k = '_fid_' . $i;
                    $ph[] = ':' . $k;
                    $params[$k] = $fid;
                }
                $sql .= ' AND b.facility_id IN (' . implode(',', $ph) . ')';
            }
        }
        if (!empty($filters['start'])) {
            $sql .= " AND b.end_datetime >= :start";
            $params['start'] = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $sql .= " AND b.start_datetime <= :end";
            $params['end'] = $filters['end'];
        }

        $sql .= " ORDER BY b.start_datetime DESC";
        return $this->db->query($sql, $params);
    }

    public function listForUser($userId, $organizationId)
    {
        return $this->db->query(
            "SELECT b.*, f.name AS facility_name, f.slug AS facility_slug, f.location AS facility_location
             FROM facility_bookings b
             INNER JOIN facilities f ON f.id = b.facility_id
             WHERE b.booked_by_user_id = :uid AND b.organization_id = :org
             ORDER BY b.start_datetime DESC",
            ['uid' => (int) $userId, 'org' => (int) $organizationId]
        );
    }

    public function getAvailability($facilityId, $startDate, $endDate, $includePending = true)
    {
        $statuses = $includePending ? ['pending', 'approved'] : ['approved'];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $params = array_merge(
            [(int) $facilityId, $endDate . ' 23:59:59', $startDate . ' 00:00:00'],
            $statuses
        );
        $bookings = $this->db->query(
            "SELECT id, title, start_datetime, end_datetime, status
             FROM facility_bookings
             WHERE facility_id = ?
               AND start_datetime < ?
               AND end_datetime > ?
               AND status IN ({$placeholders})
             ORDER BY start_datetime ASC",
            $params
        );

        $row = $this->db->queryOne(
            "SELECT organization_id FROM facilities WHERE id = :id",
            ['id' => (int) $facilityId]
        );
        if (!$row) {
            return $bookings;
        }
        $facility = $this->facilityService->getByIdForOrg((int) $facilityId, (int) $row['organization_id']);
        if (!$facility) {
            return $bookings;
        }

        $blocked = $this->facilityService->getBlockedTimesInRange($facility, $startDate, $endDate);
        $merged = array_merge($bookings, $blocked);
        usort($merged, function ($a, $b) {
            return strcmp($a['start_datetime'], $b['start_datetime']);
        });

        return $merged;
    }

    /**
     * Availability blocks enriched for admin calendar (type, editable, source ids).
     *
     * @return list<array<string, mixed>>
     */
    public function getAvailabilityForAdmin($facilityId, $startDate, $endDate, $includePending = true): array
    {
        $merged = $this->getAvailability($facilityId, $startDate, $endDate, $includePending);
        $out = [];
        foreach ($merged as $item) {
            $id = (string) ($item['id'] ?? '');
            $row = $item;
            if ($id !== '' && ctype_digit($id)) {
                $st = strtolower(trim((string) ($item['status'] ?? 'approved')));
                $row['type'] = $st === 'pending' ? 'booking_pending' : 'booking_approved';
                $row['editable'] = false;
                $row['source_id'] = (int) $id;
                $row['block_index'] = null;
            } elseif (preg_match('/^blocked-(\d+)/', $id, $m)) {
                $row['type'] = 'manual_block';
                $row['editable'] = true;
                $row['block_index'] = (int) $m[1];
                $row['source_id'] = null;
            } elseif (str_starts_with($id, 'event-')) {
                $row['type'] = 'headcount_event';
                $row['editable'] = false;
                $row['source_id'] = (int) substr($id, 6);
                $row['block_index'] = null;
            } else {
                $row['type'] = 'unknown';
                $row['editable'] = false;
                $row['source_id'] = null;
                $row['block_index'] = null;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * FullCalendar feed for all facilities (bookings, blocks, linked events).
     *
     * @param list<int>|null $facilityIds When set, only these facilities (coordinator scope). [0] = none.
     * @return list<array<string, mixed>>
     */
    public function getOrgCalendarForAdmin(
        int $organizationId,
        string $startDate,
        string $endDate,
        ?array $facilityIds = null,
        int $singleFacilityId = 0
    ): array {
        $facilities = $this->facilityService->listForOrg($organizationId, []);

        if ($singleFacilityId > 0) {
            $facilities = array_values(array_filter(
                $facilities,
                static fn ($f) => (int) ($f['id'] ?? 0) === $singleFacilityId
            ));
        } elseif ($facilityIds !== null) {
            $allowed = array_flip(array_map('intval', $facilityIds));
            if (isset($allowed[0]) && count($allowed) === 1) {
                return [];
            }
            unset($allowed[0]);
            $facilities = array_values(array_filter(
                $facilities,
                static fn ($f) => isset($allowed[(int) ($f['id'] ?? 0)])
            ));
        }

        $events = [];
        foreach ($facilities as $facility) {
            $fid = (int) ($facility['id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            $blocks = $this->getAvailabilityForAdmin($fid, $startDate, $endDate, true);
            foreach ($blocks as $block) {
                $events[] = $this->calendarEventFromBlock($block, $facility);
            }
        }

        usort($events, static function ($a, $b) {
            return strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''));
        });

        return $events;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $facility
     * @return array<string, mixed>
     */
    private function calendarEventFromBlock(array $block, array $facility): array
    {
        $fid = (int) ($facility['id'] ?? 0);
        $facilityName = trim(html_entity_decode((string) ($facility['name'] ?? 'Facility'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $itemTitle = trim((string) ($block['title'] ?? 'Reserved'));
        $blockId = (string) ($block['id'] ?? ('x' . uniqid()));
        $startRaw = (string) ($block['start_datetime'] ?? '');
        $endRaw = (string) ($block['end_datetime'] ?? '');

        return [
            'id' => $fid . '-' . $blockId,
            'title' => $facilityName . ': ' . $itemTitle,
            'start' => $this->calendarIsoDatetime($startRaw),
            'end' => $this->calendarIsoDatetime($endRaw),
            'extendedProps' => array_merge($block, [
                'facility_id' => $fid,
                'facility_name' => $facilityName,
                'display_title' => $itemTitle,
            ]),
        ];
    }

    private function calendarIsoDatetime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, 'T')) {
            return $value;
        }

        return str_replace(' ', 'T', $value);
    }

    public function hasOverlap($facilityId, $startDatetime, $endDatetime, $excludeBookingId = null)
    {
        $facility = $this->db->queryOne("SELECT buffer_minutes FROM facilities WHERE id = :id", ['id' => (int) $facilityId]);
        $buffer = (int) ($facility['buffer_minutes'] ?? 0);
        $start = date('Y-m-d H:i:s', strtotime($startDatetime) - ($buffer * 60));
        $end = date('Y-m-d H:i:s', strtotime($endDatetime) + ($buffer * 60));

        $sql = "SELECT id FROM facility_bookings
                WHERE facility_id = :fid
                  AND status IN ('pending', 'approved')
                  AND start_datetime < :end AND end_datetime > :start";
        $params = ['fid' => (int) $facilityId, 'start' => $start, 'end' => $end];
        if ($excludeBookingId) {
            $sql .= " AND id != :ex";
            $params['ex'] = (int) $excludeBookingId;
        }
        $sql .= " LIMIT 1";
        $row = $this->db->queryOne($sql, $params);
        return !empty($row);
    }

    public function createBooking($organizationId, $userId, array $data, $role = 'member', $bookedVia = 'portal')
    {
        $facilityId = (int) ($data['facility_id'] ?? 0);
        $facility = $this->facilityService->getByIdForOrg($facilityId, $organizationId);
        if (!$facility) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }

        $start = $this->normalizeDatetime($data['start_datetime'] ?? '');
        $end = $this->normalizeDatetime($data['end_datetime'] ?? '');
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'message' => 'Event title is required.'];
        }
        if (strlen($title) > 255) {
            return ['success' => false, 'message' => 'Event title must be 255 characters or fewer.'];
        }
        $purpose = trim(strip_tags((string) ($data['purpose'] ?? $data['notes'] ?? '')));
        $purposeError = headcount_validate_booking_purpose($purpose, 200);
        if ($purposeError !== null) {
            return ['success' => false, 'message' => $purposeError];
        }

        $validation = $this->facilityService->validateBookingRequest($facility, $start, $end, $role);
        if (!$validation['ok']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        $blockMsg = $this->facilityService->getSlotBlockMessage($facility, $start, $end, $role);
        if ($blockMsg !== null) {
            return ['success' => false, 'message' => $blockMsg];
        }

        if ($this->hasOverlap($facilityId, $start, $end)) {
            return ['success' => false, 'message' => 'This time slot overlaps with an existing booking.', 'code' => 409];
        }

        $pricing = $this->facilityService->calculateBookingPrice($facility, $start, $end);
        if (!empty($facility['is_paid']) && $pricing['hourly_rate'] <= 0) {
            return ['success' => false, 'message' => 'This facility requires a valid hourly rate before booking.'];
        }

        $paySvc = new FacilityPaymentService();
        if ($paySvc->facilityPaymentsEnabled() && $paySvc->requiresCheckout($facility, $pricing)) {
            return [
                'success' => false,
                'message' => 'This facility requires payment authorization. Please complete checkout.',
            ];
        }

        $insert = [
            'organization_id' => (int) $organizationId,
            'facility_id' => $facilityId,
            'booked_by_user_id' => (int) $userId,
            'title' => $title,
            'purpose' => $purpose !== '' ? $purpose : null,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => 'pending',
            'booked_via' => in_array($bookedVia, ['guest', 'portal', 'admin'], true) ? $bookedVia : 'portal',
        ];
        if ($this->facilityService->columnExistsPublic('facility_bookings', 'hours_booked')) {
            $insert['hours_booked'] = $pricing['hours_booked'];
            $insert['hourly_rate'] = $pricing['hourly_rate'] ?: null;
            $insert['discount_percent'] = $pricing['discount_percent'] ?: null;
            $insert['subtotal_amount'] = $pricing['subtotal_amount'] ?: null;
            $insert['total_amount'] = $pricing['total_amount'] ?: null;
        }
        if ($this->facilityService->columnExistsPublic('facility_bookings', 'payment_status')) {
            $insert['payment_status'] = 'not_required';
        }

        $id = (int) $this->db->insert('facility_bookings', $insert);

        $booking = $this->getByIdForOrg($id, $organizationId);
        return ['success' => true, 'id' => $id, 'booking' => $booking, 'pricing' => $pricing];
    }

    /**
     * Find or create a user for guest facility flows.
     *
     * @return array{success:bool,message?:string,user_id?:int,is_new_user?:bool,user?:array}
     */
    public function resolveGuestUser($organizationId, array $guestData)
    {
        $firstName = trim((string) ($guestData['first_name'] ?? ''));
        $lastName = trim((string) ($guestData['last_name'] ?? ''));
        $email = trim(strtolower((string) ($guestData['email'] ?? '')));

        if ($firstName === '' || $lastName === '') {
            return ['success' => false, 'message' => 'First name and last name are required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }

        $user = $this->db->queryOne(
            "SELECT * FROM users WHERE organization_id = :oid AND email = :email AND status != 'deleted'",
            ['oid' => (int) $organizationId, 'email' => $email]
        );
        $isNewUser = false;
        if (!$user) {
            $userId = (int) $this->db->insert('users', [
                'organization_id' => (int) $organizationId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => !empty($guestData['phone']) ? trim((string) $guestData['phone']) : null,
                'password_hash' => null,
                'role' => 'member',
                'status' => 'active',
                'qr_code_secret' => Security::generateToken(32),
                'email_preferences' => json_encode([
                    'event_announcements' => true,
                    'event_reminders' => true,
                    'rsvp_confirmations' => true,
                    'payment_receipts' => true,
                ]),
                'communication_preferences' => json_encode([
                    'email_enabled' => true,
                    'sms_enabled' => false,
                ]),
            ]);
            $user = $this->db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
            $isNewUser = true;
        } else {
            $userId = (int) $user['id'];
            if (!empty($guestData['phone']) && empty($user['phone'])) {
                $this->db->update('users', $userId, ['phone' => trim((string) $guestData['phone'])]);
            }
        }

        return [
            'success' => true,
            'user_id' => $userId,
            'is_new_user' => $isNewUser || empty($user['password_hash']),
            'user' => $user,
        ];
    }

    /**
     * Guest booking: resolve/create user then create pending booking.
     *
     * @return array{success:bool,message?:string,id?:int,booking?:array,is_new_user?:bool}
     */
    public function createGuestBooking($organizationId, array $guestData, array $bookingData)
    {
        $facilityId = (int) ($bookingData['facility_id'] ?? 0);
        $facility = $this->facilityService->getByIdForOrg($facilityId, $organizationId);
        if (!$facility || empty($facility['allow_guest_booking'])) {
            return ['success' => false, 'message' => 'This facility does not accept guest bookings.'];
        }

        $guestResolved = $this->resolveGuestUser($organizationId, $guestData);
        if (!$guestResolved['success']) {
            return $guestResolved;
        }
        $userId = (int) $guestResolved['user_id'];
        $user = $guestResolved['user'];

        $result = $this->createBooking($organizationId, $userId, $bookingData, 'guest', 'guest');
        if (!$result['success']) {
            return $result;
        }
        $result['is_new_user'] = !empty($guestResolved['is_new_user']);
        $result['user'] = $user;
        return $result;
    }

    public function approveBooking($bookingId, $organizationId, $reviewerId)
    {
        $booking = $this->getByIdForOrg($bookingId, $organizationId);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        if ($booking['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Only pending bookings can be approved.'];
        }
        if ($this->hasOverlap((int) $booking['facility_id'], $booking['start_datetime'], $booking['end_datetime'], (int) $bookingId)) {
            return ['success' => false, 'message' => 'Cannot approve: time slot now conflicts with another booking.'];
        }

        $paySvc = new FacilityPaymentService();
        if ($paySvc->facilityPaymentsEnabled() && ($booking['payment_status'] ?? '') === 'authorized') {
            $cap = $paySvc->captureForBooking((int) $bookingId, (int) $organizationId);
            if (!$cap['success']) {
                return $cap;
            }
        }

        $this->db->update('facility_bookings', (int) $bookingId, [
            'status' => 'approved',
            'reviewed_by' => (int) $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => null,
        ]);

        return ['success' => true, 'booking' => $this->getByIdForOrg($bookingId, $organizationId)];
    }

    public function rejectBooking($bookingId, $organizationId, $reviewerId, $reason = null)
    {
        $booking = $this->getByIdForOrg($bookingId, $organizationId);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        if ($booking['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Only pending bookings can be rejected.'];
        }

        $paySvc = new FacilityPaymentService();
        if ($paySvc->facilityPaymentsEnabled()) {
            $paySvc->releaseForBooking((int) $bookingId, (int) $organizationId, 'rejected');
        }

        $this->db->update('facility_bookings', (int) $bookingId, [
            'status' => 'rejected',
            'reviewed_by' => (int) $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
        ]);

        return ['success' => true, 'booking' => $this->getByIdForOrg($bookingId, $organizationId)];
    }

    public function cancelBooking($bookingId, $organizationId, $userId = null, $isStaff = false)
    {
        $booking = $this->getByIdForOrg($bookingId, $organizationId);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        if (!in_array($booking['status'], ['pending', 'approved'], true)) {
            return ['success' => false, 'message' => 'This booking cannot be cancelled.'];
        }
        if (!$isStaff && (int) $booking['booked_by_user_id'] !== (int) $userId) {
            return ['success' => false, 'message' => 'You can only cancel your own bookings.'];
        }
        if (!$isStaff && $booking['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Only pending bookings can be cancelled. Contact staff to cancel approved bookings.'];
        }

        $paySvc = new FacilityPaymentService();
        if ($paySvc->facilityPaymentsEnabled()) {
            $paySvc->releaseForBooking((int) $bookingId, (int) $organizationId, 'cancelled');
        }

        $this->db->update('facility_bookings', (int) $bookingId, ['status' => 'cancelled']);
        return ['success' => true, 'booking' => $this->getByIdForOrg($bookingId, $organizationId)];
    }

    private function normalizeDatetime($value)
    {
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return '';
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
