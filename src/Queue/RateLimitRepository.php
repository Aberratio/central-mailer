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
        int $windowMinutes,
        ?int $clientLimit,
        string $clientSince,
        int $clientWindowMinutes,
        string $cleanupSince
    ): RateLimitDecision
    {
        if ($limit <= 0) {
            return RateLimitDecision::denied('global', self::retryAfter(self::now(), max(1, $windowMinutes)), 0, $limit);
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
                "SELECT COUNT(*) FROM email_rate_limit_reservations
                 WHERE reserved_at >= :since AND source_app NOT LIKE 'provider:%'"
            );
            $countStmt->execute(['since' => $since]);
            $count = (int) $countStmt->fetchColumn();
            if ($count >= $limit) {
                $retryAfter = $this->retryAfterForWindow(
                    "SELECT MIN(reserved_at) FROM email_rate_limit_reservations
                     WHERE reserved_at >= :since AND source_app NOT LIKE 'provider:%'",
                    ['since' => $since],
                    $windowMinutes
                );
                $this->pdo->commit();

                return RateLimitDecision::denied('global', $retryAfter, $count, $limit);
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
                $clientCount = (int) $clientCountStmt->fetchColumn();
                if ($clientCount >= $clientLimit) {
                    $retryAfter = $this->retryAfterForWindow(
                        'SELECT MIN(reserved_at)
                         FROM email_rate_limit_reservations
                         WHERE source_app = :source_app AND reserved_at >= :client_since',
                        ['source_app' => $sourceApp, 'client_since' => $clientSince],
                        $clientWindowMinutes
                    );
                    $this->pdo->commit();

                    return RateLimitDecision::denied('client', $retryAfter, $clientCount, $clientLimit);
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

            return RateLimitDecision::allowed($count + 1, $limit);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Reserves a slot in a dedicated scope (e.g. "provider:gmail") tracked in the same
     * reservations table but excluded from the global window.
     */
    public function tryReserveScope(
        string $scope,
        int $limit,
        string $since,
        int $windowMinutes
    ): RateLimitDecision {
        $now = self::now();
        $this->pdo->beginTransaction();

        try {
            $lock = $this->pdo->prepare('UPDATE email_rate_limit_lock SET updated_at = :updated_at WHERE id = 1');
            $lock->execute(['updated_at' => $now]);

            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM email_rate_limit_reservations
                 WHERE source_app = :scope AND reserved_at >= :since'
            );
            $countStmt->execute(['scope' => $scope, 'since' => $since]);
            $count = (int) $countStmt->fetchColumn();
            if ($count >= $limit) {
                $retryAfter = $this->retryAfterForWindow(
                    'SELECT MIN(reserved_at) FROM email_rate_limit_reservations
                     WHERE source_app = :scope AND reserved_at >= :since',
                    ['scope' => $scope, 'since' => $since],
                    $windowMinutes
                );
                $this->pdo->commit();

                return RateLimitDecision::denied('provider', $retryAfter, $count, $limit);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO email_rate_limit_reservations (id, source_app, reserved_at)
                 VALUES (:id, :source_app, :reserved_at)'
            );
            $insert->execute([
                'id' => Uuid::v4(),
                'source_app' => $scope,
                'reserved_at' => $now,
            ]);
            $this->pdo->commit();

            return RateLimitDecision::allowed($count + 1, $limit);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array{used: int, limit: int, remaining: int, retryAfter: string|null} */
    public function globalUsage(int $limit, string $since, int $windowMinutes): array
    {
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM email_rate_limit_reservations
             WHERE reserved_at >= :since AND source_app NOT LIKE 'provider:%'"
        );
        $countStmt->execute(['since' => $since]);
        $used = (int) $countStmt->fetchColumn();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'retryAfter' => $used >= $limit
                ? $this->retryAfterForWindow(
                    'SELECT MIN(reserved_at) FROM email_rate_limit_reservations WHERE reserved_at >= :since',
                    ['since' => $since],
                    $windowMinutes
                )
                : null,
        ];
    }

    /** @return array{used: int, limit: int|null, remaining: int|null, retryAfter: string|null} */
    public function clientUsage(string $sourceApp, ?int $limit, string $since, int $windowMinutes): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM email_rate_limit_reservations
             WHERE source_app = :source_app AND reserved_at >= :since'
        );
        $countStmt->execute(['source_app' => $sourceApp, 'since' => $since]);
        $used = (int) $countStmt->fetchColumn();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'retryAfter' => $limit !== null && $used >= $limit
                ? $this->retryAfterForWindow(
                    'SELECT MIN(reserved_at)
                     FROM email_rate_limit_reservations
                     WHERE source_app = :source_app AND reserved_at >= :since',
                    ['source_app' => $sourceApp, 'since' => $since],
                    $windowMinutes
                )
                : null,
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $params */
    private function retryAfterForWindow(string $sql, array $params, int $windowMinutes): string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $oldest = $stmt->fetchColumn();
        if (!is_string($oldest) || $oldest === '') {
            return self::retryAfter(self::now(), max(1, $windowMinutes));
        }

        return self::retryAfter($oldest, max(1, $windowMinutes));
    }

    private static function retryAfter(string $from, int $windowMinutes): string
    {
        return (new \DateTimeImmutable($from))
            ->modify(sprintf('+%d minutes', $windowMinutes))
            ->modify('+1 second')
            ->format('Y-m-d H:i:s');
    }

}
