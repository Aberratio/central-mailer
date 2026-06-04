<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Support\Uuid;
use PDO;

final class EnqueueRateLimitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tryReserve(string $sourceApp, int $limit, string $since, string $cleanupSince): bool
    {
        if ($limit <= 0) {
            return false;
        }

        $now = self::now();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('UPDATE email_enqueue_rate_limit_lock SET updated_at = :updated_at WHERE id = 1');
            $lock->execute(['updated_at' => $now]);
            if ((int) $this->pdo->query('SELECT id FROM email_enqueue_rate_limit_lock WHERE id = 1')->fetchColumn() !== 1) {
                throw new \RuntimeException('Enqueue rate limit lock row is missing');
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM email_enqueue_rate_limit_reservations WHERE reserved_at < :cleanup_since'
            );
            $delete->execute(['cleanup_since' => $cleanupSince]);

            $count = $this->pdo->prepare(
                'SELECT COUNT(*) FROM email_enqueue_rate_limit_reservations
                 WHERE source_app = :source_app AND reserved_at >= :since'
            );
            $count->execute(['source_app' => $sourceApp, 'since' => $since]);
            if ((int) $count->fetchColumn() >= $limit) {
                $this->pdo->commit();

                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO email_enqueue_rate_limit_reservations (id, source_app, reserved_at)
                 VALUES (:id, :source_app, :reserved_at)'
            );
            $insert->execute(['id' => Uuid::v4(), 'source_app' => $sourceApp, 'reserved_at' => $now]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
