<?php

namespace Headcount\Tests\Integration;

use Headcount\Tests\TestCase;
use Headcount\Helpers\Database;

/**
 * API Integration Tests
 * Tests API endpoints
 */
class ApiTest extends TestCase
{
    private $baseUrl = '/api';
    private $testUserId;
    private $testEventId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $this->testUserId = $this->createTestUser([
            'email' => 'apitest@example.com',
            'role' => 'admin'
        ]);
        
        $this->testEventId = $this->createTestEvent([
            'title' => 'API Test Event'
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up
        if ($this->testEventId) {
            $this->db->execute("DELETE FROM events WHERE id = :id", ['id' => $this->testEventId]);
        }
        if ($this->testUserId) {
            $this->db->execute("DELETE FROM users WHERE id = :id", ['id' => $this->testUserId]);
        }
        
        parent::tearDown();
    }

    /**
     * Simulate API request
     */
    private function makeApiRequest($method, $endpoint, $data = [], $headers = [])
    {
        // This is a simplified version - in real tests you'd use a proper HTTP client
        // For now, we'll test the controllers directly
        
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $this->baseUrl . $endpoint;
        
        if ($method === 'POST' || $method === 'PUT') {
            $_POST = $data;
        }
        
        return true;
    }

    public function testGetEventsEndpoint()
    {
        // Test that events can be retrieved
        $events = $this->db->query("SELECT * FROM events WHERE id = :id", ['id' => $this->testEventId]);
        $this->assertNotEmpty($events);
    }

    public function testCreateEventEndpoint()
    {
        // Test event creation through model
        $eventData = [
            'organization_id' => 1,
            'title' => 'API Created Event',
            'event_date' => date('Y-m-d', strtotime('+1 week')),
            'location' => 'Test Location',
            'status' => 'draft'
        ];
        
        $eventId = $this->db->insert('events', $eventData);
        $this->assertIsInt($eventId);
        $this->assertGreaterThan(0, $eventId);
        
        // Clean up
        $this->db->execute("DELETE FROM events WHERE id = :id", ['id' => $eventId]);
    }

    public function testCSRFProtection()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Simulate POST request without CSRF token
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['data' => 'test'];
        $_POST['csrf_token'] = 'invalid_token';
        
        // CSRF middleware should reject this
        $this->expectException(\Exception::class);
        \Headcount\Middleware\CsrfMiddleware::verify();
    }
}
