<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Support\Uuid;
use PDO;

final class RateLimitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tryReserve(
        string $sourceApp,
        int $limit,
        string $since,
        ?int $clientLimit,
        string $clientSince,
        string $cleanupSince
    ): bool
    {
        if ($limit <= 0) {
            return false;
        }

        $now = self::now();
        $this->pdo->beginTransaction();

        try {
            // Updating the singleton row serializes reservations across all workers.
            $lock = $this->pdo->prepare('UPDATE email_rate_limit_lock SET updated_at = :updated_at WHERE id = 1');
            $lock->execute(['updated_at' => $now]);
            if ((int) $this->pdo->query('SELECT id FROM email_rate_limit_lock WHERE id = 1')->fetchColumn() !== 1) {
                throw new \RuntimeException('Rate limit lock row is missing');
            }

            $delete = $this->pdo->prepare('DELETE FROM email_rate_limit_reservations WHERE reserved_at < :cleanup_since');
            $delete->execute(['cleanup_since' => $cleanupSince]);

            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM email_rate_limit_reservations WHERE reserved_at >= :since'
            );
            $countStmt->execute(['since' => $since]);
            $count = (int) $countStmt->fetchColumn();
            if ($count >= $limit) {
                $this->pdo->commit();

                return false;
            }

            if ($clientLimit !== null) {
                $clientCountStmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM email_rate_limit_reservations
                     WHERE source_app = :source_app AND reserved_at >= :client_since'
                );
                $clientCountStmt->execute([
                    'source_app' => $sourceApp,
                    'client_since' => $clientSince,
                ]);
                if ((int) $clientCountStmt->fetchColumn() >= $clientLimit) {
                    $this->pdo->commit();

                    return false;
                }
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO email_rate_limit_reservations (id, source_app, reserved_at)
                 VALUES (:id, :source_app, :reserved_at)'
            );
            $insert->execute([
                'id' => Uuid::v4(),
                'source_app' => $sourceApp,
                'reserved_at' => $now,
            ]);
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
