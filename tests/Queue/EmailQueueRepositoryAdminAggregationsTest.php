<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Tests\Support\DatabaseTestCase;

final class EmailQueueRepositoryAdminAggregationsTest extends DatabaseTestCase
{
    public function testReturnsGlobalStatusCountsByClientAndBacklog(): void
    {
        $oldest = $this->insertQueueRow([
            'id' => 'oldest-global',
            'source_app' => 'app-b',
            'status' => 'pending',
            'created_at' => '2026-01-01 08:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'retry-global',
            'source_app' => 'app-a',
            'status' => 'retry',
            'next_attempt_at' => '2099-01-01 00:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'failed-global',
            'source_app' => 'app-a',
            'status' => 'failed',
        ]);
        $this->insertQueueRow([
            'id' => 'sent-global',
            'source_app' => 'app-a',
            'status' => 'sent',
            'sent_at' => '2026-01-01 10:05:00',
        ]);

        $counts = $this->repository->globalStatusCounts();
        $bySource = $this->repository->statusCountsBySourceApp();
        $oldestRow = $this->repository->oldestUnsentGlobal();

        self::assertSame(1, $counts['pending']);
        self::assertSame(1, $counts['retry']);
        self::assertSame(1, $counts['failed']);
        self::assertSame(1, $counts['sent']);
        self::assertSame($oldest, $oldestRow['id']);
        self::assertSame('2099-01-01 00:00:00', $this->repository->nextDelayedAttemptGlobal('2026-01-01 00:00:00'));
        self::assertSame('app-a', $bySource[0]['sourceApp']);
        self::assertSame(1, $bySource[0]['statusCounts']['retry']);
        self::assertSame('app-b', $bySource[1]['sourceApp']);
        self::assertSame(1, $bySource[1]['statusCounts']['pending']);
    }

    public function testReturnsTechnicalBlockerStaleProcessingAndLimitedUnsentRows(): void
    {
        $this->insertQueueRow([
            'id' => 'technical-blocker',
            'source_app' => 'app-b',
            'priority' => 'technical',
            'status' => 'retry',
            'created_at' => '2026-01-01 08:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'stale-processing',
            'status' => 'processing',
            'lease_expires_at' => '2026-01-01 09:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'sent-ignored',
            'status' => 'sent',
            'sent_at' => '2026-01-01 10:05:00',
        ]);

        $blocker = $this->repository->technicalBlockerGlobal();
        $unsent = $this->repository->findUnsentGlobal(1);

        self::assertSame('technical-blocker', $blocker['id']);
        self::assertSame(1, $this->repository->staleProcessingCount('2026-01-01 10:00:00'));
        self::assertCount(1, $unsent);
        self::assertSame('technical-blocker', $unsent[0]['id']);
    }

    public function testSentCountSinceCountsOnlySentEmailsAfterCutoff(): void
    {
        $this->insertQueueRow([
            'id' => 'sent-recent',
            'status' => 'sent',
            'sent_at' => '2026-01-01 10:30:00',
        ]);
        $this->insertQueueRow([
            'id' => 'sent-old',
            'status' => 'sent',
            'sent_at' => '2026-01-01 09:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'pending-ignored',
            'status' => 'pending',
        ]);

        self::assertSame(1, $this->repository->sentCountSince('2026-01-01 10:00:00'));
    }

    public function testFailedCountSinceCountsOnlyFailedEmailsUpdatedAfterCutoff(): void
    {
        $this->insertQueueRow([
            'id' => 'failed-recent',
            'status' => 'failed',
            'updated_at' => '2026-01-01 10:30:00',
        ]);
        $this->insertQueueRow([
            'id' => 'failed-old',
            'status' => 'failed',
            'updated_at' => '2026-01-01 09:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'retry-ignored',
            'status' => 'retry',
            'updated_at' => '2026-01-01 10:30:00',
        ]);

        self::assertSame(1, $this->repository->failedCountSince('2026-01-01 10:00:00'));
    }

    public function testOldestUnsentGlobalIgnoresTerminalStatuses(): void
    {
        $this->insertQueueRow([
            'id' => 'failed-old',
            'status' => 'failed',
            'created_at' => '2026-01-01 07:00:00',
        ]);
        $pendingId = $this->insertQueueRow([
            'id' => 'pending-newer',
            'status' => 'pending',
            'created_at' => '2026-01-01 08:00:00',
        ]);

        $oldest = $this->repository->oldestUnsentGlobal();

        self::assertSame($pendingId, $oldest['id']);
    }
}
