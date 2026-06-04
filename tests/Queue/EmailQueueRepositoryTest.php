<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Tests\Support\DatabaseTestCase;

final class EmailQueueRepositoryTest extends DatabaseTestCase
{
    public function testFindForSourceAppReturnsProviderMessageId(): void
    {
        $id = $this->insertQueueRow(['provider_message_id' => '<message-id@mailer.test>']);

        $row = $this->repository->findForSourceApp($id, 'app-a');

        self::assertSame('<message-id@mailer.test>', $row['provider_message_id']);
        self::assertNull($this->repository->findForSourceApp($id, 'app-b'));
    }

    public function testMarkSentStoresMessageIdAndClearsRetryState(): void
    {
        $id = $this->insertQueueRow([
            'status' => 'retry',
            'next_attempt_at' => '2026-01-01 11:00:00',
            'last_error' => 'Temporary error',
        ]);

        $this->repository->markSent($id, '<message-id@mailer.test>');

        $row = $this->fetchQueueRow($id);
        self::assertSame('sent', $row['status']);
        self::assertSame('<message-id@mailer.test>', $row['provider_message_id']);
        self::assertNull($row['next_attempt_at']);
        self::assertNull($row['last_error']);
        self::assertNotNull($row['sent_at']);
    }

    public function testReleaseStaleProcessingRetriesOrFailsMessagesAtAttemptLimit(): void
    {
        $retryId = $this->insertQueueRow([
            'status' => 'processing',
            'attempts' => 1,
            'max_attempts' => 3,
            'updated_at' => '2026-01-01 09:00:00',
        ]);
        $failedId = $this->insertQueueRow([
            'status' => 'processing',
            'attempts' => 2,
            'max_attempts' => 3,
            'updated_at' => '2026-01-01 09:00:00',
        ]);
        $freshId = $this->insertQueueRow([
            'status' => 'processing',
            'updated_at' => '2026-01-01 11:00:00',
        ]);
        $pendingId = $this->insertQueueRow([
            'status' => 'pending',
            'updated_at' => '2026-01-01 09:00:00',
        ]);

        $released = $this->repository->releaseStaleProcessing(
            '2026-01-01 10:00:00',
            'Email processing timed out'
        );

        self::assertSame(2, $released);

        $retry = $this->fetchQueueRow($retryId);
        self::assertSame('retry', $retry['status']);
        self::assertSame(2, $retry['attempts']);
        self::assertNotNull($retry['next_attempt_at']);
        self::assertSame('Email processing timed out', $retry['last_error']);

        $failed = $this->fetchQueueRow($failedId);
        self::assertSame('failed', $failed['status']);
        self::assertSame(3, $failed['attempts']);
        self::assertNull($failed['next_attempt_at']);

        self::assertSame('processing', $this->fetchQueueRow($freshId)['status']);
        self::assertSame('pending', $this->fetchQueueRow($pendingId)['status']);
    }
}
