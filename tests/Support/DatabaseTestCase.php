<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Support;

use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Attachment\AttachmentStorage;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected EmailQueueRepository $repository;
    protected AttachmentStorage $attachmentStorage;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->repository = new EmailQueueRepository($this->pdo);
        $this->attachmentStorage = new AttachmentStorage(dirname(__DIR__, 2) . '/storage/attachments');
    }

    /** @param array<string, mixed> $overrides */
    protected function insertQueueRow(array $overrides = []): string
    {
        $id = $overrides['id'] ?? uniqid('email-', true);
        $row = [
            'id' => $id,
            'source_app' => 'app-a',
            'idempotency_key' => null,
            'request_hash' => null,
            'message_id' => null,
            'batch_id' => null,
            'recipient_email' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html_body' => '<p>Body</p>',
            'text_body' => 'Body',
            'priority' => 'normal',
            'category' => 'transactional',
            'metadata' => null,
            'status' => 'pending',
            'lease_id' => null,
            'lease_expires_at' => null,
            'attempts' => 0,
            'max_attempts' => 5,
            'next_attempt_at' => null,
            'last_error' => null,
            'provider_message_id' => null,
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
            'sent_at' => null,
            ...$overrides,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue
             (id, source_app, idempotency_key, request_hash, message_id, batch_id, recipient_email, subject, html_body, text_body, priority, category, metadata, status,
              lease_id, lease_expires_at, attempts, max_attempts, next_attempt_at, last_error, provider_message_id, created_at, updated_at, sent_at)
             VALUES
             (:id, :source_app, :idempotency_key, :request_hash, :message_id, :batch_id, :recipient_email, :subject, :html_body, :text_body, :priority, :category, :metadata, :status,
              :lease_id, :lease_expires_at, :attempts, :max_attempts, :next_attempt_at, :last_error, :provider_message_id, :created_at, :updated_at, :sent_at)'
        );
        $stmt->execute($row);

        return (string) $id;
    }

    /** @return array<string, mixed> */
    protected function fetchQueueRow(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_queue WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE email_queue (
                id TEXT PRIMARY KEY,
                source_app TEXT NOT NULL,
                idempotency_key TEXT NULL,
                request_hash TEXT NULL,
                message_id TEXT NULL,
                batch_id TEXT NULL,
                recipient_email TEXT NOT NULL,
                subject TEXT NULL,
                html_body TEXT NULL,
                text_body TEXT NULL,
                priority TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT \'transactional\',
                metadata TEXT NULL,
                status TEXT NOT NULL,
                lease_id TEXT NULL,
                lease_expires_at TEXT NULL,
                attempts INTEGER NOT NULL,
                max_attempts INTEGER NOT NULL,
                next_attempt_at TEXT NULL,
                last_error TEXT NULL,
                provider_message_id TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                sent_at TEXT NULL,
                UNIQUE (source_app, idempotency_key)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE email_clients (
                source_app TEXT PRIMARY KEY,
                api_key_hash TEXT NOT NULL UNIQUE,
                active INTEGER NOT NULL,
                queue_weight INTEGER NOT NULL,
                queue_credit INTEGER NOT NULL,
                rate_limit_count INTEGER NULL,
                rate_limit_window_minutes INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            "INSERT INTO email_clients
             (source_app, api_key_hash, active, queue_weight, queue_credit, created_at, updated_at)
             VALUES
             ('app-a', 'hash-a', 1, 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
             ('app-b', 'hash-b', 1, 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        $this->pdo->exec(
            'CREATE TABLE email_messages (
                id TEXT PRIMARY KEY,
                source_app TEXT NOT NULL,
                subject TEXT NOT NULL,
                html_body TEXT NOT NULL,
                text_body TEXT NULL,
                metadata TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE email_batches (
                id TEXT PRIMARY KEY,
                source_app TEXT NOT NULL,
                idempotency_key TEXT NULL,
                request_hash TEXT NULL,
                message_id TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (source_app, idempotency_key)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE email_attachments (
                id TEXT PRIMARY KEY,
                email_id TEXT NOT NULL,
                filename TEXT NOT NULL,
                content_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                sha256 TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                content_id TEXT NULL,
                deleted_at TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE email_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                status TEXT NOT NULL,
                attempt INTEGER NOT NULL,
                error_code TEXT NULL,
                error_message TEXT NULL,
                provider_message_id TEXT NULL,
                details TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE email_rate_limit_lock (
                id INTEGER PRIMARY KEY,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec("INSERT INTO email_rate_limit_lock (id, updated_at) VALUES (1, '2026-01-01 00:00:00')");
        $this->pdo->exec(
            'CREATE TABLE email_rate_limit_reservations (
                id TEXT PRIMARY KEY,
                source_app TEXT NOT NULL,
                reserved_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE email_enqueue_rate_limit_lock (
                id INTEGER PRIMARY KEY,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec("INSERT INTO email_enqueue_rate_limit_lock (id, updated_at) VALUES (1, '2026-01-01 00:00:00')");
        $this->pdo->exec(
            'CREATE TABLE email_enqueue_rate_limit_reservations (
                id TEXT PRIMARY KEY,
                source_app TEXT NOT NULL,
                reserved_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE email_suppressions (
                id TEXT PRIMARY KEY,
                email TEXT NOT NULL,
                source_app TEXT NOT NULL DEFAULT '',
                reason TEXT NOT NULL,
                applies_to TEXT NOT NULL DEFAULT 'all',
                origin_email_id TEXT NULL,
                details TEXT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (email, source_app, applies_to)
            )"
        );
        $this->pdo->exec(
            'CREATE TABLE email_worker_heartbeats (
                worker_id TEXT PRIMARY KEY,
                queue TEXT NOT NULL,
                host TEXT NOT NULL,
                process_id INTEGER NULL,
                started_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                last_processed_at TEXT NULL,
                last_error TEXT NULL,
                processed_count INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            )'
        );
    }
}
