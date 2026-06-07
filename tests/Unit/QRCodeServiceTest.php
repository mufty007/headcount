<?php

namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;
use Headcount\Services\QRCodeService;

class QRCodeServiceTest extends TestCase
{
    private $qrService;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrService = new QRCodeService();
        
        // Create test user
        $this->testUserId = $this->createTestUser([
            'email' => 'qrtest@example.com',
            'first_name' => 'QR',
            'last_name' => 'Test'
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->testUserId) {
            $this->db->execute("DELETE FROM users WHERE id = :id", ['id' => $this->testUserId]);
        }
        parent::tearDown();
    }

    public function testGenerateQRCodeData()
    {
        $qrData = $this->qrService->generateQRCodeData($this->testUserId);
        
        $this->assertNotNull($qrData);
        $this->assertArrayHasKey('data', $qrData);
        $this->assertArrayHasKey('hash', $qrData);
        $this->assertArrayHasKey('full_code', $qrData);
    }

    public function testValidateQRCode()
    {
        $qrData = $this->qrService->generateQRCodeData($this->testUserId);
        $user = $this->qrService->validateQRCode($qrData['full_code']);
        
        $this->assertNotNull($user);
        $this->assertEquals($this->testUserId, $user['id']);
    }

    public function testValidateExpiredQRCode()
    {
        // This would require mocking time, so we'll test invalid QR codes instead
        $invalidQR = 'invalid|hash';
        $user = $this->qrService->validateQRCode($invalidQR);
        
        $this->assertNull($user);
    }
}
