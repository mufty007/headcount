<?php

namespace Headcount\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Headcount\Helpers\Database;

/**
 * Base Test Case
 * Provides common functionality for all tests
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected $db;
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Load test configuration
        $this->config = $GLOBALS['test_config'] ?? require BASE_PATH . '/config/config-sample.php';
        
        // Initialize database connection
        if (isset($this->config['database'])) {
            $this->db = Database::getInstance($this->config['database']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        if ($this->db) {
            // Rollback any transactions
            try {
                $this->db->rollBack();
            } catch (\Exception $e) {
                // Ignore if no transaction
            }
        }
        
        parent::tearDown();
    }

    /**
     * Get test database instance
     */
    protected function getTestDatabase()
    {
        return $this->db;
    }

    /**
     * Create test user
     */
    protected function createTestUser($data = [])
    {
        $defaultData = [
            'organization_id' => 1,
            'email' => 'test@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => password_hash('Test123!@#', PASSWORD_BCRYPT),
            'role' => 'admin',
            'status' => 'active'
        ];

        $userData = array_merge($defaultData, $data);
        
        return $this->db->insert('users', $userData);
    }

    /**
     * Create test event
     */
    protected function createTestEvent($data = [])
    {
        $defaultData = [
            'organization_id' => 1,
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => date('Y-m-d', strtotime('+1 week')),
            'location' => 'Test Location',
            'category' => 'Test',
            'status' => 'published',
            'is_paid' => false,
            'price' => 0
        ];

        $eventData = array_merge($defaultData, $data);
        
        return $this->db->insert('events', $eventData);
    }

    /**
     * Clean up test data
     */
    protected function cleanupTestData()
    {
        if ($this->db) {
            // Delete test data in reverse order of dependencies
            $this->db->execute("DELETE FROM attendance WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'test%@example.com')");
            $this->db->execute("DELETE FROM rsvps WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'test%@example.com')");
            $this->db->execute("DELETE FROM payments WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'test%@example.com')");
            $this->db->execute("DELETE FROM events WHERE title LIKE 'Test%'");
            $this->db->execute("DELETE FROM users WHERE email LIKE 'test%@example.com'");
        }
    }
}
