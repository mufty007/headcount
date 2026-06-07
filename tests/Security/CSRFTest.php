<?php

namespace Headcount\Tests\Security;

use Headcount\Tests\TestCase;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Security;

class CSRFTest extends TestCase
{
    public function testGenerateCSRFToken()
    {
        $_SESSION = [];
        $token = CsrfMiddleware::getToken();
        
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testVerifyCSRFToken()
    {
        $_SESSION = [];
        $token = CsrfMiddleware::getToken();
        
        $this->assertTrue(Security::verifyCSRFToken($token));
        $this->assertFalse(Security::verifyCSRFToken('invalid_token'));
    }

    public function testCSRFTokenUniqueness()
    {
        $_SESSION = [];
        $token1 = CsrfMiddleware::getToken();
        
        $_SESSION = [];
        $token2 = CsrfMiddleware::getToken();
        
        // Tokens should be different (very high probability)
        $this->assertNotEquals($token1, $token2);
    }
}
