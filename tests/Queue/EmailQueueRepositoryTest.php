<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Queue\IdempotencyConflictException;
use CentralMailer\Tests\Support\DatabaseTestCase;

final class EmailQueueRepositoryTest extends DatabaseTestCase
{
    public function testRejectsEnqueueWhenClientQueueCapacityIsReached(): void
    {
        $this->insertQueueRow();

        $this->expectException(\CentralMailer\Queue\QueueCapacityExceededException::class);
        $this->repository->assertCanEnqueue('app-a', 1, 0, 1, 1000);
    }

    public function testRejectsEnqueueWhenAttachmentCapacityIsReached(): void
    {
        $id = $this->insertQueueRow();
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_attachments
             (id, email_id, filename, content_type, size_bytes, sha256, storage_path, deleted_at, created_at)
             VALUES
             (:id, :email_id, :filename, :content_type, :size_bytes, :sha256, :storage_path, NULL, :created_at)'
        );
        $stmt->execute([
            'id' => 'attachment-1',
            'email_id' => $id,
            'filename' => 'file.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 900,
            'sha256' => str_repeat('a', 64),
            'storage_path' => $id . '/file',
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $this->expectException(\CentralMailer\Queue\QueueCapacityExceededException::class);
        $this->repository->assertCanEnqueue('app-a', 1, 101, 10, 1000);
    }

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
            'status' => 'processing',
            'lease_id' => 'lease-a',
            'lease_expires_at' => '2026-01-01 11:00:00',
            'next_attempt_at' => '2026-01-01 11:00:00',
            'last_error' => 'Temporary error',
        ]);

        self::assertTrue($this->repository->markSent($id, 'lease-a', '<message-id@mailer.test>'));

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
            'lease_id' => 'lease-retry',
            'lease_expires_at' => '2026-01-01 09:00:00',
            'attempts' => 1,
            'max_attempts' => 3,
            'updated_at' => '2026-01-01 09:00:00',
        ]);
        $failedId = $this->insertQueueRow([
            'status' => 'processing',
            'lease_id' => 'lease-failed',
            'lease_expires_at' => '2026-01-01 09:00:00',
            'attempts' => 2,
            'max_attempts' => 3,
            'updated_at' => '2026-01-01 09:00:00',
        ]);
        $freshId = $this->insertQueueRow([
            'status' => 'processing',
            'lease_id' => 'lease-fresh',
            'lease_expires_at' => '2026-01-01 11:00:00',
            'updated_at' => '2026-01-01 11:00:00',
        ]);
        $pendingId = $this->insertQueueRow([
            'status' => 'pending',
            'updated_at' => '2026-01-01 09:00:00',
        ]);
        $legacyProcessingId = $this->insertQueueRow([
            'status' => 'processing',
            'lease_id' => null,
            'lease_expires_at' => null,
            'updated_at' => '2026-01-01 09:00:00',
        ]);

        $released = $this->repository->releaseStaleProcessing(
            '2026-01-01 10:00:00',
            'Email processing timed out'
        );

        self::assertSame(3, $released);

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
        self::assertSame('retry', $this->fetchQueueRow($legacyProcessingId)['status']);
    }

    public function testLeasePreventsOldWorkerFromOverwritingNewOwner(): void
    {
        $id = $this->insertQueueRow([
            'status' => 'processing',
            'lease_id' => 'new-lease',
            'lease_expires_at' => '2026-01-01 11:00:00',
        ]);

        self::assertFalse($this->repository->markSent($id, 'old-lease', '<old@mailer.test>'));
        self::assertNull($this->repository->markFailedOrRetry($id, 'old-lease', 1, 5, 'Old error', null));

        $row = $this->fetchQueueRow($id);
        self::assertSame('processing', $row['status']);
        self::assertSame('new-lease', $row['lease_id']);
        self::assertNull($row['provider_message_id']);
    }

    public function testTechnicalFallbackRequiresCurrentLeaseAndTechnicalPriority(): void
    {
        $id = $this->insertQueueRow([
            'priority' => 'technical',
            'status' => 'processing',
            'lease_id' => 'current-lease',
        ]);

        self::assertFalse($this->repository->fallbackTechnicalToStandard(
            $id,
            'old-lease',
            5,
            'Old worker error'
        ));
        self::assertTrue($this->repository->fallbackTechnicalToStandard(
            $id,
            'current-lease',
            5,
            'Gmail SMTP is unavailable',
            \RuntimeException::class
        ));

        $row = $this->fetchQueueRow($id);
        self::assertSame('normal', $row['priority']);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, $row['attempts']);
    }

    public function testInsertReplaysSameIdempotencyKeyAndRejectsDifferentPayload(): void
    {
        $data = [...$this->queueData(), 'maxQueuedEmailsPerClient' => 1];

        $created = $this->repository->insert($data);
        $replayed = $this->repository->insert($data);

        self::assertTrue($created->created);
        self::assertFalse($replayed->created);
        self::assertSame($created->id, $replayed->id);

        $this->expectException(IdempotencyConflictException::class);
        $this->repository->insert([...$data, 'subject' => 'Different subject']);
    }

    public function testAgedNormalEmailIsNotStarvedByRecentHighEmail(): void
    {
        $agedNormalId = $this->insertQueueRow([
            'priority' => 'normal',
            'created_at' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);
        $this->insertQueueRow([
            'priority' => 'high',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $claimed = $this->repository->claimBatch(1, 300, 900);

        self::assertSame($agedNormalId, $claimed[0]['id']);
    }

    public function testQueueCreditsPreferHigherWeightButStillServeOtherClient(): void
    {
        $this->pdo->exec("UPDATE email_clients SET queue_weight = 2 WHERE source_app = 'app-b'");
        $appAId = $this->insertQueueRow([
            'source_app' => 'app-a',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $appBId = $this->insertQueueRow([
            'source_app' => 'app-b',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $first = $this->repository->claimBatch(1, 300, 900);
        $second = $this->repository->claimBatch(1, 300, 900);

        self::assertSame($appBId, $first[0]['id']);
        self::assertSame($appAId, $second[0]['id']);
    }

    public function testStandardQueueDoesNotClaimTechnicalEmail(): void
    {
        $technicalId = $this->insertQueueRow(['priority' => 'technical']);
        $standardId = $this->insertQueueRow([
            'priority' => 'normal',
            'created_at' => '2026-01-01 10:01:00',
        ]);

        $claimed = $this->repository->claimBatch(1, 300, 900);

        self::assertSame($standardId, $claimed[0]['id']);
        self::assertSame('pending', $this->fetchQueueRow($technicalId)['status']);
    }

    public function testTechnicalQueueClaimsOldestEmailInFifoOrder(): void
    {
        $oldestId = $this->insertQueueRow([
            'id' => 'technical-a',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'technical-b',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:01:00',
        ]);
        $this->insertQueueRow(['priority' => 'high']);

        $claimed = $this->repository->claimBatch(20, 300, 900, 'technical');

        self::assertCount(1, $claimed);
        self::assertSame($oldestId, $claimed[0]['id']);
    }

    public function testTechnicalQueueWaitsForOldestRetryBeforeClaimingNextEmail(): void
    {
        $this->insertQueueRow([
            'id' => 'technical-retry',
            'priority' => 'technical',
            'status' => 'retry',
            'next_attempt_at' => '2099-01-01 00:00:00',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->insertQueueRow([
            'id' => 'technical-next',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:01:00',
        ]);

        self::assertSame([], $this->repository->claimBatch(1, 300, 900, 'technical'));
    }

    public function testBatchStoresSharedMessageAndCreatesQueueEvents(): void
    {
        $result = $this->repository->insertBatch([
            'sourceApp' => 'app-a',
            'idempotencyKey' => 'batch-1',
            'subject' => 'Shared subject',
            'html' => '<p>Shared body</p>',
            'text' => 'Shared body',
            'priority' => 'normal',
            'metadata' => ['type' => 'newsletter'],
            'recipients' => [
                ['to' => 'one@deliverable.test', 'metadata' => null],
                ['to' => 'two@deliverable.test', 'metadata' => ['userId' => 2]],
            ],
        ]);

        self::assertCount(2, $result->emails);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM email_messages')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM email_events')->fetchColumn());

        $claimed = $this->repository->claimBatch(1, 300, 900);
        self::assertSame('Shared subject', $claimed[0]['resolved_subject']);
        self::assertSame('<p>Shared body</p>', $claimed[0]['resolved_html_body']);
    }

    /** @return array<string, mixed> */
    private function queueData(): array
    {
        return [
            'sourceApp' => 'app-a',
            'idempotencyKey' => 'request-123',
            'to' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
            'text' => 'Body',
            'priority' => 'normal',
            'metadata' => null,
        ];
    }
}
