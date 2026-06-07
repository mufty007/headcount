<?php

namespace Headcount\Tests\Integration;

use Headcount\Tests\TestCase;
use Headcount\Controllers\AuthController;
use Headcount\Helpers\Database;

/**
 * Authentication Controller Integration Tests
 */
class AuthControllerTest extends TestCase
{
    private $controller;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->controller = new AuthController();
        
        // Create test user
        $this->testUserId = $this->createTestUser([
            'email' => 'testauth@example.com',
            'password' => \Headcount\Helpers\Security::hashPassword('Test123!@#'),
            'role' => 'admin'
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up
        if ($this->testUserId) {
            $this->db->execute("DELETE FROM users WHERE id = :id", ['id' => $this->testUserId]);
        }
        
        // Clear session
        $_SESSION = [];
        
        parent::tearDown();
    }

    public function testLoginWithValidCredentials()
    {
        $result = $this->controller->login(
            'testauth@example.com',
            'Test123!@#',
            false
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('Login successful', $result['message']);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals($this->testUserId, $result['data']['user_id']);
        $this->assertTrue(isset($_SESSION['user_id']));
    }

    public function testLoginWithInvalidCredentials()
    {
        $result = $this->controller->login(
            'testauth@example.com',
            'WrongPassword',
            false
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid', $result['message']);
        $this->assertFalse(isset($_SESSION['user_id']));
    }

    public function testLoginWithInvalidEmail()
    {
        $result = $this->controller->login(
            'invalid@example.com',
            'Test123!@#',
            false
        );

        $this->assertFalse($result['success']);
        $this->assertFalse(isset($_SESSION['user_id']));
    }

    public function testLogout()
    {
        // First login
        $this->controller->login('testauth@example.com', 'Test123!@#', false);
        $this->assertTrue(isset($_SESSION['user_id']));

        // Then logout
        $result = $this->controller->logout();
        
        $this->assertTrue($result['success']);
        $this->assertFalse(isset($_SESSION['user_id']));
    }
}
