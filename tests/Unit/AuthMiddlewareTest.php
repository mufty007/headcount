<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Headcount\Middleware\AuthMiddleware;

/**
 * AuthMiddleware unit tests for coordinator role support.
 * Uses PHPUnit base TestCase to avoid database connection (tests only session/role helpers).
 */
class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testIsAdminOrCoordinatorReturnsTrueForAdmin(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['organization_id'] = 1;
        $_SESSION['role'] = 'admin';
        $this->assertTrue(AuthMiddleware::isAdminOrCoordinator());
    }

    public function testIsAdminOrCoordinatorReturnsTrueForCoordinator(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['organization_id'] = 1;
        $_SESSION['role'] = 'coordinator';
        $this->assertTrue(AuthMiddleware::isAdminOrCoordinator());
    }

    public function testIsAdminOrCoordinatorReturnsFalseForMember(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['organization_id'] = 1;
        $_SESSION['role'] = 'member';
        $this->assertFalse(AuthMiddleware::isAdminOrCoordinator());
    }

    public function testIsAdminOrCoordinatorReturnsFalseWhenNoRole(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['organization_id'] = 1;
        unset($_SESSION['role']);
        $this->assertFalse(AuthMiddleware::isAdminOrCoordinator());
    }

    public function testIsAdminReturnsTrueOnlyForAdmin(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(AuthMiddleware::isAdmin());

        $_SESSION['role'] = 'coordinator';
        $this->assertFalse(AuthMiddleware::isAdmin());

        $_SESSION['role'] = 'member';
        $this->assertFalse(AuthMiddleware::isAdmin());
    }

    public function testGetRoleReturnsCoordinator(): void
    {
        $_SESSION['role'] = 'coordinator';
        $this->assertSame('coordinator', AuthMiddleware::getRole());
    }

    public function testAdminCanMaintainExistingEventWithoutManagePermission(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['organization_id'] = 1;
        $_SESSION['role'] = 'admin';
        $this->assertTrue(AuthMiddleware::canMaintainExistingEvent(1, 42));
        $this->assertFalse(AuthMiddleware::canMaintainExistingEvent(1, 0));
    }

    public function testCoordinatorCannotMaintainArbitraryExistingEvent(): void
    {
        $_SESSION['user_id'] = 2;
        $_SESSION['organization_id'] = 1;
        $_SESSION['role'] = 'coordinator';
        $this->assertFalse(AuthMiddleware::canMaintainExistingEvent(1, 42));
    }

    public function testAdminCanMaintainExistingProgram(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['organization_id'] = 1;
        $_SESSION['role'] = 'admin';
        $this->assertTrue(AuthMiddleware::canMaintainExistingProgram(1, 7));
    }
}
