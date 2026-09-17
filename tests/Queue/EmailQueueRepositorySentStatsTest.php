<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Tests\Support\DatabaseTestCase;

final class EmailQueueRepositorySentStatsTest extends DatabaseTestCase
{
    public function testGroupsSentEmailsByDayClientAndQueueWithinHalfOpenRange(): void
    {
        $this->sent('app-a', '2026-01-01 00:00:00', 'standard');
        $this->sent('app-a', '2026-01-01 23:59:59', 'technical');
        $this->sent('app-b', '2026-01-01 12:00:00', 'standard');
        $this->sent('app-a', '2026-01-02 09:00:00', 'standard');
        $this->sent('app-a', '2026-01-03 00:00:00', 'standard');
        $this->insertQueueRow(['status' => 'failed', 'sent_at' => null, 'updated_at' => '2026-01-01 10:00:00']);

        $rows = $this->repository->sentCountsBetween('2026-01-01 00:00:00', '2026-01-03 00:00:00', 'day');

        self::assertSame([
            ['bucket' => '2026-01-01', 'sourceApp' => 'app-a', 'queue' => 'standard', 'count' => 1],
            ['bucket' => '2026-01-01', 'sourceApp' => 'app-a', 'queue' => 'technical', 'count' => 1],
            ['bucket' => '2026-01-01', 'sourceApp' => 'app-b', 'queue' => 'standard', 'count' => 1],
            ['bucket' => '2026-01-02', 'sourceApp' => 'app-a', 'queue' => 'standard', 'count' => 1],
        ], $rows);
    }

    public function testGroupsByHour(): void
    {
        $this->sent('app-a', '2026-01-01 10:00:00', 'standard');
        $this->sent('app-a', '2026-01-01 10:59:59', 'standard');
        $this->sent('app-a', '2026-01-01 11:00:00', 'standard');

        $rows = $this->repository->sentCountsBetween('2026-01-01 00:00:00', '2026-01-02 00:00:00', 'hour');

        self::assertSame(['2026-01-01 10', '2026-01-01 11'], array_column($rows, 'bucket'));
        self::assertSame([2, 1], array_column($rows, 'count'));
    }

    public function testFiltersByClientAndQueueAndTreatsMissingQueueAsStandard(): void
    {
        $this->sent('app-a', '2026-01-01 10:00:00', null);
        $this->sent('app-a', '2026-01-01 10:00:00', 'technical');
        $this->sent('app-b', '2026-01-01 10:00:00', 'standard');

        $standardA = $this->repository->sentCountsBetween('2026-01-01 00:00:00', '2026-01-02 00:00:00', 'day', 'app-a', 'standard');

        self::assertSame(
            [['bucket' => '2026-01-01', 'sourceApp' => 'app-a', 'queue' => 'standard', 'count' => 1]],
            $standardA
        );
    }

    private function sent(string $sourceApp, string $sentAt, ?string $queue): void
    {
        $this->insertQueueRow([
            'source_app' => $sourceApp,
            'status' => 'sent',
            'sent_at' => $sentAt,
            'sent_queue' => $queue,
        ]);
    }
}
