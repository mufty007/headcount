<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Headcount\Services\EventRequestService;

/**
 * Status-machine and draft-mapping tests (no database).
 */
class EventRequestServiceTest extends TestCase
{
    public function testValidateProposalRequiresTitleDescriptionAndDate(): void
    {
        $errors = EventRequestService::validateProposal([]);
        $this->assertNotEmpty($errors);

        $errors = EventRequestService::validateProposal([
            'title' => 'Community Iftar',
            'description' => 'Open iftar for families.',
            'event_date' => '2026-09-15',
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidateProposalRejectsNegativeBudget(): void
    {
        $errors = EventRequestService::validateProposal([
            'title' => 'Community Iftar',
            'description' => 'Open iftar for families.',
            'event_date' => '2026-09-15',
            'budget' => -10,
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testPendingCanBeSentBackApprovedDeclinedOrWithdrawn(): void
    {
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_PENDING, 'send_back'));
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_PENDING, 'approve'));
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_PENDING, 'decline'));
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_PENDING, 'withdraw'));
        $this->assertFalse(EventRequestService::canTransition(EventRequestService::STATUS_PENDING, 'resubmit'));
    }

    public function testChangesRequestedAllowsUpdateAndResubmitOnly(): void
    {
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_CHANGES_REQUESTED, 'update'));
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_CHANGES_REQUESTED, 'resubmit'));
        $this->assertTrue(EventRequestService::canTransition(EventRequestService::STATUS_CHANGES_REQUESTED, 'withdraw'));
        $this->assertFalse(EventRequestService::canTransition(EventRequestService::STATUS_CHANGES_REQUESTED, 'approve'));
    }

    public function testApprovedAndDeclinedAreTerminal(): void
    {
        $this->assertFalse(EventRequestService::canTransition(EventRequestService::STATUS_APPROVED, 'approve'));
        $this->assertFalse(EventRequestService::canTransition(EventRequestService::STATUS_DECLINED, 'send_back'));
        $this->expectException(\RuntimeException::class);
        EventRequestService::assertCanTransition(EventRequestService::STATUS_APPROVED, 'decline');
    }

    public function testBuildEventInsertCopiesProposalAndUsesTbdLocation(): void
    {
        $row = EventRequestService::buildEventInsert([
            'organization_id' => 3,
            'submitted_by' => 9,
            'title' => 'Youth Night',
            'description' => 'Games and dinner',
            'event_date' => '2026-10-01',
            'start_time' => '18:00:00',
            'end_time' => '21:00:00',
            'location' => '',
            'category' => 'youth',
            'target_audience' => 'High school students',
            'target_attendance' => 80,
            'budget' => 250.5,
        ], ['extra_details', 'target_attendance', 'budget']);

        $this->assertSame('draft', $row['status']);
        $this->assertSame('TBD', $row['location']);
        $this->assertSame(3, $row['organization_id']);
        $this->assertSame(9, $row['created_by']);
        $this->assertSame('Youth Night', $row['title']);
        $extra = (string) ($row['extra_details'] ?? $row['extra_details'] ?? '');
        $this->assertStringContainsString('High school students', $extra);
        $this->assertSame(80, $row['target_attendance']);
        $this->assertEquals(250.5, $row['budget']);
    }
}
