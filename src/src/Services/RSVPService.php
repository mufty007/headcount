<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Core\Database as CoreDatabase;

/**
 * RSVP Service
 * Handles RSVP operations for members
 */
class RSVPService
{
    private $db;

    /** @var EventEligibilityService */
    private $eligibility;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->eligibility = new EventEligibilityService($this->db);
    }

    /**
     * Create RSVP for an event
     * 
     * @param int $eventId Event ID
     * @param int $userId User ID
     * @param int $guests Number of guests (default 0)
     * @param array $familyMemberIds Array of family member IDs to RSVP for
     * @param array $options Optional: from_payment_success (bool) — when true, only RSVP the given event_id (no all_sessions expansion)
     * @return array Result with success status and RSVP data
     */
    /**
     * True when registration_deadline is set and current time (org timezone) is past it.
     * Used to block online RSVP; does not affect admin check-in.
     */
    public function isRegistrationDeadlinePassed(array $event): bool
    {
        $deadline = $event['registration_deadline'] ?? null;
        if ($deadline === null || $deadline === '') {
            return false;
        }
        $orgId = (int) ($event['organization_id'] ?? 0);
        $tzName = OrgTimeZone::FALLBACK_IANA;
        if ($orgId > 0) {
            try {
                $org = $this->db->queryOne('SELECT timezone FROM organizations WHERE id = ?', [$orgId]);
                $tzName = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
            } catch (\Exception $e) {
                // ignore
            }
        }
        try {
            $tz = new \DateTimeZone($tzName);
            $now = new \DateTime('now', $tz);
            $deadlineStr = trim(str_replace('T', ' ', (string) $deadline));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadlineStr)) {
                $deadlineStr .= ' 23:59:59';
            }
            $dt = new \DateTime($deadlineStr, $tz);
            return $now > $dt;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createRSVP($eventId, $userId, $guests = 0, $familyMemberIds = [], array $options = [])
    {
        $eventId = (int) $eventId;
        $userId = (int) $userId;
        $guests = max(0, (int) $guests);

        $event = $this->db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            return [
                'success' => false,
                'message' => 'Event not found or not available for RSVP'
            ];
        }

        if (!EventVisibilityService::memberMayRsvp($this->db, $event, $userId)) {
            return [
                'success' => false,
                'message' => 'This event is not available for RSVP with your account.',
            ];
        }

        $fromPayment = !empty($options['from_payment_success']);

        $this->ensureAccountSetup($userId, $event['organization_id']);

        $userEmail = null;
        try {
            $userRow = $this->db->queryOne(
                "SELECT email FROM users WHERE id = :id AND status != 'deleted'",
                ['id' => $userId]
            );
            $userEmail = $userRow['email'] ?? null;
        } catch (\Exception $e) {
        }

        if ($userEmail !== null) {
            $userEmail = strtolower(trim((string) $userEmail));
            if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Please enter a valid email address.'
                ];
            }
        }

        $familyMembers = [];
        if (!empty($familyMemberIds)) {
            $params = ['user_id' => $userId];
            $placeholders = [];
            foreach ($familyMemberIds as $index => $fmId) {
                $key = 'fm_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $fmId;
            }
            $placeholdersStr = implode(',', $placeholders);

            $familyMembers = $this->db->query(
                "SELECT * FROM family_members 
                 WHERE id IN ($placeholdersStr) AND parent_user_id = :user_id",
                $params
            );

            if (count($familyMembers) !== count(array_unique(array_map('intval', $familyMemberIds)))) {
                return [
                    'success' => false,
                    'message' => 'Invalid family member(s) selected'
                ];
            }
        }

        $profileUser = null;
        try {
            $profileUser = $this->db->queryOne(
                'SELECT id, first_name, last_name, date_of_birth, gender FROM users WHERE id = ? AND status != ?',
                [$userId, 'deleted']
            );
        } catch (\Exception $e) {
            $profileUser = null;
        }

        $notesParts = [];
        if ($guests > 0) {
            $notesParts[] = "Guests: {$guests}";
        }
        if (!empty($familyMembers)) {
            $familyNames = array_map(function ($fm) {
                return trim($fm['first_name'] . ' ' . $fm['last_name']);
            }, $familyMembers);
            $notesParts[] = "Family members: " . implode(', ', $familyNames);
        }
        $notes = !empty($notesParts) ? implode('; ', $notesParts) : null;

        $seriesRootId = EventSeriesHelper::getSeriesRootId($this->db, $eventId);
        $sessionMode = EventSeriesHelper::getSessionRegistrationMode($this->db, $eventId);
        $seriesIds = ($seriesRootId && EventSeriesHelper::columnExists($this->db))
            ? EventSeriesHelper::getPublishedSeriesEventIds($this->db, $seriesRootId)
            : [];

        $targetEventIds = [$eventId];
        if (
            !$fromPayment
            && $seriesRootId
            && count($seriesIds) > 1
            && $sessionMode === EventSeriesHelper::MODE_ALL_SESSIONS
        ) {
            $targetEventIds = $seriesIds;
        }

        if ($seriesRootId && count($seriesIds) > 1 && $sessionMode === EventSeriesHelper::MODE_CHOOSE_ONE) {
            $clearUserIds = [$userId];
            foreach ($familyMembers as $fm) {
                if (!empty($fm['linked_user_id'])) {
                    $clearUserIds[] = (int) $fm['linked_user_id'];
                }
            }
            EventSeriesHelper::clearYesRsvpsExcept($this->db, $clearUserIds, $seriesIds, $eventId);
        }

        foreach ($targetEventIds as $tid) {
            $tid = (int) $tid;
            $ev = $this->db->queryOne(
                "SELECT * FROM events WHERE id = :id AND status = 'published'",
                ['id' => $tid]
            );
            if (!$ev) {
                if ($sessionMode === EventSeriesHelper::MODE_ALL_SESSIONS) {
                    return [
                        'success' => false,
                        'message' => 'One or more sessions in this series are no longer available for RSVP.'
                    ];
                }
                continue;
            }
            if (!EventVisibilityService::memberMayRsvp($this->db, $ev, $userId)) {
                return [
                    'success' => false,
                    'message' => 'This event is not available for RSVP with your account.',
                ];
            }
            if (!$fromPayment && $this->isRegistrationDeadlinePassed($ev)) {
                $title = $ev['title'] ?? 'Session';
                $multi = count($targetEventIds) > 1;
                return [
                    'success' => false,
                    'message' => $multi
                        ? "Online RSVP is closed for: {$title}"
                        : 'Online RSVP is closed for this event. You can still attend as a walk-in if space allows — ask staff at the event.'
                ];
            }
            if (!$fromPayment && is_array($profileUser) && $this->eligibility->eventHasRestrictionRules($ev)) {
                $chk = $this->eligibility->checkEligibility($ev, $profileUser, null);
                if (!$chk['ok']) {
                    return ['success' => false, 'message' => $chk['message'] ?? 'You do not meet this event’s eligibility requirements.'];
                }
                foreach ($familyMembers as $fmRow) {
                    $chkFm = $this->eligibility->checkEligibility($ev, null, $fmRow);
                    if (!$chkFm['ok']) {
                        return ['success' => false, 'message' => $chkFm['message'] ?? 'A family member does not meet this event’s eligibility requirements.'];
                    }
                }
            }
            $dupMsg = $this->validateDuplicateEmailForEvent($tid, $userId, $userEmail);
            if ($dupMsg !== null) {
                return ['success' => false, 'message' => $dupMsg];
            }
            $capMsg = $this->validateCapacityForEvent($ev, $guests, $familyMembers);
            if ($capMsg !== null) {
                $title = $ev['title'] ?? 'Session';
                return [
                    'success' => false,
                    'message' => $sessionMode === EventSeriesHelper::MODE_ALL_SESSIONS
                        ? "{$capMsg} ({$title})"
                        : $capMsg
                ];
            }
        }

        $familyRSVPsMerged = [];
        $anyUpdated = false;
        $rsvpForRequested = null;

        foreach ($targetEventIds as $tid) {
            $tid = (int) $tid;
            $ev = $this->db->queryOne(
                "SELECT id FROM events WHERE id = :id AND status = 'published'",
                ['id' => $tid]
            );
            if (!$ev) {
                continue;
            }
            $result = $this->upsertPrimaryRsvp($tid, $userId, $guests, $notes);
            if ($result['updated']) {
                $anyUpdated = true;
            }
            if (!empty($result['rsvp']['id'])) {
                $this->syncRsvpFamilyMemberLinks((int) $result['rsvp']['id'], $familyMemberIds);
            }
            if ($tid === $eventId) {
                $rsvpForRequested = $result['rsvp'];
            }
            $familyRSVPsMerged = array_merge(
                $familyRSVPsMerged,
                $this->createFamilyMemberRSVPs($tid, $familyMembers, $userId)
            );
        }

        if (!$rsvpForRequested) {
            $rsvpForRequested = $this->db->queryOne(
                "SELECT * FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
                ['event_id' => $eventId, 'user_id' => $userId]
            );
        }

        return [
            'success' => true,
            'message' => $anyUpdated ? 'RSVP updated' : 'RSVP created successfully',
            'rsvp' => $rsvpForRequested,
            'updated' => $anyUpdated,
            'family_rsvps' => $familyRSVPsMerged
        ];
    }

    /**
     * @return string|null Error message or null if OK
     */
    private function validateDuplicateEmailForEvent(int $eventId, int $userId, ?string $userEmail): ?string
    {
        if ($userEmail === null || $userEmail === '') {
            return null;
        }
        try {
            $duplicateEmailRsvp = $this->db->queryOne(
                "SELECT r.id
                 FROM rsvps r
                 JOIN users u ON u.id = r.user_id
                 WHERE r.event_id = :event_id
                   AND r.status = 'yes'
                   AND u.email = :email
                   AND r.user_id != :user_id
                 LIMIT 1",
                [
                    'event_id' => $eventId,
                    'email' => $userEmail,
                    'user_id' => $userId
                ]
            );
        } catch (\Exception $e) {
            return null;
        }
        if (!empty($duplicateEmailRsvp)) {
            return 'This email has already RSVP\'d for this event.';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<int,array<string,mixed>> $familyMembers
     * @return string|null Error message or null
     */
    private function validateCapacityForEvent(array $event, int $guests, array $familyMembers): ?string
    {
        $eventId = (int) $event['id'];
        if (empty($event['registration_required']) || empty($event['capacity'])) {
            return null;
        }
        $currentRSVPs = $this->getRSVPCount($eventId, 'yes');
        $familyMemberCount = count($familyMembers);
        $totalNeeded = $currentRSVPs + 1 + $guests + $familyMemberCount;

        if ($totalNeeded <= $event['capacity']) {
            return null;
        }
        if ($currentRSVPs >= $event['capacity']) {
            return 'Event is at full capacity';
        }
        $availableSpots = $event['capacity'] - $currentRSVPs;
        $neededForOthers = $guests + $familyMemberCount;
        if ($neededForOthers > ($availableSpots - 1)) {
            return "Only {$availableSpots} spot(s) available. Please reduce number of guests or family members.";
        }
        return 'Event is at full capacity';
    }

    /**
     * @return array{rsvp: array<string,mixed>, updated: bool}
     */
    private function upsertPrimaryRsvp(int $eventId, int $userId, int $guests, ?string $notes): array
    {
        $existing = $this->db->queryOne(
            "SELECT * FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
            ['event_id' => $eventId, 'user_id' => $userId]
        );

        if ($existing) {
            $updatePayload = [
                'status' => 'yes',
                'notes' => $notes
            ];
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM rsvps");
                if (in_array('guest_count', array_column($cols, 'Field'))) {
                    $updatePayload['guest_count'] = max(0, (int) $guests);
                }
            } catch (\Exception $e) { /* ignore */ }
            $this->db->update('rsvps', $existing['id'], $updatePayload);
            $rsvp = $this->db->queryOne(
                "SELECT * FROM rsvps WHERE id = :id",
                ['id' => $existing['id']]
            );
            return ['rsvp' => $rsvp, 'updated' => true];
        }

        $insertPayload = [
            'event_id' => $eventId,
            'user_id' => $userId,
            'status' => 'yes',
            'notes' => $notes
        ];
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM rsvps");
            if (in_array('guest_count', array_column($cols, 'Field'))) {
                $insertPayload['guest_count'] = max(0, (int) $guests);
            }
        } catch (\Exception $e) { /* ignore */ }
        $rsvpId = $this->db->insert('rsvps', $insertPayload);
        $rsvp = $this->db->queryOne(
            "SELECT * FROM rsvps WHERE id = :id",
            ['id' => $rsvpId]
        );
        return ['rsvp' => $rsvp, 'updated' => false];
    }

    /**
     * Create RSVPs for family members who have linked user accounts
     * 
     * @param int $eventId Event ID
     * @param array $familyMembers Array of family member records
     * @param int $parentUserId Parent user ID
     * @return array Created RSVPs
     */
    private function createFamilyMemberRSVPs($eventId, $familyMembers, $parentUserId)
    {
        $createdRSVPs = [];
        
        foreach ($familyMembers as $familyMember) {
            // Only create RSVP if family member has a linked user account
            if (!empty($familyMember['linked_user_id'])) {
                // Check if RSVP already exists for this family member
                $existing = $this->db->queryOne(
                    "SELECT * FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
                    [
                        'event_id' => $eventId,
                        'user_id' => $familyMember['linked_user_id']
                    ]
                );
                
                if (!$existing) {
                    // Create RSVP for family member
                    $familyRSVPId = $this->db->insert('rsvps', [
                        'event_id' => $eventId,
                        'user_id' => $familyMember['linked_user_id'],
                        'status' => 'yes',
                        'notes' => 'Family member of user ID: ' . $parentUserId
                    ]);
                    
                    $familyRSVP = $this->db->queryOne(
                        "SELECT * FROM rsvps WHERE id = :id",
                        ['id' => $familyRSVPId]
                    );
                    
                    $createdRSVPs[] = $familyRSVP;
                } else {
                    // Update existing RSVP
                    $this->db->update('rsvps', $existing['id'], [
                        'status' => 'yes'
                    ]);
                    $createdRSVPs[] = $existing;
                }
            }
        }
        
        return $createdRSVPs;
    }

    /**
     * Update RSVP
     * 
     * @param int $rsvpId RSVP ID
     * @param array $data Update data (status, notes, guests)
     * @return array Result with success status
     */
    public function updateRSVP($rsvpId, $data)
    {
        $rsvp = $this->db->queryOne(
            "SELECT * FROM rsvps WHERE id = :id",
            ['id' => $rsvpId]
        );

        if (!$rsvp) {
            return [
                'success' => false,
                'message' => 'RSVP not found'
            ];
        }

        $event = $this->db->queryOne(
            "SELECT * FROM events WHERE id = :id",
            ['id' => $rsvp['event_id']]
        );

        $willBeYes = isset($data['status'])
            ? ((string) $data['status'] === 'yes')
            : ((string) ($rsvp['status'] ?? '') === 'yes');
        if ($event && (string) ($event['status'] ?? '') === 'published' && $willBeYes) {
            if (!EventVisibilityService::memberMayRsvp($this->db, $event, (int) $rsvp['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'This event is not available for RSVP with your account.',
                ];
            }
        }
        if ($willBeYes) {
            $partyFmIds = [];
            if (array_key_exists('family_member_ids', $data) && is_array($data['family_member_ids'])) {
                $partyFmIds = array_values(array_unique(array_map('intval', $data['family_member_ids'])));
            } else {
                $partyFmIds = $this->loadFamilyMemberIdsForRsvp((int) $rsvpId);
            }
            $partyRows = $this->loadFamilyMemberRowsForUser($partyFmIds, (int) $rsvp['user_id']);
            if (count($partyRows) !== count($partyFmIds)) {
                return ['success' => false, 'message' => 'Invalid family member(s) selected'];
            }
            $profileUser = $this->db->queryOne(
                'SELECT id, first_name, last_name, date_of_birth, gender FROM users WHERE id = ? AND status != ?',
                [(int) $rsvp['user_id'], 'deleted']
            );
            if (is_array($event) && is_array($profileUser) && $this->eligibility->eventHasRestrictionRules($event)) {
                $chk = $this->eligibility->checkEligibility($event, $profileUser, null);
                if (!$chk['ok']) {
                    return ['success' => false, 'message' => $chk['message'] ?? 'You do not meet this event’s eligibility requirements.'];
                }
                foreach ($partyRows as $fmRow) {
                    $chkFm = $this->eligibility->checkEligibility($event, null, $fmRow);
                    if (!$chkFm['ok']) {
                        return ['success' => false, 'message' => $chkFm['message'] ?? 'A family member does not meet this event’s eligibility requirements.'];
                    }
                }
            }
        }

        if ($event && $this->isRegistrationDeadlinePassed($event)) {
            if (isset($data['status']) && $data['status'] === 'yes' && ($rsvp['status'] ?? '') !== 'yes') {
                return [
                    'success' => false,
                    'message' => 'Online RSVP is closed for this event.'
                ];
            }
            if (isset($data['guests'])) {
                $newG = max(0, (int) $data['guests']);
                $oldG = 0;
                if (!empty($rsvp['guest_count'])) {
                    $oldG = (int) $rsvp['guest_count'];
                } elseif (!empty($rsvp['notes']) && preg_match('/Guests:\s*(\d+)/', (string) $rsvp['notes'], $m)) {
                    $oldG = (int) $m[1];
                }
                if ($newG > $oldG) {
                    return [
                        'success' => false,
                        'message' => 'Online RSVP is closed — guest count can no longer be increased.'
                    ];
                }
            }
        }

        if (isset($data['status']) && $data['status'] === 'yes') {
            if ($event && !empty($event['capacity'])) {
                $currentRSVPs = $this->getRSVPCount($event['id'], 'yes');
                if ($rsvp['status'] !== 'yes') {
                    $currentRSVPs++;
                }
                if ($currentRSVPs > $event['capacity']) {
                    return [
                        'success' => false,
                        'message' => 'Event is at full capacity'
                    ];
                }
            }
        }
        if ($event && !empty($event['registration_required']) && !empty($event['capacity'])
            && (($rsvp['status'] ?? '') === 'yes' || (isset($data['status']) && $data['status'] === 'yes'))
            && (array_key_exists('family_member_ids', $data) || isset($data['guests']))) {
            $ids = array_key_exists('family_member_ids', $data) && is_array($data['family_member_ids'])
                ? array_values(array_unique(array_map('intval', $data['family_member_ids'])))
                : $this->loadFamilyMemberIdsForRsvp((int) $rsvpId);
            $partyForCap = $this->loadFamilyMemberRowsForUser($ids, (int) $rsvp['user_id']);
            if (count($partyForCap) !== count($ids)) {
                return ['success' => false, 'message' => 'Invalid family member(s) selected'];
            }
            $guestsForCap = isset($data['guests']) ? max(0, (int) $data['guests']) : $this->extractGuestCountFromRsvp($rsvp);
            $capParty = $this->validateCapacityForPartyUpdate($event, (int) $rsvpId, $guestsForCap, $partyForCap);
            if ($capParty !== null) {
                return ['success' => false, 'message' => $capParty];
            }
        }

        $updateData = [];
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }
        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
        }
        if (isset($data['guests'])) {
            $updateData['notes'] = "Guests: {$data['guests']}";
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM rsvps");
                if (in_array('guest_count', array_column($cols, 'Field'))) {
                    $updateData['guest_count'] = max(0, (int)$data['guests']);
                }
            } catch (\Exception $e) { /* ignore */ }
        }

        $this->db->update('rsvps', $rsvpId, $updateData);

        $finalStatus = isset($updateData['status']) ? (string) $updateData['status'] : (string) ($rsvp['status'] ?? '');
        if ($finalStatus === 'no') {
            $this->clearRsvpFamilyMemberLinks((int) $rsvpId);
            $this->downgradeLinkedFamilyRsvpsForEvent((int) $rsvp['event_id'], (int) $rsvp['user_id']);
        } elseif ($finalStatus === 'yes' && array_key_exists('family_member_ids', $data) && is_array($data['family_member_ids'])) {
            $fmIds = array_values(array_unique(array_map('intval', $data['family_member_ids'])));
            $partyRows = $this->loadFamilyMemberRowsForUser($fmIds, (int) $rsvp['user_id']);
            if (count($partyRows) !== count($fmIds)) {
                return ['success' => false, 'message' => 'Invalid family member(s) selected'];
            }
            $guestsN = isset($data['guests']) ? max(0, (int) $data['guests']) : $this->extractGuestCountFromRsvp($rsvp);
            $notesParts = [];
            if ($guestsN > 0) {
                $notesParts[] = "Guests: {$guestsN}";
            }
            if (!empty($partyRows)) {
                $familyNames = array_map(function ($fm) {
                    return trim($fm['first_name'] . ' ' . $fm['last_name']);
                }, $partyRows);
                $notesParts[] = 'Family members: ' . implode(', ', $familyNames);
            }
            $notesMerged = !empty($notesParts) ? implode('; ', $notesParts) : null;
            $mergePayload = ['notes' => $notesMerged];
            try {
                $cols = $this->db->query('SHOW COLUMNS FROM rsvps');
                if (in_array('guest_count', array_column($cols, 'Field'), true)) {
                    $mergePayload['guest_count'] = $guestsN;
                }
            } catch (\Exception $e) { /* ignore */ }
            $this->db->update('rsvps', $rsvpId, $mergePayload);
            $this->syncRsvpFamilyMemberLinks((int) $rsvpId, $fmIds);
            $this->createFamilyMemberRSVPs((int) $rsvp['event_id'], $partyRows, (int) $rsvp['user_id']);
        }

        return [
            'success' => true,
            'message' => 'RSVP updated successfully'
        ];
    }

    /**
     * Cancel RSVP
     * 
     * @param int $rsvpId RSVP ID
     * @return array Result with success status
     */
    public function cancelRSVP($rsvpId)
    {
        $rsvp = $this->db->queryOne(
            "SELECT * FROM rsvps WHERE id = :id",
            ['id' => $rsvpId]
        );

        if (!$rsvp) {
            return [
                'success' => false,
                'message' => 'RSVP not found'
            ];
        }

        // Update status to 'no'
        $this->db->update('rsvps', $rsvpId, ['status' => 'no']);

        $this->clearRsvpFamilyMemberLinks((int) $rsvpId);
        $this->downgradeLinkedFamilyRsvpsForEvent((int) ($rsvp['event_id'] ?? 0), (int) ($rsvp['user_id'] ?? 0));

        try {
            $event = $this->db->queryOne(
                'SELECT * FROM events WHERE id = ?',
                [(int) ($rsvp['event_id'] ?? 0)]
            );
            PotluckCategoryService::applyPotluckState(
                $this->db,
                is_array($event) ? $event : [],
                (int) $rsvpId,
                'no',
                null,
                false
            );
        } catch (\Throwable $e) {
            error_log('cancelRSVP potluck clear: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'RSVP cancelled successfully'
        ];
    }

    /**
     * Get all RSVPs for a member
     * 
     * @param int $userId User ID
     * @return array List of RSVPs with event details
     */
    public function getMemberRSVPs($userId)
    {
        $visCol = '';
        try {
            if ($this->db->hasColumn('events', 'visibility')) {
                $visCol = ', e.visibility as event_visibility';
            }
        } catch (\Throwable $e) {
            $visCol = '';
        }
        $sql = "SELECT r.*, e.title, e.event_date, e.start_time, e.location, e.ticket_price,
                       e.capacity, e.status as event_status{$visCol}
                FROM rsvps r
                JOIN events e ON r.event_id = e.id
                WHERE r.user_id = :user_id
                ORDER BY e.event_date DESC, e.start_time DESC";

        $rows = $this->db->query($sql, ['user_id' => $userId]) ?: [];
        if ($visCol === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $r): bool {
            $vis = EventVisibilityService::fromEventRow([
                'visibility' => $r['event_visibility'] ?? 'public',
            ]);

            return $vis !== EventVisibilityService::INTERNAL;
        }));
    }

    /**
     * Get RSVP count for an event
     * 
     * @param int $eventId Event ID
     * @param string $status RSVP status (yes, no, maybe)
     * @return int Count
     */
    public function getRSVPCount($eventId, $status = 'yes')
    {
        $sql = "SELECT COUNT(*) as count FROM rsvps 
                WHERE event_id = :event_id AND status = :status";
        
        $result = $this->db->queryOne($sql, [
            'event_id' => $eventId,
            'status' => $status
        ]);

        return (int)($result['count'] ?? 0);
    }

    /**
     * Get available spots for an event
     * 
     * @param int $eventId Event ID
     * @return int|null Available spots or null if unlimited
     */
    public function getAvailableSpots($eventId)
    {
        $event = $this->db->queryOne(
            "SELECT capacity FROM events WHERE id = :id",
            ['id' => $eventId]
        );

        if (!$event || empty($event['capacity'])) {
            return null; // Unlimited
        }

        $heads = $this->getRSVPYesHeadCount($eventId);
        $available = (int) $event['capacity'] - $heads;

        return max(0, $available);
    }

    /**
     * Total people with status yes (1 + guest_count per row).
     */
    public function getRSVPYesHeadCount($eventId): int
    {
        try {
            $row = $this->db->queryOne(
                "SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) AS c FROM rsvps WHERE event_id = :event_id AND status = 'yes'",
                ['event_id' => $eventId]
            );
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return $this->getRSVPCount($eventId, 'yes');
        }
    }

    /**
     * @param array<int,int> $familyMemberIds
     */
    private function syncRsvpFamilyMemberLinks(int $rsvpId, array $familyMemberIds): void
    {
        if (!$this->eligibility->rsvpFamilyMembersTableExists()) {
            return;
        }
        try {
            $this->db->execute('DELETE FROM rsvp_family_members WHERE rsvp_id = ?', [$rsvpId]);
            foreach (array_unique(array_map('intval', $familyMemberIds)) as $fmId) {
                if ($fmId <= 0) {
                    continue;
                }
                $this->db->insert('rsvp_family_members', [
                    'rsvp_id' => $rsvpId,
                    'family_member_id' => $fmId,
                ]);
            }
        } catch (\Exception $e) {
            error_log('syncRsvpFamilyMemberLinks: ' . $e->getMessage());
        }
    }

    private function clearRsvpFamilyMemberLinks(int $rsvpId): void
    {
        if (!$this->eligibility->rsvpFamilyMembersTableExists() || $rsvpId <= 0) {
            return;
        }
        try {
            $this->db->execute('DELETE FROM rsvp_family_members WHERE rsvp_id = ?', [$rsvpId]);
        } catch (\Exception $e) {
            error_log('clearRsvpFamilyMemberLinks: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int,int>
     */
    private function loadFamilyMemberIdsForRsvp(int $rsvpId): array
    {
        if (!$this->eligibility->rsvpFamilyMembersTableExists() || $rsvpId <= 0) {
            return [];
        }
        try {
            $rows = $this->db->query(
                'SELECT family_member_id FROM rsvp_family_members WHERE rsvp_id = ? ORDER BY id ASC',
                [$rsvpId]
            );
            return array_map('intval', array_column($rows, 'family_member_id'));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function loadFamilyMemberRowsForUser(array $ids, int $parentUserId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            return $this->db->query(
                "SELECT * FROM family_members WHERE id IN ($placeholders) AND parent_user_id = ?",
                array_merge($ids, [$parentUserId])
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    private function extractGuestCountFromRsvp(array $rsvp): int
    {
        if (!empty($rsvp['guest_count'])) {
            return max(0, (int) $rsvp['guest_count']);
        }
        if (!empty($rsvp['notes']) && preg_match('/Guests:\s*(\d+)/', (string) $rsvp['notes'], $m)) {
            return max(0, (int) $m[1]);
        }
        return 0;
    }

    /**
     * Headcount capacity when changing guests / party (excludes this RSVP, then adds new party size).
     */
    private function validateCapacityForPartyUpdate(array $event, int $rsvpId, int $newGuests, array $newFamilyRows): ?string
    {
        $eventId = (int) $event['id'];
        $cap = (int) $event['capacity'];
        $guestExpr = '0';
        try {
            $cols = $this->db->query('SHOW COLUMNS FROM rsvps');
            if (in_array('guest_count', array_column($cols, 'Field'), true)) {
                $guestExpr = 'COALESCE(r.guest_count, 0)';
            }
        } catch (\Exception $e) {
            // ignore
        }
        $junctionSql = '0';
        if ($this->eligibility->rsvpFamilyMembersTableExists()) {
            $junctionSql = 'COALESCE(rm.fm_ct, 0)';
        }
        try {
            if ($this->eligibility->rsvpFamilyMembersTableExists()) {
                $row = $this->db->queryOne(
                    "SELECT COALESCE(SUM(1 + {$guestExpr} + {$junctionSql}), 0) AS heads
                     FROM rsvps r
                     LEFT JOIN (SELECT rsvp_id, COUNT(*) AS fm_ct FROM rsvp_family_members GROUP BY rsvp_id) rm ON rm.rsvp_id = r.id
                     WHERE r.event_id = ? AND r.status = 'yes' AND r.id != ?",
                    [$eventId, $rsvpId]
                );
            } else {
                $row = $this->db->queryOne(
                    "SELECT COALESCE(SUM(1 + {$guestExpr}), 0) AS heads
                     FROM rsvps r
                     WHERE r.event_id = ? AND r.status = 'yes' AND r.id != ?",
                    [$eventId, $rsvpId]
                );
            }
        } catch (\Exception $e) {
            return null;
        }
        $others = (int) ($row['heads'] ?? 0);
        $newParty = 1 + max(0, $newGuests) + count($newFamilyRows);
        if ($others + $newParty > $cap) {
            return 'Event is at full capacity. Please reduce guests or family members.';
        }
        return null;
    }

    private function downgradeLinkedFamilyRsvpsForEvent(int $eventId, int $parentUserId): void
    {
        if ($eventId <= 0 || $parentUserId <= 0) {
            return;
        }
        try {
            $fms = $this->db->query(
                'SELECT linked_user_id FROM family_members WHERE parent_user_id = ? AND linked_user_id IS NOT NULL',
                [$parentUserId]
            );
            foreach ($fms as $row) {
                $uid = (int) ($row['linked_user_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $this->db->execute(
                    "UPDATE rsvps SET status = 'no' WHERE event_id = ? AND user_id = ? AND status = 'yes'",
                    [$eventId, $uid]
                );
            }
        } catch (\Exception $e) {
            error_log('downgradeLinkedFamilyRsvpsForEvent: ' . $e->getMessage());
        }
    }

    /**
     * Ensure user account is properly set up when RSVPing for the first time
     * 
     * @param int $userId User ID
     * @param int $organizationId Organization ID
     */
    private function ensureAccountSetup($userId, $organizationId)
    {
        // Check if this is the user's first RSVP
        $rsvpCount = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM rsvps WHERE user_id = :user_id",
            ['user_id' => $userId]
        );
        
        $isFirstRSVP = (int)($rsvpCount['count'] ?? 0) === 0;

        if ($isFirstRSVP) {
            // Get user data
            $user = $this->db->queryOne(
                "SELECT * FROM users WHERE id = :id",
                ['id' => $userId]
            );

            if ($user) {
                $updateData = [];

                // Set default email preferences if not set
                if (empty($user['email_preferences'])) {
                    $updateData['email_preferences'] = json_encode([
                        'event_announcements' => true,
                        'event_reminders' => true,
                        'rsvp_confirmations' => true,
                        'payment_receipts' => true
                    ]);
                }

                // Set default communication preferences if not set
                if (empty($user['communication_preferences'])) {
                    $updateData['communication_preferences'] = json_encode([
                        'email_enabled' => true,
                        'sms_enabled' => false
                    ]);
                }

                // Ensure user has QR code secret for check-in
                if (empty($user['qr_code_secret'])) {
                    $updateData['qr_code_secret'] = \Headcount\Helpers\Security::generateToken(32);
                }

                // Ensure user status is active
                if ($user['status'] !== 'active') {
                    $updateData['status'] = 'active';
                }

                // Ensure user role is member (if not admin)
                if ($user['role'] !== 'admin' && $user['role'] !== 'member') {
                    $updateData['role'] = 'member';
                }

                // Update user if there are changes
                if (!empty($updateData)) {
                    $this->db->update('users', $userId, $updateData);
                }
            }
        }
    }
}
