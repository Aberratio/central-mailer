<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use PDO;

final class WorkerHeartbeatRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function beat(
        string $workerId,
        string $queue,
        int $processedDelta = 0,
        ?string $lastProcessedAt = null,
        ?string $lastError = null
    ): void {
        if (!in_array($queue, ['standard', 'technical'], true)) {
            throw new \InvalidArgumentException('Queue must be standard or technical');
        }

        $now = self::now();
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO email_worker_heartbeats
                 (worker_id, queue, host, process_id, started_at, last_seen_at, last_processed_at, last_error, processed_count, updated_at)
                 VALUES
                 (:worker_id, :queue, :host, :process_id, :started_at, :last_seen_at, :last_processed_at, :last_error, :processed_count, :updated_at)
                 ON CONFLICT(worker_id) DO UPDATE SET
                   queue = excluded.queue,
                   host = excluded.host,
                   process_id = excluded.process_id,
                   last_seen_at = excluded.last_seen_at,
                   last_processed_at = COALESCE(excluded.last_processed_at, email_worker_heartbeats.last_processed_at),
                   last_error = excluded.last_error,
                   processed_count = email_worker_heartbeats.processed_count + excluded.processed_count,
                   updated_at = excluded.updated_at'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO email_worker_heartbeats
                 (worker_id, queue, host, process_id, started_at, last_seen_at, last_processed_at, last_error, processed_count, updated_at)
                 VALUES
                 (:worker_id, :queue, :host, :process_id, :started_at, :last_seen_at, :last_processed_at, :last_error, :processed_count, :updated_at)
                 ON DUPLICATE KEY UPDATE
                   queue = VALUES(queue),
                   host = VALUES(host),
                   process_id = VALUES(process_id),
                   last_seen_at = VALUES(last_seen_at),
                   last_processed_at = COALESCE(VALUES(last_processed_at), last_processed_at),
                   last_error = VALUES(last_error),
                   processed_count = processed_count + VALUES(processed_count),
                   updated_at = VALUES(updated_at)'
            );
        }

        $stmt->execute([
            'worker_id' => $workerId,
            'queue' => $queue,
            'host' => gethostname() ?: 'unknown',
            'process_id' => getmypid() ?: null,
            'started_at' => $now,
            'last_seen_at' => $now,
            'last_processed_at' => $lastProcessedAt,
            'last_error' => $lastError === null ? null : mb_substr($lastError, 0, 2000),
            'processed_count' => max(0, $processedDelta),
            'updated_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function findActiveSince(string $freshSince): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT worker_id, queue, host, process_id, started_at, last_seen_at, last_processed_at, last_error, processed_count
             FROM email_worker_heartbeats
             WHERE last_seen_at >= :fresh_since
             ORDER BY queue ASC, last_seen_at DESC, worker_id ASC'
        );
        $stmt->execute(['fresh_since' => $freshSince]);

        return $stmt->fetchAll();
    }

    /** @return array{standard: array{lastSeenAt: string|null, lastProcessedAt: string|null}, technical: array{lastSeenAt: string|null, lastProcessedAt: string|null}} */
    public function lastActivityByQueue(): array
    {
        $stmt = $this->pdo->query(
            'SELECT queue, MAX(last_seen_at) AS last_seen_at, MAX(last_processed_at) AS last_processed_at
             FROM email_worker_heartbeats
             GROUP BY queue'
        );
        $activity = [
            'standard' => ['lastSeenAt' => null, 'lastProcessedAt' => null],
            'technical' => ['lastSeenAt' => null, 'lastProcessedAt' => null],
        ];
        foreach ($stmt->fetchAll() as $row) {
            $queue = (string) $row['queue'];
            if (array_key_exists($queue, $activity)) {
                $activity[$queue] = [
                    'lastSeenAt' => $row['last_seen_at'],
                    'lastProcessedAt' => $row['last_processed_at'],
                ];
            }
        }

        return $activity;
    }

    /** @return array{standard: int, technical: int} */
    public function activeCountsSince(string $freshSince): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT queue, COUNT(*) AS count
             FROM email_worker_heartbeats
             WHERE last_seen_at >= :fresh_since
             GROUP BY queue'
        );
        $stmt->execute(['fresh_since' => $freshSince]);
        $counts = ['standard' => 0, 'technical' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $queue = (string) $row['queue'];
            if (array_key_exists($queue, $counts)) {
                $counts[$queue] = (int) $row['count'];
            }
        }

        return $counts;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
