<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Tests\Support\DatabaseTestCase;

final class EmailQueueRepositoryContextTest extends DatabaseTestCase
{
    public function testInsertPersistsContextId(): void
    {
        $result = $this->repository->insert([...$this->queueData(), 'contextId' => 'evt-1']);

        $row = $this->fetchQueueRow($result->id);
        self::assertSame('evt-1', $row['context_id']);
    }

    public function testInsertWithoutContextIdStoresNull(): void
    {
        $result = $this->repository->insert($this->queueData());

        $row = $this->fetchQueueRow($result->id);
        self::assertNull($row['context_id']);
    }

    public function testInsertBatchPersistsContextIdOnQueueRowsAndMessage(): void
    {
        $result = $this->repository->insertBatch([...$this->batchData(), 'contextId' => 'evt-1']);

        foreach ($result->emails as $email) {
            self::assertSame('evt-1', $this->fetchQueueRow($email['id'])['context_id']);
        }
        self::assertSame('evt-1', $this->pdo->query('SELECT context_id FROM email_messages')->fetchColumn());
    }

    public function testContextIdDoesNotChangeHashOfRequestsWithoutIt(): void
    {
        // Replays of requests enqueued before contextId existed must keep matching.
        $created = $this->repository->insert($this->queueData());
        $replayed = $this->repository->insert($this->queueData());

        self::assertTrue($created->created);
        self::assertFalse($replayed->created);
        self::assertSame($created->id, $replayed->id);
    }

    public function testFindForContextIsScopedToSourceAppAndOrderedNewestFirst(): void
    {
        $this->insertQueueRow(['id' => 'ctx-old', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']);
        $this->insertQueueRow(['id' => 'ctx-new', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 11:00:00', 'updated_at' => '2026-01-01 11:00:00']);
        $this->insertQueueRow(['id' => 'ctx-other-app', 'context_id' => 'evt-1', 'source_app' => 'app-b']);
        $this->insertQueueRow(['id' => 'ctx-other-context', 'context_id' => 'evt-2']);
        $this->insertQueueRow(['id' => 'ctx-none']);

        $rows = $this->repository->findForContextForSourceApp('evt-1', 'app-a');

        self::assertSame(['ctx-new', 'ctx-old'], array_column($rows, 'id'));
    }

    public function testFindForContextSupportsPagination(): void
    {
        $this->insertQueueRow(['id' => 'ctx-a', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']);
        $this->insertQueueRow(['id' => 'ctx-b', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 11:00:00', 'updated_at' => '2026-01-01 11:00:00']);
        $this->insertQueueRow(['id' => 'ctx-c', 'context_id' => 'evt-1', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00']);

        $firstPage = $this->repository->findForContextForSourceApp('evt-1', 'app-a', 2, 0);
        $secondPage = $this->repository->findForContextForSourceApp('evt-1', 'app-a', 2, 2);

        self::assertSame(['ctx-c', 'ctx-b'], array_column($firstPage, 'id'));
        self::assertSame(['ctx-a'], array_column($secondPage, 'id'));
    }

    public function testContextStatusCountsAggregateStatusesAndBounces(): void
    {
        $this->insertQueueRow(['id' => 'ctx-sent-1', 'context_id' => 'evt-1', 'status' => 'sent']);
        $this->insertQueueRow(['id' => 'ctx-sent-2', 'context_id' => 'evt-1', 'status' => 'sent']);
        $this->insertQueueRow(['id' => 'ctx-failed', 'context_id' => 'evt-1', 'status' => 'failed']);
        $this->insertQueueRow(['id' => 'ctx-pending', 'context_id' => 'evt-1', 'status' => 'pending']);
        $this->insertQueueRow(['id' => 'ctx-hidden', 'context_id' => 'evt-1', 'source_app' => 'app-b', 'status' => 'sent']);
        $this->repository->recordBounce('ctx-sent-1', '5.1.1', 'User unknown');

        $counts = $this->repository->contextStatusCountsForSourceApp('evt-1', 'app-a');

        self::assertSame(4, $counts['total']);
        self::assertSame(2, $counts['statusCounts']['sent']);
        self::assertSame(1, $counts['statusCounts']['failed']);
        self::assertSame(1, $counts['statusCounts']['pending']);
        self::assertSame(0, $counts['statusCounts']['retry']);
        self::assertSame(1, $counts['bounced']);
    }

    public function testContextStatusCountsForUnknownContextAreEmpty(): void
    {
        $counts = $this->repository->contextStatusCountsForSourceApp('missing', 'app-a');

        self::assertSame(0, $counts['total']);
        self::assertSame(0, $counts['bounced']);
        self::assertSame(0, array_sum($counts['statusCounts']));
    }

    public function testStatusSelectExposesBounceCountAndBouncedAt(): void
    {
        $id = $this->insertQueueRow(['context_id' => 'evt-1', 'status' => 'sent']);
        $this->repository->recordBounce($id, '5.1.1', 'User unknown');

        $row = $this->repository->findForSourceApp($id, 'app-a');

        self::assertSame(1, (int) $row['bounce_count']);
        self::assertNotNull($row['bounced_at']);
        self::assertSame('evt-1', $row['context_id']);
    }

    public function testGlobalUnsentAndSentSupportContextFilter(): void
    {
        $this->insertQueueRow(['id' => 'g-pending-ctx', 'context_id' => 'evt-1', 'status' => 'pending']);
        $this->insertQueueRow(['id' => 'g-pending-other', 'context_id' => 'evt-2', 'status' => 'pending']);
        $this->insertQueueRow(['id' => 'g-sent-ctx', 'context_id' => 'evt-1', 'status' => 'sent', 'sent_at' => '2026-01-01 10:05:00']);
        $this->insertQueueRow(['id' => 'g-sent-none', 'status' => 'sent', 'sent_at' => '2026-01-01 10:06:00']);

        self::assertSame(['g-pending-ctx'], array_column($this->repository->findUnsentGlobal(10, 'evt-1'), 'id'));
        self::assertSame(['g-sent-ctx'], array_column($this->repository->findSentGlobal(10, 'evt-1'), 'id'));
        self::assertCount(2, $this->repository->findUnsentGlobal(10));
        self::assertCount(2, $this->repository->findSentGlobal(10));
    }

    /** @return array<string, mixed> */
    private function queueData(): array
    {
        return [
            'sourceApp' => 'app-a',
            'idempotencyKey' => 'request-ctx-123',
            'to' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
            'text' => 'Body',
            'priority' => 'normal',
            'metadata' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function batchData(): array
    {
        return [
            'sourceApp' => 'app-a',
            'idempotencyKey' => 'batch-ctx-123',
            'subject' => 'Shared subject',
            'html' => '<p>Shared body</p>',
            'text' => 'Shared body',
            'priority' => 'normal',
            'metadata' => null,
            'recipients' => [
                ['to' => 'one@deliverable.test', 'metadata' => null],
                ['to' => 'two@deliverable.test', 'metadata' => ['participantId' => 2]],
            ],
        ];
    }
}
