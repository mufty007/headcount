<?php

namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;
use Headcount\Services\RememberTokenService;

class RememberTokenServiceTest extends TestCase
{
    private $tokenService;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = new RememberTokenService();
        
        // Create test user
        $this->testUserId = $this->createTestUser([
            'email' => 'remembertest@example.com',
            'first_name' => 'Remember',
            'last_name' => 'Test'
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->testUserId) {
            $this->tokenService->revokeAllUserTokens($this->testUserId);
            $this->db->execute("DELETE FROM users WHERE id = :id", ['id' => $this->testUserId]);
        }
        parent::tearDown();
    }

    public function testCreateToken()
    {
        $token = $this->tokenService->createToken($this->testUserId, 'admin');
        
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function testValidateToken()
    {
        $token = $this->tokenService->createToken($this->testUserId, 'admin');
        $userData = $this->tokenService->validateToken($token, 'admin');
        
        $this->assertNotNull($userData);
        $this->assertEquals($this->testUserId, $userData['user_id']);
    }

    public function testRevokeToken()
    {
        $token = $this->tokenService->createToken($this->testUserId, 'admin');
        $result = $this->tokenService->revokeToken($token);
        
        $this->assertTrue($result);
        
        // Token should no longer be valid
        $userData = $this->tokenService->validateToken($token, 'admin');
        $this->assertNull($userData);
    }
}
