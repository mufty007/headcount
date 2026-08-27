<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Headcount\Helpers\Permissions;
use Headcount\Services\OwnerService;

class PermissionsTest extends TestCase
{
    public function testAdminsCannotManageOrApproveEventsByDefault(): void
    {
        $this->assertFalse(Permissions::roleDefault('admin', 'events.manage'));
        $this->assertTrue(Permissions::roleDefault('admin', 'events.request'));
        $this->assertFalse(Permissions::roleDefault('admin', 'events.approve_requests'));
    }

    public function testAdminsCannotManageOrApproveProgramsByDefault(): void
    {
        $this->assertFalse(Permissions::roleDefault('admin', 'programs.manage'));
        $this->assertTrue(Permissions::roleDefault('admin', 'programs.request'));
        $this->assertFalse(Permissions::roleDefault('admin', 'programs.approve_requests'));
    }

    public function testCoordinatorsCanRequestButNotManage(): void
    {
        $this->assertTrue(Permissions::roleDefault('coordinator', 'events.request'));
        $this->assertTrue(Permissions::roleDefault('coordinator', 'programs.request'));
        $this->assertFalse(Permissions::roleDefault('coordinator', 'events.manage'));
        $this->assertFalse(Permissions::roleDefault('coordinator', 'programs.manage'));
        $this->assertFalse(Permissions::roleDefault('coordinator', 'events.approve_requests'));
    }

    public function testApproveKeysAreOwnerApproverOnly(): void
    {
        $this->assertTrue(Permissions::isOwnerApproverKey('events.approve_requests'));
        $this->assertTrue(Permissions::isOwnerApproverKey('programs.approve_requests'));
        $this->assertFalse(Permissions::isOwnerApproverKey('events.manage'));
    }

    public function testOwnerCapIsThree(): void
    {
        $this->assertSame(3, OwnerService::MAX_OWNERS);
    }
}
