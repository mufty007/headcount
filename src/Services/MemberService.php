<?php

namespace Headcount\Services;

use Headcount\Models\User;
use Headcount\Helpers\Validator;
use Headcount\Helpers\Utilities;
use Headcount\Services\ActivityLogger;
use Headcount\Middleware\AuthMiddleware;

/**
 * Member Service
 * Business logic for member management
 */
class MemberService
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    /**
     * Create member with validation
     */
    public function createMember($data)
    {
        // Capitalize names before validation
        if (isset($data['first_name'])) {
            $data['first_name'] = Utilities::capitalizeName($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $data['last_name'] = Utilities::capitalizeName($data['last_name']);
        }
        
        // Validate member data
        $errors = Validator::validateMember($data);
        if (!empty($errors)) {
            throw new \Exception('Validation failed', 400);
        }

        // Check for duplicate email
        if ($this->userModel->emailExists($data['email'], $data['organization_id'])) {
            throw new \Exception('Email already exists', 409);
        }

        // Note: Phone numbers are NOT unique - multiple members can share the same phone (e.g., family members)

        // Create member
        try {
            $member = $this->userModel->create($data);
        } catch (\PDOException $e) {
            // Re-throw PDO exceptions so they can be handled by the API endpoint
            throw $e;
        } catch (\Exception $e) {
            // Re-throw other exceptions
            throw $e;
        }
        
        // Assign default tags (don't fail if this errors)
        try {
            $this->assignDefaultTags($member['id'], $data['organization_id'], $data['gender'] ?? null);
        } catch (\Exception $e) {
            // Log but don't fail member creation
            error_log("Error assigning default tags: " . $e->getMessage());
        }
        
        // Log activity (don't fail if this errors)
        try {
            $userName = $member['first_name'] . ' ' . $member['last_name'];
            $userId = AuthMiddleware::getUserId();
            if ($userId) {
                $activityLogger = new ActivityLogger($data['organization_id'], $userId);
                $activityLogger->logUserCreated($member['id'], $userName);
            }
        } catch (\Exception $e) {
            // Log but don't fail member creation
            error_log("Error logging activity: " . $e->getMessage());
        }
        
        return $member;
    }
    
    /**
     * Assign default tags to a member
     */
    private function assignDefaultTags($memberId, $organizationId, $gender = null)
    {
        $db = \Headcount\Helpers\Database::getInstance();
        
        try {
            // Check if tags table exists by trying to query it
            try {
                $testQuery = $db->query("SELECT 1 FROM tags LIMIT 1");
            } catch (\Exception $e) {
                // Tags table doesn't exist, skip tag assignment
                error_log("Tags table doesn't exist, skipping tag assignment: " . $e->getMessage());
                return;
            }
            
            // Get or create "All Members" tag
            $allMembersTag = $db->queryOne(
                "SELECT id FROM tags WHERE name = :name AND organization_id = :org_id",
                ['name' => 'All Members', 'org_id' => $organizationId]
            );
            
            if (!$allMembersTag) {
                // Create "All Members" tag
                $allMembersTagId = $db->insert('tags', [
                    'organization_id' => $organizationId,
                    'name' => 'All Members',
                    'color' => '#3B82F6'
                ]);
            } else {
                $allMembersTagId = $allMembersTag['id'];
            }
            
            // Assign "All Members" tag
            try {
                $db->insert('member_tags', [
                    'user_id' => $memberId,
                    'tag_id' => $allMembersTagId
                ]);
            } catch (\Exception $e) {
                // Tag might already be assigned, ignore
                error_log("Tag already assigned or error: " . $e->getMessage());
            }
            
            // Assign gender-based tag if gender is provided
            if ($gender) {
                $genderTagName = '';
                $genderLower = strtolower(trim($gender));
                if ($genderLower === 'female' || $genderLower === 'f') {
                    $genderTagName = 'Female Member';
                } elseif ($genderLower === 'male' || $genderLower === 'm') {
                    $genderTagName = 'Male Member';
                }
                
                if ($genderTagName) {
                    // Get or create gender tag
                    $genderTag = $db->queryOne(
                        "SELECT id FROM tags WHERE name = :name AND organization_id = :org_id",
                        ['name' => $genderTagName, 'org_id' => $organizationId]
                    );
                    
                    if (!$genderTag) {
                        // Create gender tag
                        $genderTagId = $db->insert('tags', [
                            'organization_id' => $organizationId,
                            'name' => $genderTagName,
                            'color' => ($genderLower === 'female' || $genderLower === 'f') ? '#EC4899' : '#3B82F6'
                        ]);
                    } else {
                        $genderTagId = $genderTag['id'];
                    }
                    
                    // Assign gender tag
                    try {
                        $db->insert('member_tags', [
                            'user_id' => $memberId,
                            'tag_id' => $genderTagId
                        ]);
                    } catch (\Exception $e) {
                        // Tag might already be assigned, ignore
                        error_log("Gender tag already assigned or error: " . $e->getMessage());
                    }
                }
            }
        } catch (\PDOException $e) {
            // Database error - tags table might not exist or column mismatch
            error_log("Database error assigning default tags: " . $e->getMessage());
        } catch (\Exception $e) {
            // If tags table doesn't exist or there's an error, log it but don't fail member creation
            error_log("Error assigning default tags to member: " . $e->getMessage());
        }
    }

    /**
     * Update member with validation
     */
    public function updateMember($id, $data)
    {
        // Check if member exists
        $existingMember = $this->userModel->find($id);
        if (!$existingMember) {
            throw new \Exception('Member not found', 404);
        }

        // Capitalize names if provided
        if (isset($data['first_name'])) {
            $data['first_name'] = Utilities::capitalizeName($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $data['last_name'] = Utilities::capitalizeName($data['last_name']);
        }

        // Validate member data
        $errors = Validator::validateMember(array_merge($existingMember, $data));
        if (!empty($errors)) {
            throw new \Exception('Validation failed', 400);
        }

        // Check for duplicate email (excluding current member)
        if (isset($data['email']) && $this->userModel->emailExists($data['email'], $existingMember['organization_id'], $id)) {
            throw new \Exception('Email already exists', 409);
        }

        // Track changes for activity log
        $changes = [];
        foreach ($data as $key => $value) {
            if (isset($existingMember[$key]) && $existingMember[$key] != $value) {
                $changes[$key] = ['old' => $existingMember[$key], 'new' => $value];
            }
        }
        
        // Update member
        $member = $this->userModel->update($id, $data);
        
        // Log activity
        if (!empty($changes)) {
            $userName = $member['first_name'] . ' ' . $member['last_name'];
            $userId = AuthMiddleware::getUserId();
            $activityLogger = new ActivityLogger($member['organization_id'], $userId);
            $activityLogger->logUserUpdated($id, $userName, $changes);
        }
        
        return $member;
    }

    /**
     * Get member by ID
     */
    public function getMember($id)
    {
        $member = $this->userModel->find($id);
        if (!$member) {
            throw new \Exception('Member not found', 404);
        }
        return $member;
    }

    /**
     * Search members
     */
    public function searchMembers($organizationId, $query, $limit = 10)
    {
        if (strlen($query) < 2) {
            return [];
        }
        return $this->userModel->search($organizationId, $query, $limit);
    }

    /**
     * Get all members with filters
     */
    public function getMembers($organizationId, $filters = [], $page = 1, $perPage = 50)
    {
        $offset = ($page - 1) * $perPage;
        $members = $this->userModel->getAll($organizationId, $filters, $perPage, $offset);
        $total = $this->userModel->count($organizationId, $filters);

        return [
            'members' => $members,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    public function deleteMember($id)
    {
        $member = $this->userModel->find($id);
        if (!$member) {
            throw new \Exception('Member not found', 404);
        }

        // Hard delete the member
        return $this->userModel->delete($id);
    }

    /**
     * Get statistics for a member
     */
    public function getMemberStats($memberId)
    {
        $db = \Headcount\Helpers\Database::getInstance();
        
        // Get member info for email status check
        $member = $this->userModel->find($memberId);
        
        // Events attended (with checked_in_at)
        try {
            $attendance = $db->query("
                SELECT a.*, e.title as event_title, e.event_date, e.start_time, e.location
                FROM attendance a
                JOIN events e ON a.event_id = e.id
                WHERE a.user_id = :user_id
                ORDER BY e.event_date DESC, e.start_time DESC
            ", ['user_id' => $memberId]);
        } catch (\Exception $e) {
            error_log("Attendance query failed: " . $e->getMessage());
            $attendance = [];
        }

        // All RSVPs
        try {
            $rsvps = $db->query("
                SELECT r.*, e.title as event_title, e.event_date, e.start_time
                FROM rsvps r
                JOIN events e ON r.event_id = e.id
                WHERE r.user_id = :user_id
                ORDER BY e.event_date DESC, r.created_at DESC
            ", ['user_id' => $memberId]);
        } catch (\Exception $e) {
            error_log("RSVPs query failed: " . $e->getMessage());
            $rsvps = [];
        }

        // RSVPs with status 'yes' (signed up)
        try {
            $rsvpsYes = $db->query("
                SELECT r.*, e.title as event_title, e.event_date, e.start_time
                FROM rsvps r
                JOIN events e ON r.event_id = e.id
                WHERE r.user_id = :user_id AND r.status = 'yes'
                ORDER BY e.event_date DESC
            ", ['user_id' => $memberId]);
        } catch (\Exception $e) {
            error_log("RSVPs Yes query failed: " . $e->getMessage());
            $rsvpsYes = [];
        }

        // No-shows: RSVP yes but no attendance
        try {
            $noShows = $db->query("
                SELECT r.*, e.title as event_title, e.event_date, e.start_time, r.created_at as rsvp_date
                FROM rsvps r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN attendance a ON r.event_id = a.event_id AND r.user_id = a.user_id
                WHERE r.user_id = :user_id AND r.status = 'yes' AND a.id IS NULL
                ORDER BY e.event_date DESC
            ", ['user_id' => $memberId]);
        } catch (\Exception $e) {
            error_log("No-shows query failed: " . $e->getMessage());
            $noShows = [];
        }

        // Calculate attendance rate
        $totalSignedUp = count($rsvpsYes);
        $totalAttended = count($attendance);
        $attendedRsvps = 0;
        
        // Count how many RSVP yes events were actually attended
        foreach ($rsvpsYes as $rsvp) {
            foreach ($attendance as $att) {
                if ($att['event_id'] == $rsvp['event_id']) {
                    $attendedRsvps++;
                    break;
                }
            }
        }
        
        $attendanceRate = $totalSignedUp > 0 ? round(($attendedRsvps / $totalSignedUp) * 100) : 0;

        // Email status check (with error handling in case email_logs table doesn't exist)
        $emailStatus = 'no_email';
        $emailStatusText = 'No email on file';
        $emailStatusClass = 'bg-gray-50 text-gray-700 border-gray-100';
        
        if (!empty($member['email'])) {
            try {
                // Check if email_logs table exists
                $tableCheck = $db->query("SHOW TABLES LIKE 'email_logs'");
                $emailLogsExists = !empty($tableCheck);
                
                if ($emailLogsExists) {
                    // Check if emails have been sent to this member recently (last 90 days)
                    $recentEmails = $db->queryOne("
                        SELECT COUNT(*) as count 
                        FROM email_logs 
                        WHERE recipient_user_id = :user_id 
                        AND status = 'sent' 
                        AND sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    ", ['user_id' => $memberId]);
                    
                    $emailCount = (int)($recentEmails['count'] ?? 0);
                    
                    if ($emailCount > 0) {
                        $emailStatus = 'receiving';
                        $emailStatusText = 'Receiving emails (' . $emailCount . ' sent in last 90 days)';
                        $emailStatusClass = 'bg-emerald-50 text-emerald-700 border-emerald-100';
                    } else {
                        // Check if any emails were ever sent
                        $totalEmails = $db->queryOne("
                            SELECT COUNT(*) as count 
                            FROM email_logs 
                            WHERE recipient_user_id = :user_id 
                            AND status = 'sent'
                        ", ['user_id' => $memberId]);
                        
                        $totalEmailCount = (int)($totalEmails['count'] ?? 0);
                        
                        if ($totalEmailCount > 0) {
                            $emailStatus = 'not_receiving_recent';
                            $emailStatusText = 'Has email but no recent sends';
                            $emailStatusClass = 'bg-amber-50 text-amber-700 border-amber-100';
                        } else {
                            $emailStatus = 'has_email';
                            $emailStatusText = 'Has email address';
                            $emailStatusClass = 'bg-blue-50 text-blue-700 border-blue-100';
                        }
                    }
                } else {
                    // Table doesn't exist, just show they have an email
                    $emailStatus = 'has_email';
                    $emailStatusText = 'Has email address';
                    $emailStatusClass = 'bg-blue-50 text-blue-700 border-blue-100';
                }
            } catch (\Exception $e) {
                // If query fails, just show they have an email
                error_log("Email status check failed: " . $e->getMessage());
                $emailStatus = 'has_email';
                $emailStatusText = 'Has email address';
                $emailStatusClass = 'bg-blue-50 text-blue-700 border-blue-100';
            }
        }

        // Get last attendance
        $lastAttendance = !empty($attendance) ? $attendance[0] : null;
        
        // Get last RSVP
        $lastRsvp = !empty($rsvps) ? $rsvps[0] : null;

        // General stats
        $stats = [
            'total_attended' => $totalAttended,
            'total_attendance' => $totalAttended, // Keep for backward compatibility
            'total_signed_up' => $totalSignedUp,
            'total_rsvps' => count($rsvps),
            'no_shows' => count($noShows),
            'attendance_rate' => $attendanceRate,
            'email_status' => $emailStatus,
            'email_status_text' => $emailStatusText,
            'email_status_class' => $emailStatusClass,
            'last_attendance' => $lastAttendance,
            'last_rsvp' => $lastRsvp,
            'recent_attendance' => array_slice($attendance, 0, 15),
            'recent_rsvps' => array_slice($rsvps, 0, 15),
            'no_show_events' => $noShows
        ];

        return $stats;
    }

    /**
     * Generate credentials for a member
     * Creates a random password and sets it for the member
     * 
     * @param int $memberId Member ID
     * @return array Result with generated password
     */
    public function generateCredentials($memberId)
    {
        $member = $this->userModel->find($memberId);
        
        if (!$member) {
            throw new \Exception('Member not found', 404);
        }

        // Check if member has email (required for login)
        if (empty($member['email'])) {
            throw new \Exception('Member must have an email address to generate credentials', 400);
        }

        // Generate a secure random password
        // Format: 12 characters with uppercase, lowercase, numbers
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // Exclude I and O to avoid confusion
        $lowercase = 'abcdefghijkmnpqrstuvwxyz'; // Exclude l and o
        $numbers = '23456789'; // Exclude 0 and 1
        $all = $uppercase . $lowercase . $numbers;
        
        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        
        // Fill the rest randomly
        for ($i = 3; $i < 12; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        
        // Shuffle the password
        $password = str_shuffle($password);

        // Hash and save the password
        $passwordHash = \Headcount\Helpers\Security::hashPassword($password);
        
        // Prepare update data - ensure user can login
        $updateData = [
            'password_hash' => $passwordHash
        ];
        
        // Ensure user status is active (required for login)
        if ($member['status'] !== 'active') {
            $updateData['status'] = 'active';
        }
        
        // Ensure user role is member (required for portal login)
        if ($member['role'] !== 'member' && $member['role'] !== 'admin') {
            $updateData['role'] = 'member';
        }
        
        // Reset any account locks
        $updateData['failed_login_attempts'] = 0;
        $updateData['locked_until'] = null;
        
        $this->userModel->update($memberId, $updateData);

        // Log activity
        try {
            $userName = $member['first_name'] . ' ' . $member['last_name'];
            $userId = AuthMiddleware::getUserId();
            if ($userId) {
                $activityLogger = new ActivityLogger($member['organization_id'], $userId);
                $activityLogger->log(
                    'user_credentials_generated',
                    "Credentials generated for: {$userName}",
                    'user',
                    $memberId,
                    [
                        'target_user_id' => $memberId,
                        'target_user_name' => $userName
                    ]
                );
            }
        } catch (\Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }

        return [
            'success' => true,
            'password' => $password,
            'email' => $member['email'],
            'member_name' => $member['first_name'] . ' ' . $member['last_name']
        ];
    }

    /**
     * Import members from CSV
     */
    public function importMembers($organizationId, $filePath, $mapping, $options = [])
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \Exception('Failed to open CSV file', 500);
        }

        $results = [
            'success' => 0,
            'failed' => 0,
            'duplicates' => 0,
            'errors' => []
        ];

        $rowNumber = 0;
        $headers = null;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // First row is headers
            if ($headers === null) {
                $headers = $row;
                continue;
            }

            // Map CSV columns to database fields
            $memberData = [
                'organization_id' => $organizationId,
                'role' => 'member',
                'status' => 'active',
            ];

            // If mapping is provided, use it; otherwise auto-detect from headers
            if (!empty($mapping)) {
                foreach ($mapping as $dbField => $csvColumn) {
                    $columnIndex = array_search($csvColumn, $headers);
                    if ($columnIndex !== false && isset($row[$columnIndex])) {
                        $value = trim($row[$columnIndex]);
                        // Capitalize names
                        if ($dbField === 'first_name' || $dbField === 'last_name') {
                            $value = Utilities::capitalizeName($value);
                        }
                        $memberData[$dbField] = $value;
                    }
                }
            } else {
                // Auto-map based on header names (case-insensitive)
                $headerMap = array_map('strtolower', $headers);
                foreach ($row as $index => $value) {
                    $headerName = strtolower($headers[$index] ?? '');
                    if ($headerName === 'first_name' || $headerName === 'firstname') {
                        $memberData['first_name'] = Utilities::capitalizeName(trim($value));
                    } elseif ($headerName === 'last_name' || $headerName === 'lastname') {
                        $memberData['last_name'] = Utilities::capitalizeName(trim($value));
                    } elseif ($headerName === 'email') {
                        $memberData['email'] = trim($value);
                        // Remove any leading/trailing spaces that might have been in CSV
                        $memberData['email'] = preg_replace('/\s+/', '', $memberData['email']);
                    } elseif ($headerName === 'phone') {
                        $memberData['phone'] = trim($value);
                    } elseif ($headerName === 'gender') {
                        $memberData['gender'] = trim(strtolower($value));
                    }
                }
            }

            // Validate required fields
            if (empty($memberData['email']) || empty($memberData['first_name']) || empty($memberData['last_name'])) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'message' => 'Missing required fields (email, first_name, last_name)'
                ];
                continue;
            }

            // Validate email
            if (!Validator::email($memberData['email'])) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'message' => 'Invalid email format: ' . $memberData['email']
                ];
                continue;
            }

            // Check for duplicate
            $existing = $this->userModel->findByEmail($memberData['email'], $organizationId);
            
            if ($existing) {
                if (isset($options['duplicate_action']) && $options['duplicate_action'] === 'update') {
                    try {
                        $this->updateMember($existing['id'], $memberData);
                        $results['success']++;
                    } catch (\Exception $e) {
                        $results['failed']++;
                        $results['errors'][] = [
                            'row' => $rowNumber,
                            'message' => 'Failed to update: ' . $e->getMessage()
                        ];
                    }
                    continue;
                } else {
                    $results['duplicates']++;
                    if (!isset($options['duplicate_action']) || $options['duplicate_action'] !== 'skip') {
                        $results['errors'][] = [
                            'row' => $rowNumber,
                            'message' => 'Duplicate email: ' . $memberData['email']
                        ];
                    }
                    continue;
                }
            }

            // Create member
            try {
                $this->createMember($memberData);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage()
                ];
            }
        }

        fclose($handle);
        return $results;
    }
}
