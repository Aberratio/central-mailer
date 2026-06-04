<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Support;

use CentralMailer\Queue\EmailQueueRepository;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected EmailQueueRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->repository = new EmailQueueRepository($this->pdo);
    }

    /** @param array<string, mixed> $overrides */
    protected function insertQueueRow(array $overrides = []): string
    {
        $id = $overrides['id'] ?? uniqid('email-', true);
        $row = [
            'id' => $id,
            'source_app' => 'app-a',
            'recipient_email' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html_body' => '<p>Body</p>',
            'text_body' => 'Body',
            'priority' => 'normal',
            'metadata' => null,
            'status' => 'pending',
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
             (id, source_app, recipient_email, subject, html_body, text_body, priority, metadata, status,
              attempts, max_attempts, next_attempt_at, last_error, provider_message_id, created_at, updated_at, sent_at)
             VALUES
             (:id, :source_app, :recipient_email, :subject, :html_body, :text_body, :priority, :metadata, :status,
              :attempts, :max_attempts, :next_attempt_at, :last_error, :provider_message_id, :created_at, :updated_at, :sent_at)'
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
                recipient_email TEXT NOT NULL,
                subject TEXT NOT NULL,
                html_body TEXT NOT NULL,
                text_body TEXT NULL,
                priority TEXT NOT NULL,
                metadata TEXT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                max_attempts INTEGER NOT NULL,
                next_attempt_at TEXT NULL,
                last_error TEXT NULL,
                provider_message_id TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                sent_at TEXT NULL
            )'
        );
    }
}
