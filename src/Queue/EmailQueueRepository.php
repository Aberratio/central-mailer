<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use PDO;
use PDOException;

final class EmailQueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): EnqueueResult
    {
        $id = self::uuidV4();
        $now = self::now();
        $metadata = $data['metadata'] === null ? null : json_encode($data['metadata'], JSON_THROW_ON_ERROR);
        $requestHash = self::requestHash($data, $metadata);

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue
            (id, source_app, idempotency_key, request_hash, recipient_email, subject, html_body, text_body, priority, metadata, status, attempts, max_attempts, created_at, updated_at)
            VALUES
            (:id, :source_app, :idempotency_key, :request_hash, :recipient_email, :subject, :html_body, :text_body, :priority, :metadata, "pending", 0, :max_attempts, :created_at, :updated_at)'
        );

        try {
            $stmt->execute([
                'id' => $id,
                'source_app' => $data['sourceApp'],
                'idempotency_key' => $data['idempotencyKey'],
                'request_hash' => $requestHash,
                'recipient_email' => $data['to'],
                'subject' => $data['subject'],
                'html_body' => $data['html'],
                'text_body' => $data['text'],
                'priority' => $data['priority'],
                'metadata' => $metadata,
                'max_attempts' => $data['maxAttempts'] ?? 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            $existing = $this->findByIdempotencyKey((string) $data['sourceApp'], $data['idempotencyKey']);
            if ($existing === null) {
                throw $exception;
            }

            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new IdempotencyConflictException('Idempotency-Key was already used for a different email');
            }

            return new EnqueueResult((string) $existing['id'], (string) $existing['status'], false);
        }

        return new EnqueueResult($id, 'pending', true);
    }

    /** @return array<string, mixed>|null */
    public function findForSourceApp(string $id, string $sourceApp): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, source_app, recipient_email, subject, attempts, last_error, provider_message_id, created_at, sent_at
             FROM email_queue
             WHERE id = :id AND source_app = :source_app'
        );
        $stmt->execute(['id' => $id, 'source_app' => $sourceApp]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function claimBatch(int $limit, int $leaseSeconds): array
    {
        $leaseId = self::uuidV4();
        $leaseExpiresAt = (new \DateTimeImmutable(sprintf('+%d seconds', $leaseSeconds)))->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();

        try {
            $claimed = $this->claimWithSkipLocked($limit);
            $ids = array_column($claimed, 'id');
            if ($ids !== []) {
                $this->setProcessing($ids, $leaseId, $leaseExpiresAt);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            $this->pdo->beginTransaction();
            try {
                $claimed = $this->claimWithFallback($limit);
                $ids = array_column($claimed, 'id');
                if ($ids !== []) {
                    $this->setProcessing($ids, $leaseId, $leaseExpiresAt);
                }
                $this->pdo->commit();
            } catch (\Throwable $fallbackException) {
                $this->pdo->rollBack();
                throw $fallbackException;
            }
        }

        if ($ids === []) {
            return [];
        }

        $rows = $this->fetchByIds($ids, $leaseId);
        $previousStatuses = [];
        foreach ($claimed as $row) {
            $previousStatuses[(string) $row['id']] = (string) $row['status'];
        }
        foreach ($rows as &$row) {
            $row['_previous_status'] = $previousStatuses[(string) $row['id']];
        }
        unset($row);

        return $rows;
    }

    public function releaseClaim(string $id, string $leaseId, string $previousStatus): bool
    {
        if (!in_array($previousStatus, ['pending', 'retry'], true)) {
            throw new \InvalidArgumentException('Previous queue status is invalid');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = :status, lease_id = NULL, lease_expires_at = NULL, updated_at = :updated_at
             WHERE id = :id AND status = "processing" AND lease_id = :lease_id'
        );
        $stmt->execute([
            'id' => $id,
            'lease_id' => $leaseId,
            'status' => $previousStatus,
            'updated_at' => self::now(),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markSent(string $id, string $leaseId, ?string $providerMessageId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = "sent", provider_message_id = :provider_message_id, sent_at = :sent_at, updated_at = :updated_at,
                 next_attempt_at = NULL, last_error = NULL, lease_id = NULL, lease_expires_at = NULL
             WHERE id = :id AND status = "processing" AND lease_id = :lease_id'
        );
        $now = self::now();
        $stmt->execute([
            'id' => $id,
            'lease_id' => $leaseId,
            'provider_message_id' => $providerMessageId,
            'sent_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markFailedOrRetry(
        string $id,
        string $leaseId,
        int $attempts,
        int $maxAttempts,
        string $error,
        ?string $nextAttemptAt
    ): ?string
    {
        $status = $attempts < $maxAttempts ? 'retry' : 'failed';
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = :status, attempts = :attempts, next_attempt_at = :next_attempt_at, last_error = :last_error,
                 updated_at = :updated_at, lease_id = NULL, lease_expires_at = NULL
             WHERE id = :id AND status = "processing" AND lease_id = :lease_id'
        );
        $stmt->execute([
            'id' => $id,
            'lease_id' => $leaseId,
            'status' => $status,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'last_error' => mb_substr($error, 0, 2000),
            'updated_at' => self::now(),
        ]);

        return $stmt->rowCount() === 1 ? $status : null;
    }

    public function releaseStaleProcessing(string $olderThan, string $error): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = CASE WHEN attempts + 1 >= max_attempts THEN "failed" ELSE "retry" END,
                 attempts = attempts + 1,
                 next_attempt_at = CASE WHEN attempts + 1 >= max_attempts THEN NULL ELSE :next_attempt_at END,
                 last_error = :last_error,
                 updated_at = :updated_at,
                 lease_id = NULL,
                 lease_expires_at = NULL
             WHERE status = "processing"
               AND (lease_expires_at < :lease_older_than OR (lease_expires_at IS NULL AND updated_at < :updated_older_than))'
        );
        $now = self::now();
        $stmt->execute([
            'next_attempt_at' => $now,
            'last_error' => mb_substr($error, 0, 2000),
            'updated_at' => $now,
            'lease_older_than' => $olderThan,
            'updated_older_than' => $olderThan,
        ]);

        return $stmt->rowCount();
    }

    /** @return list<array{id: string, status: string}> */
    private function claimWithSkipLocked(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status
             FROM email_queue
             WHERE status = "pending" OR (status = "retry" AND next_attempt_at <= CURRENT_TIMESTAMP)
             ORDER BY priority = "high" DESC, created_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array{id: string, status: string}> */
    private function claimWithFallback(int $limit): array
    {
        $lockClause = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $this->pdo->prepare(
            'SELECT id, status
             FROM email_queue
             WHERE status = "pending" OR (status = "retry" AND next_attempt_at <= CURRENT_TIMESTAMP)
             ORDER BY priority = "high" DESC, created_at ASC
             LIMIT :limit' . $lockClause
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param list<string> $ids */
    private function setProcessing(array $ids, string $leaseId, string $leaseExpiresAt): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE email_queue
             SET status = 'processing', lease_id = ?, lease_expires_at = ?, updated_at = ?
             WHERE id IN ($placeholders) AND (status = 'pending' OR (status = 'retry' AND next_attempt_at <= CURRENT_TIMESTAMP))"
        );
        $stmt->execute([$leaseId, $leaseExpiresAt, self::now(), ...$ids]);
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function fetchByIds(array $ids, string $leaseId): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM email_queue
             WHERE id IN ($placeholders) AND status = 'processing' AND lease_id = ?
             ORDER BY priority = 'high' DESC, created_at ASC"
        );
        $stmt->execute([...$ids, $leaseId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotencyKey(string $sourceApp, ?string $idempotencyKey): ?array
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, status, request_hash
             FROM email_queue
             WHERE source_app = :source_app AND idempotency_key = :idempotency_key'
        );
        $stmt->execute([
            'source_app' => $sourceApp,
            'idempotency_key' => $idempotencyKey,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    private static function requestHash(array $data, ?string $metadata): string
    {
        return hash('sha256', json_encode([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'html' => $data['html'],
            'text' => $data['text'],
            'priority' => $data['priority'],
            'metadata' => $metadata,
            'maxAttempts' => $data['maxAttempts'] ?? 5,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
