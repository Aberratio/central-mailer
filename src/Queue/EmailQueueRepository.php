<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Support\Uuid;
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
        $id = $data['id'] ?? Uuid::v4();
        $now = self::now();
        $metadata = $data['metadata'] === null ? null : json_encode($data['metadata'], JSON_THROW_ON_ERROR);
        $requestHash = self::requestHash($data, $metadata);
        $existing = $this->findByIdempotencyKey((string) $data['sourceApp'], $data['idempotencyKey']);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new IdempotencyConflictException('Idempotency-Key was already used for a different email');
            }

            return new EnqueueResult((string) $existing['id'], (string) $existing['status'], false);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue
            (id, source_app, idempotency_key, request_hash, recipient_email, subject, html_body, text_body, priority, metadata, status, attempts, max_attempts, created_at, updated_at)
            VALUES
            (:id, :source_app, :idempotency_key, :request_hash, :recipient_email, :subject, :html_body, :text_body, :priority, :metadata, "pending", 0, :max_attempts, :created_at, :updated_at)'
        );

        $this->pdo->beginTransaction();
        try {
            $this->assertCapacity(
                (string) $data['sourceApp'],
                1,
                array_sum(array_column($data['attachments'] ?? [], 'sizeBytes')),
                (int) ($data['maxQueuedEmailsPerClient'] ?? 10_000),
                (int) ($data['maxActiveAttachmentBytesPerClient'] ?? 100_000_000),
                true
            );
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
            $this->insertAttachments($id, $data['attachments'] ?? [], $now);
            $this->insertEvent($id, 'queued', 'pending', 0, null, null, null, null, $now);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $existing = $this->findByIdempotencyKey((string) $data['sourceApp'], $data['idempotencyKey']);
            if ($existing === null) {
                throw $exception;
            }

            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new IdempotencyConflictException('Idempotency-Key was already used for a different email');
            }

            return new EnqueueResult((string) $existing['id'], (string) $existing['status'], false);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return new EnqueueResult($id, 'pending', true);
    }

    /** @param array<string, mixed> $data */
    public function insertBatch(array $data): BatchEnqueueResult
    {
        $batchId = $data['id'] ?? Uuid::v4();
        $messageId = Uuid::v4();
        $now = self::now();
        $messageMetadata = $data['metadata'] === null ? null : json_encode($data['metadata'], JSON_THROW_ON_ERROR);
        $requestHash = self::batchRequestHash($data, $messageMetadata);
        $existing = $this->findBatchByIdempotencyKey((string) $data['sourceApp'], $data['idempotencyKey']);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new IdempotencyConflictException('Idempotency-Key was already used for a different batch');
            }

            return new BatchEnqueueResult((string) $existing['id'], $this->findBatchEmails((string) $existing['id']), false);
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertCapacity(
                (string) $data['sourceApp'],
                count($data['recipients']),
                0,
                (int) ($data['maxQueuedEmailsPerClient'] ?? 10_000),
                (int) ($data['maxActiveAttachmentBytesPerClient'] ?? 100_000_000),
                true
            );
            $messageStmt = $this->pdo->prepare(
                'INSERT INTO email_messages
                 (id, source_app, subject, html_body, text_body, metadata, created_at)
                 VALUES
                 (:id, :source_app, :subject, :html_body, :text_body, :metadata, :created_at)'
            );
            $messageStmt->execute([
                'id' => $messageId,
                'source_app' => $data['sourceApp'],
                'subject' => $data['subject'],
                'html_body' => $data['html'],
                'text_body' => $data['text'],
                'metadata' => $messageMetadata,
                'created_at' => $now,
            ]);

            $batchStmt = $this->pdo->prepare(
                'INSERT INTO email_batches
                 (id, source_app, idempotency_key, request_hash, message_id, created_at)
                 VALUES
                 (:id, :source_app, :idempotency_key, :request_hash, :message_id, :created_at)'
            );
            $batchStmt->execute([
                'id' => $batchId,
                'source_app' => $data['sourceApp'],
                'idempotency_key' => $data['idempotencyKey'],
                'request_hash' => $requestHash,
                'message_id' => $messageId,
                'created_at' => $now,
            ]);

            $queueStmt = $this->pdo->prepare(
                'INSERT INTO email_queue
                 (id, source_app, idempotency_key, request_hash, message_id, batch_id, recipient_email, subject, html_body,
                  text_body, priority, metadata, status, attempts, max_attempts, created_at, updated_at)
                 VALUES
                 (:id, :source_app, :idempotency_key, :request_hash, :message_id, :batch_id, :recipient_email, NULL, NULL,
                  NULL, :priority, :metadata, "pending", 0, :max_attempts, :created_at, :updated_at)'
            );
            $emails = [];
            foreach ($data['recipients'] as $index => $recipient) {
                $emailId = Uuid::v4();
                $recipientMetadata = $recipient['metadata'] === null
                    ? null
                    : json_encode($recipient['metadata'], JSON_THROW_ON_ERROR);
                $queueStmt->execute([
                    'id' => $emailId,
                    'source_app' => $data['sourceApp'],
                    'idempotency_key' => $data['idempotencyKey'] === null ? null : sprintf('batch:%s:%d', $batchId, $index),
                    'request_hash' => hash('sha256', $requestHash . ':' . $index),
                    'message_id' => $messageId,
                    'batch_id' => $batchId,
                    'recipient_email' => $recipient['to'],
                    'priority' => $data['priority'],
                    'metadata' => $recipientMetadata,
                    'max_attempts' => $data['maxAttempts'] ?? 5,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->insertEvent($emailId, 'queued', 'pending', 0, null, null, null, ['batchId' => $batchId], $now);
                $emails[] = ['id' => $emailId, 'status' => 'pending'];
            }
            $this->pdo->commit();

            return new BatchEnqueueResult($batchId, $emails, true);
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $existing = $this->findBatchByIdempotencyKey((string) $data['sourceApp'], $data['idempotencyKey']);
            if ($existing === null) {
                throw $exception;
            }
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new IdempotencyConflictException('Idempotency-Key was already used for a different batch');
            }

            return new BatchEnqueueResult((string) $existing['id'], $this->findBatchEmails((string) $existing['id']), false);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function findForSourceApp(string $id, string $sourceApp): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.status, q.source_app, q.recipient_email, COALESCE(q.subject, m.subject) AS subject, q.priority,
                    q.attempts, q.last_error, q.provider_message_id, q.created_at, q.sent_at, q.batch_id
             FROM email_queue q
             LEFT JOIN email_messages m ON m.id = q.message_id
             WHERE q.id = :id AND q.source_app = :source_app'
        );
        $stmt->execute(['id' => $id, 'source_app' => $sourceApp]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function assertCanEnqueue(
        string $sourceApp,
        int $emailCount,
        int $attachmentBytes,
        int $maxQueuedEmails,
        int $maxActiveAttachmentBytes
    ): void {
        $this->assertCapacity(
            $sourceApp,
            $emailCount,
            $attachmentBytes,
            $maxQueuedEmails,
            $maxActiveAttachmentBytes,
            false
        );
    }

    /** @return list<array<string, mixed>> */
    public function claimBatch(int $limit, int $leaseSeconds, int $priorityAgingSeconds, string $queue = 'standard'): array
    {
        if (!in_array($queue, ['standard', 'technical'], true)) {
            throw new \InvalidArgumentException('Queue must be standard or technical');
        }

        $leaseId = Uuid::v4();
        $leaseExpiresAt = (new \DateTimeImmutable(sprintf('+%d seconds', $leaseSeconds)))->format('Y-m-d H:i:s');
        $agingCutoff = (new \DateTimeImmutable(sprintf('-%d seconds', max(0, $priorityAgingSeconds))))->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();

        try {
            if ($queue === 'standard') {
                $this->addQueueCredits();
            }
            $claimed = $queue === 'technical'
                ? $this->claimTechnicalWithSkipLocked()
                : $this->claimWithSkipLocked($limit, $agingCutoff);
            $ids = array_column($claimed, 'id');
            if ($ids !== []) {
                $this->setProcessing($ids, $leaseId, $leaseExpiresAt);
                if ($queue === 'standard') {
                    $this->spendQueueCredits($claimed);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            $this->pdo->beginTransaction();
            try {
                if ($queue === 'standard') {
                    $this->addQueueCredits();
                }
                $claimed = $queue === 'technical'
                    ? $this->claimTechnicalWithFallback()
                    : $this->claimWithFallback($limit, $agingCutoff);
                $ids = array_column($claimed, 'id');
                if ($ids !== []) {
                    $this->setProcessing($ids, $leaseId, $leaseExpiresAt);
                    if ($queue === 'standard') {
                        $this->spendQueueCredits($claimed);
                    }
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

        $this->pdo->beginTransaction();
        try {
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
            $released = $stmt->rowCount() === 1;
            if ($released) {
                $this->insertEvent($id, 'rate_limited', $previousStatus, 0, null, null, null, null);
            }
            $this->pdo->commit();

            return $released;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markSent(string $id, string $leaseId, ?string $providerMessageId, int $attempt = 0): bool
    {
        $this->pdo->beginTransaction();
        try {
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
            $marked = $stmt->rowCount() === 1;
            if ($marked) {
                $this->insertEvent($id, 'sent', 'sent', $attempt, null, null, $providerMessageId, null, $now);
            }
            $this->pdo->commit();

            return $marked;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markFailedOrRetry(
        string $id,
        string $leaseId,
        int $attempts,
        int $maxAttempts,
        string $error,
        ?string $nextAttemptAt,
        ?string $errorCode = null
    ): ?string
    {
        $status = $attempts < $maxAttempts ? 'retry' : 'failed';
        $this->pdo->beginTransaction();
        try {
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
            $marked = $stmt->rowCount() === 1;
            if ($marked) {
                $this->insertEvent(
                    $id,
                    $status,
                    $status,
                    $attempts,
                    $errorCode,
                    $error,
                    null,
                    ['nextAttemptAt' => $nextAttemptAt]
                );
            }
            $this->pdo->commit();

            return $marked ? $status : null;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function fallbackTechnicalToStandard(
        string $id,
        string $leaseId,
        int $attempts,
        string $error,
        ?string $errorCode = null
    ): bool {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE email_queue
                 SET priority = "normal", status = "pending", attempts = 0, next_attempt_at = NULL, last_error = :last_error,
                     updated_at = :updated_at, lease_id = NULL, lease_expires_at = NULL
                 WHERE id = :id AND priority = "technical" AND status = "processing" AND lease_id = :lease_id'
            );
            $stmt->execute([
                'id' => $id,
                'lease_id' => $leaseId,
                'last_error' => mb_substr($error, 0, 2000),
                'updated_at' => self::now(),
            ]);
            $marked = $stmt->rowCount() === 1;
            if ($marked) {
                $this->insertEvent(
                    $id,
                    'technical_fallback',
                    'pending',
                    $attempts,
                    $errorCode,
                    $error,
                    null,
                    ['fromPriority' => 'technical', 'toPriority' => 'normal']
                );
            }
            $this->pdo->commit();

            return $marked;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function releaseStaleProcessing(string $olderThan, string $error): int
    {
        $lockClause = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT id, attempts, max_attempts
                 FROM email_queue
                 WHERE status = "processing"
                   AND (lease_expires_at < :lease_older_than OR (lease_expires_at IS NULL AND updated_at < :updated_older_than))' . $lockClause
            );
            $select->execute([
                'lease_older_than' => $olderThan,
                'updated_older_than' => $olderThan,
            ]);
            $staleRows = $select->fetchAll();

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
            foreach ($staleRows as $row) {
                $attempts = ((int) $row['attempts']) + 1;
                $status = $attempts >= (int) $row['max_attempts'] ? 'failed' : 'retry';
                $this->insertEvent(
                    (string) $row['id'],
                    'processing_timeout',
                    $status,
                    $attempts,
                    'processing_timeout',
                    $error,
                    null,
                    null,
                    $now
                );
            }
            $this->pdo->commit();

            return count($staleRows);
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return list<array{id: string, status: string, source_app: string}> */
    private function claimWithSkipLocked(int $limit, string $agingCutoff): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.status, q.source_app
             FROM email_queue q
             INNER JOIN email_clients c ON c.source_app = q.source_app AND c.active = 1
             WHERE q.priority <> "technical"
               AND (q.status = "pending" OR (q.status = "retry" AND q.next_attempt_at <= CURRENT_TIMESTAMP))
             ORDER BY (q.priority = "high" OR q.created_at <= :aging_cutoff) DESC, c.queue_credit DESC, q.created_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->bindValue('aging_cutoff', $agingCutoff);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array{id: string, status: string, source_app: string}> */
    private function claimWithFallback(int $limit, string $agingCutoff): array
    {
        $lockClause = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.status, q.source_app
             FROM email_queue q
             INNER JOIN email_clients c ON c.source_app = q.source_app AND c.active = 1
             WHERE q.priority <> "technical"
               AND (q.status = "pending" OR (q.status = "retry" AND q.next_attempt_at <= CURRENT_TIMESTAMP))
             ORDER BY (q.priority = "high" OR q.created_at <= :aging_cutoff) DESC, c.queue_credit DESC, q.created_at ASC
             LIMIT :limit' . $lockClause
        );
        $stmt->bindValue('aging_cutoff', $agingCutoff);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array{id: string, status: string, source_app: string}> */
    private function claimTechnicalWithSkipLocked(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.status, q.source_app
             FROM email_queue q
             INNER JOIN email_clients c ON c.source_app = q.source_app AND c.active = 1
             WHERE q.priority = "technical"
               AND q.status IN ("pending", "retry")
               AND NOT EXISTS (
                   SELECT 1 FROM email_queue older
                   WHERE older.priority = "technical"
                     AND older.status IN ("pending", "retry", "processing")
                     AND (older.created_at < q.created_at OR (older.created_at = q.created_at AND older.id < q.id))
               )
               AND (q.status = "pending" OR q.next_attempt_at <= CURRENT_TIMESTAMP)
             ORDER BY q.created_at ASC, q.id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array{id: string, status: string, source_app: string}> */
    private function claimTechnicalWithFallback(): array
    {
        $lockClause = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.status, q.source_app
             FROM email_queue q
             INNER JOIN email_clients c ON c.source_app = q.source_app AND c.active = 1
             WHERE q.priority = "technical"
               AND q.status IN ("pending", "retry")
               AND NOT EXISTS (
                   SELECT 1 FROM email_queue older
                   WHERE older.priority = "technical"
                     AND older.status IN ("pending", "retry", "processing")
                     AND (older.created_at < q.created_at OR (older.created_at = q.created_at AND older.id < q.id))
               )
               AND (q.status = "pending" OR q.next_attempt_at <= CURRENT_TIMESTAMP)
             ORDER BY q.created_at ASC, q.id ASC
             LIMIT 1' . $lockClause
        );
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
        foreach ($ids as $id) {
            $this->insertEvent($id, 'processing', 'processing', 0, null, null, null, ['leaseId' => $leaseId]);
        }
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function fetchByIds(array $ids, string $leaseId): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT q.*,
                    COALESCE(q.subject, m.subject) AS resolved_subject,
                    COALESCE(q.html_body, m.html_body) AS resolved_html_body,
                    COALESCE(q.text_body, m.text_body) AS resolved_text_body,
                    c.rate_limit_count AS client_rate_limit_count,
                    c.rate_limit_window_minutes AS client_rate_limit_window_minutes
             FROM email_queue q
             LEFT JOIN email_messages m ON m.id = q.message_id
             INNER JOIN email_clients c ON c.source_app = q.source_app AND c.active = 1
             WHERE q.id IN ($placeholders) AND q.status = 'processing' AND q.lease_id = ?
             ORDER BY q.created_at ASC, q.id ASC"
        );
        $stmt->execute([...$ids, $leaseId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function findEventsForSourceApp(string $id, string $sourceApp): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.event_type, e.status, e.attempt, e.error_code, e.error_message, e.provider_message_id, e.details, e.created_at
             FROM email_events e
             INNER JOIN email_queue q ON q.id = e.email_id
             WHERE e.email_id = :id AND q.source_app = :source_app
             ORDER BY e.id ASC'
        );
        $stmt->execute(['id' => $id, 'source_app' => $sourceApp]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function findAttachments(string $emailId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, content_type, size_bytes, sha256, storage_path
             FROM email_attachments
             WHERE email_id = :email_id AND deleted_at IS NULL
             ORDER BY created_at ASC'
        );
        $stmt->execute(['email_id' => $emailId]);

        return $stmt->fetchAll();
    }

    public function markAttachmentsDeleted(string $emailId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_attachments SET deleted_at = :deleted_at WHERE email_id = :email_id AND deleted_at IS NULL'
        );
        $stmt->execute(['email_id' => $emailId, 'deleted_at' => self::now()]);
    }

    /** @return list<string> */
    public function findTerminalAttachmentEmailIds(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.email_id
             FROM email_attachments a
             INNER JOIN email_queue q ON q.id = a.email_id
             WHERE a.deleted_at IS NULL AND q.status IN ("sent", "failed")
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'email_id');
    }

    private function addQueueCredits(): void
    {
        $this->pdo->exec(
            'UPDATE email_clients
             SET queue_credit = CASE
                 WHEN queue_credit + queue_weight > 1000000000 THEN 1000000000
                 ELSE queue_credit + queue_weight
             END
             WHERE active = 1'
        );
    }

    private function assertCapacity(
        string $sourceApp,
        int $emailCount,
        int $attachmentBytes,
        int $maxQueuedEmails,
        int $maxActiveAttachmentBytes,
        bool $lockClient
    ): void {
        if ($lockClient) {
            $lockClause = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $lock = $this->pdo->prepare(
                'SELECT source_app FROM email_clients WHERE source_app = :source_app' . $lockClause
            );
            $lock->execute(['source_app' => $sourceApp]);
            if ($lock->fetchColumn() === false) {
                throw new QueueCapacityExceededException('Email client is not active');
            }
        }

        $queued = $this->pdo->prepare(
            'SELECT COUNT(*) FROM email_queue
             WHERE source_app = :source_app AND status IN ("pending", "processing", "retry")'
        );
        $queued->execute(['source_app' => $sourceApp]);
        if ((int) $queued->fetchColumn() + $emailCount > $maxQueuedEmails) {
            throw new QueueCapacityExceededException('Email queue capacity for this client has been reached');
        }

        if ($attachmentBytes <= 0) {
            return;
        }

        $attachments = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.size_bytes), 0)
             FROM email_attachments a
             INNER JOIN email_queue q ON q.id = a.email_id
             WHERE q.source_app = :source_app AND a.deleted_at IS NULL'
        );
        $attachments->execute(['source_app' => $sourceApp]);
        if ((int) $attachments->fetchColumn() + $attachmentBytes > $maxActiveAttachmentBytes) {
            throw new QueueCapacityExceededException('Active attachment storage capacity for this client has been reached');
        }
    }

    /** @param list<array{id: string, status: string, source_app: string}> $claimed */
    private function spendQueueCredits(array $claimed): void
    {
        $totalWeight = (int) $this->pdo->query(
            'SELECT COALESCE(SUM(queue_weight), 1) FROM email_clients WHERE active = 1'
        )->fetchColumn();
        $counts = array_count_values(array_column($claimed, 'source_app'));
        $stmt = $this->pdo->prepare(
            'UPDATE email_clients
             SET queue_credit = queue_credit - :spent
             WHERE source_app = :source_app'
        );
        foreach ($counts as $sourceApp => $count) {
            $stmt->execute([
                'spent' => $totalWeight * $count,
                'source_app' => $sourceApp,
            ]);
        }
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

    /** @return array<string, mixed>|null */
    private function findBatchByIdempotencyKey(string $sourceApp, ?string $idempotencyKey): ?array
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, request_hash FROM email_batches
             WHERE source_app = :source_app AND idempotency_key = :idempotency_key'
        );
        $stmt->execute(['source_app' => $sourceApp, 'idempotency_key' => $idempotencyKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array{id: string, status: string}> */
    private function findBatchEmails(string $batchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status FROM email_queue WHERE batch_id = :batch_id ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['batch_id' => $batchId]);

        return $stmt->fetchAll();
    }

    /** @param list<array<string, mixed>> $attachments */
    private function insertAttachments(string $emailId, array $attachments, string $now): void
    {
        if ($attachments === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_attachments
             (id, email_id, filename, content_type, size_bytes, sha256, storage_path, created_at)
             VALUES
             (:id, :email_id, :filename, :content_type, :size_bytes, :sha256, :storage_path, :created_at)'
        );
        foreach ($attachments as $attachment) {
            $stmt->execute([
                'id' => $attachment['id'],
                'email_id' => $emailId,
                'filename' => $attachment['filename'],
                'content_type' => $attachment['contentType'],
                'size_bytes' => $attachment['sizeBytes'],
                'sha256' => $attachment['sha256'],
                'storage_path' => $attachment['storagePath'],
                'created_at' => $now,
            ]);
        }
    }

    /** @param array<string, mixed>|null $details */
    private function insertEvent(
        string $emailId,
        string $eventType,
        string $status,
        int $attempt,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $providerMessageId,
        ?array $details,
        ?string $createdAt = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_events
             (email_id, event_type, status, attempt, error_code, error_message, provider_message_id, details, created_at)
             VALUES
             (:email_id, :event_type, :status, :attempt, :error_code, :error_message, :provider_message_id, :details, :created_at)'
        );
        $stmt->execute([
            'email_id' => $emailId,
            'event_type' => $eventType,
            'status' => $status,
            'attempt' => $attempt,
            'error_code' => $errorCode,
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 2000),
            'provider_message_id' => $providerMessageId,
            'details' => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $createdAt ?? self::now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function requestHash(array $data, ?string $metadata): string
    {
        $attachments = array_map(
            static fn (array $attachment): array => [
                'filename' => $attachment['filename'],
                'contentType' => $attachment['contentType'],
                'sizeBytes' => $attachment['sizeBytes'],
                'sha256' => $attachment['sha256'],
            ],
            $data['attachments'] ?? []
        );

        return hash('sha256', json_encode([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'html' => $data['html'],
            'text' => $data['text'],
            'priority' => $data['priority'],
            'metadata' => $metadata,
            'attachments' => $attachments,
            'maxAttempts' => $data['maxAttempts'] ?? 5,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $data */
    private static function batchRequestHash(array $data, ?string $metadata): string
    {
        return hash('sha256', json_encode([
            'subject' => $data['subject'],
            'html' => $data['html'],
            'text' => $data['text'],
            'priority' => $data['priority'],
            'metadata' => $metadata,
            'recipients' => $data['recipients'],
            'maxAttempts' => $data['maxAttempts'] ?? 5,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

}
