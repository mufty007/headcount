<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;

/**
 * Age / gender eligibility for events (RSVP and optional check-in enforcement).
 */
class EventEligibilityService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function eventHasRestrictionRules(array $event): bool
    {
        $min = isset($event['min_age']) ? $event['min_age'] : null;
        $max = isset($event['max_age']) ? $event['max_age'] : null;
        $gr = isset($event['gender_restriction']) ? trim((string) $event['gender_restriction']) : 'none';
        if ($gr === '') {
            $gr = 'none';
        }
        if ($min !== null && $min !== '' && (int) $min > 0) {
            return true;
        }
        if ($max !== null && $max !== '' && (int) $max > 0) {
            return true;
        }
        if ($gr !== '' && $gr !== 'none') {
            return true;
        }
        return false;
    }

    public function guestRequiresDateOfBirth(array $event): bool
    {
        $minAge = isset($event['min_age']) && $event['min_age'] !== '' ? (int) $event['min_age'] : 0;
        $maxAge = isset($event['max_age']) && $event['max_age'] !== '' ? (int) $event['max_age'] : 0;
        return $minAge > 0 || $maxAge > 0;
    }

    public function guestRequiresGender(array $event): bool
    {
        $genderRestriction = isset($event['gender_restriction']) ? trim((string) $event['gender_restriction']) : 'none';
        return $genderRestriction !== '' && $genderRestriction !== 'none';
    }

    /**
     * Normalize guest-submitted DOB to Y-m-d or null.
     */
    public function normalizeDateOfBirthInput($raw, ?array $input = null): ?string
    {
        if (is_array($input)) {
            $year = isset($input['dob_year']) ? (int) $input['dob_year'] : 0;
            $month = isset($input['dob_month']) ? (int) $input['dob_month'] : 0;
            $day = isset($input['dob_day']) ? (int) $input['dob_day'] : 0;
            if ($year > 0 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        try {
            $dt = new \DateTime($s);
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validate guest RSVP fields against event age/gender rules.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $input Expects date_of_birth, gender; optional first_name, last_name for messages
     * @return array{ok:bool,message:?string,date_of_birth:?string,gender:?string}
     */
    public function validateGuestSubmission(array $event, array $input): array
    {
        if (!$this->eventHasRestrictionRules($event)) {
            return ['ok' => true, 'message' => null, 'date_of_birth' => null, 'gender' => null];
        }

        $needsDob = $this->guestRequiresDateOfBirth($event);
        $needsGender = $this->guestRequiresGender($event);
        $dob = $this->normalizeDateOfBirthInput($input['date_of_birth'] ?? null, $input);
        $gender = isset($input['gender']) ? trim((string) $input['gender']) : '';
        if ($gender === '') {
            $gender = null;
        }

        if ($needsDob && $dob === null) {
            return [
                'ok' => false,
                'message' => 'Date of birth is required to verify age for this event.',
                'date_of_birth' => null,
                'gender' => $gender,
            ];
        }
        if ($needsGender && $gender === null) {
            return [
                'ok' => false,
                'message' => 'Gender is required for this event.',
                'date_of_birth' => $dob,
                'gender' => null,
            ];
        }
        if ($gender !== null && !in_array($gender, ['male', 'female', 'other'], true)) {
            return [
                'ok' => false,
                'message' => 'Please select a valid gender.',
                'date_of_birth' => $dob,
                'gender' => null,
            ];
        }

        $guestRow = [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name' => trim((string) ($input['last_name'] ?? '')),
            'date_of_birth' => $dob,
            'gender' => $gender,
        ];
        $chk = $this->checkEligibility($event, $guestRow, null);
        if (!$chk['ok']) {
            return [
                'ok' => false,
                'message' => $chk['message'] ?? 'You do not meet the requirements for this event.',
                'date_of_birth' => $dob,
                'gender' => $gender,
            ];
        }

        return ['ok' => true, 'message' => null, 'date_of_birth' => $dob, 'gender' => $gender];
    }

    /**
     * Save validated guest eligibility fields on the user record when columns exist.
     */
    public function persistGuestProfileFields(int $userId, ?string $dateOfBirth, ?string $gender): void
    {
        $patch = [];
        if ($dateOfBirth !== null && $this->db->hasColumn('users', 'date_of_birth')) {
            $patch['date_of_birth'] = $dateOfBirth;
        }
        if ($gender !== null && $this->db->hasColumn('users', 'gender')) {
            $patch['gender'] = $gender;
        }
        if ($patch !== []) {
            $this->db->update('users', $userId, $patch);
        }
    }

    /**
     * Age in full years at end of event_date in org timezone (date-only comparison).
     */
    public function ageAtEventDate(?string $dateOfBirthYmd, string $eventDateYmd, string $timezone): ?int
    {
        if ($dateOfBirthYmd === null || $dateOfBirthYmd === '') {
            return null;
        }
        $dob = substr(preg_replace('/\s.*$/', '', (string) $dateOfBirthYmd), 0, 10);
        $evt = substr((string) $eventDateYmd, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $evt)) {
            return null;
        }
        try {
            $tz = new \DateTimeZone($timezone);
            $birth = new \DateTime($dob . ' 00:00:00', $tz);
            $eventDay = new \DateTime($evt . ' 23:59:59', $tz);
            if ($birth > $eventDay) {
                return null;
            }
            return $birth->diff($eventDay)->y;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $event Must include event_date; optional min_age, max_age, gender_restriction
     * @param array<string,mixed>|null $userRow users row (date_of_birth, gender) or empty for anonymous
     * @param array<string,mixed>|null $familyRow family_members row with optional date_of_birth, gender
     * @return array{ok:bool,message:?string}
     */
    public function checkEligibility(array $event, ?array $userRow, ?array $familyRow = null): array
    {
        if (!$this->eventHasRestrictionRules($event)) {
            return ['ok' => true, 'message' => null];
        }
        $orgId = (int) ($event['organization_id'] ?? 0);
        $tz = OrgTimeZone::FALLBACK_IANA;
        if ($orgId > 0) {
            try {
                $org = $this->db->queryOne('SELECT timezone FROM organizations WHERE id = ?', [$orgId]);
                $tz = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
            } catch (\Exception $e) {
                // ignore
            }
        }
        $eventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);
        $minAge = isset($event['min_age']) && $event['min_age'] !== '' ? (int) $event['min_age'] : null;
        $maxAge = isset($event['max_age']) && $event['max_age'] !== '' ? (int) $event['max_age'] : null;
        $genderRestriction = isset($event['gender_restriction']) ? trim((string) $event['gender_restriction']) : 'none';
        if ($genderRestriction === '') {
            $genderRestriction = 'none';
        }

        $dob = null;
        $gender = null;
        $label = 'This person';
        if ($familyRow !== null) {
            $dob = $familyRow['date_of_birth'] ?? null;
            $gender = isset($familyRow['gender']) ? ($familyRow['gender'] !== '' ? (string) $familyRow['gender'] : null) : null;
            $label = trim(($familyRow['first_name'] ?? '') . ' ' . ($familyRow['last_name'] ?? '')) ?: 'Family member';
        } elseif ($userRow !== null) {
            $dob = $userRow['date_of_birth'] ?? null;
            $gender = isset($userRow['gender']) ? ($userRow['gender'] !== '' ? (string) $userRow['gender'] : null) : null;
            $label = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? '')) ?: 'You';
        }

        if ($minAge !== null && $minAge > 0 || $maxAge !== null && $maxAge > 0) {
            $age = $this->ageAtEventDate($dob !== null ? (string) $dob : null, $eventDate, $tz);
            if ($age === null) {
                return [
                    'ok' => false,
                    'message' => "{$label} needs a date of birth on file to register for this event (age-restricted).",
                ];
            }
            if ($minAge !== null && $minAge > 0 && $age < $minAge) {
                return [
                    'ok' => false,
                    'message' => "{$label} does not meet the minimum age for this event.",
                ];
            }
            if ($maxAge !== null && $maxAge > 0 && $age > $maxAge) {
                return [
                    'ok' => false,
                    'message' => "{$label} does not meet the maximum age for this event.",
                ];
            }
        }

        if ($genderRestriction !== '' && $genderRestriction !== 'none') {
            if ($gender === null || $gender === '') {
                return [
                    'ok' => false,
                    'message' => "{$label} needs gender on file to register for this event (gender-restricted).",
                ];
            }
            if ($gender !== $genderRestriction) {
                return [
                    'ok' => false,
                    'message' => "{$label} does not meet the gender requirement for this event.",
                ];
            }
        }

        return ['ok' => true, 'message' => null];
    }

    public function rsvpFamilyMembersTableExists(): bool
    {
        try {
            $r = $this->db->query("SHOW TABLES LIKE 'rsvp_family_members'");
            return !empty($r);
        } catch (\Exception $e) {
            return false;
        }
    }
}
