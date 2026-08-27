<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Headcount\Services\ProgramRequestService;

/**
 * Status-machine and draft-mapping tests (no database).
 */
class ProgramRequestServiceTest extends TestCase
{
    public function testValidateProposalRequiresTitleDescriptionAndDate(): void
    {
        $errors = ProgramRequestService::validateProposal([]);
        $this->assertNotEmpty($errors);

        $errors = ProgramRequestService::validateProposal([
            'title' => 'Youth Halaqah',
            'description' => 'Weekly circles for teens.',
            'starts_on' => '2026-09-15',
        ]);
        $this->assertSame([], $errors);
    }

    public function testPendingCanBeSentBackApprovedDeclinedOrWithdrawn(): void
    {
        $this->assertTrue(ProgramRequestService::canTransition(ProgramRequestService::STATUS_PENDING, 'send_back'));
        $this->assertTrue(ProgramRequestService::canTransition(ProgramRequestService::STATUS_PENDING, 'approve'));
        $this->assertTrue(ProgramRequestService::canTransition(ProgramRequestService::STATUS_PENDING, 'decline'));
        $this->assertTrue(ProgramRequestService::canTransition(ProgramRequestService::STATUS_PENDING, 'withdraw'));
        $this->assertFalse(ProgramRequestService::canTransition(ProgramRequestService::STATUS_PENDING, 'resubmit'));
    }

    public function testApprovedIsTerminal(): void
    {
        $this->assertFalse(ProgramRequestService::canTransition(ProgramRequestService::STATUS_APPROVED, 'approve'));
        $this->assertFalse(ProgramRequestService::canTransition(ProgramRequestService::STATUS_APPROVED, 'send_back'));
    }

    public function testBuildProgramInsertCreatesDraft(): void
    {
        $row = ProgramRequestService::buildProgramInsert([
            'organization_id' => 1,
            'submitted_by' => 9,
            'title' => 'Youth Halaqah',
            'description' => 'Weekly circles for teens.',
            'starts_on' => '2026-09-15',
            'session_start_time' => '19:00',
            'session_end_time' => '20:30',
            'location' => '',
        ], ['starts_on', 'session_start_time', 'session_end_time']);

        $this->assertSame('draft', $row['status']);
        $this->assertSame('TBD', $row['location']);
        $this->assertSame('2026-09-15', $row['starts_on']);
        $this->assertSame(9, $row['created_by']);
    }
}
