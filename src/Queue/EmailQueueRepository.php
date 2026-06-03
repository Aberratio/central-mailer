<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use PDO;

final class EmailQueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): string
    {
        $id = self::uuidV4();
        $now = self::now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue
            (id, source_app, recipient_email, subject, html_body, text_body, priority, metadata, status, attempts, max_attempts, created_at, updated_at)
            VALUES
            (:id, :source_app, :recipient_email, :subject, :html_body, :text_body, :priority, :metadata, "pending", 0, :max_attempts, :created_at, :updated_at)'
        );

        $stmt->execute([
            'id' => $id,
            'source_app' => $data['sourceApp'],
            'recipient_email' => $data['to'],
            'subject' => $data['subject'],
            'html_body' => $data['html'],
            'text_body' => $data['text'],
            'priority' => $data['priority'],
            'metadata' => $data['metadata'] === null ? null : json_encode($data['metadata'], JSON_THROW_ON_ERROR),
            'max_attempts' => $data['maxAttempts'] ?? 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findForSourceApp(string $id, string $sourceApp): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, source_app, recipient_email, subject, attempts, last_error, created_at, sent_at
             FROM email_queue
             WHERE id = :id AND source_app = :source_app'
        );
        $stmt->execute(['id' => $id, 'source_app' => $sourceApp]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function claimBatch(int $limit): array
    {
        $this->pdo->beginTransaction();

        try {
            $ids = $this->claimWithSkipLocked($limit);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            $this->pdo->beginTransaction();
            try {
                $ids = $this->claimWithFallback($limit);
                $this->pdo->commit();
            } catch (\Throwable $fallbackException) {
                $this->pdo->rollBack();
                throw $fallbackException;
            }
        }

        if ($ids === []) {
            return [];
        }

        return $this->fetchByIds($ids);
    }

    public function markSent(string $id, ?string $providerMessageId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = "sent", provider_message_id = :provider_message_id, sent_at = :sent_at, updated_at = :updated_at, last_error = NULL
             WHERE id = :id'
        );
        $now = self::now();
        $stmt->execute([
            'id' => $id,
            'provider_message_id' => $providerMessageId,
            'sent_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markFailedOrRetry(string $id, int $attempts, int $maxAttempts, string $error, ?string $nextAttemptAt): string
    {
        $status = $attempts < $maxAttempts ? 'retry' : 'failed';
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = :status, attempts = :attempts, next_attempt_at = :next_attempt_at, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'last_error' => mb_substr($error, 0, 2000),
            'updated_at' => self::now(),
        ]);

        return $status;
    }

    public function countSentSince(string $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE status = "sent" AND sent_at >= :since');
        $stmt->execute(['since' => $since]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function claimWithSkipLocked(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM email_queue
             WHERE status = "pending" OR (status = "retry" AND next_attempt_at <= NOW())
             ORDER BY priority = "high" DESC, created_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_column($stmt->fetchAll(), 'id');

        if ($ids !== []) {
            $this->setProcessing($ids);
        }

        return $ids;
    }

    /** @return list<string> */
    private function claimWithFallback(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM email_queue
             WHERE status = "pending" OR (status = "retry" AND next_attempt_at <= NOW())
             ORDER BY priority = "high" DESC, created_at ASC
             LIMIT :limit
             FOR UPDATE'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_column($stmt->fetchAll(), 'id');

        if ($ids === []) {
            return [];
        }

        $this->setProcessing($ids);

        return $ids;
    }

    /** @param list<string> $ids */
    private function setProcessing(array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE email_queue
             SET status = 'processing', updated_at = ?
             WHERE id IN ($placeholders) AND (status = 'pending' OR (status = 'retry' AND next_attempt_at <= NOW()))"
        );
        $stmt->execute([self::now(), ...$ids]);
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function fetchByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM email_queue
             WHERE id IN ($placeholders) AND status = 'processing'
             ORDER BY priority = 'high' DESC, created_at ASC"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
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
