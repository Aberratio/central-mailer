<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use PDO;

final class RateLimitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tryReserve(int $limit, string $since): bool
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

            $delete = $this->pdo->prepare('DELETE FROM email_rate_limit_reservations WHERE reserved_at < :since');
            $delete->execute(['since' => $since]);

            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM email_rate_limit_reservations')->fetchColumn();
            if ($count >= $limit) {
                $this->pdo->commit();

                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO email_rate_limit_reservations (id, reserved_at) VALUES (:id, :reserved_at)'
            );
            $insert->execute([
                'id' => self::uuidV4(),
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

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
