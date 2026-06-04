<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Config\Env;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\EmailProviderInterface;
use CentralMailer\Email\EmailSendResult;
use CentralMailer\Queue\EmailWorker;
use CentralMailer\Queue\RateLimiter;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Support\Uuid;
use CentralMailer\Tests\Support\DatabaseTestCase;
use Psr\Log\NullLogger;

final class EmailWorkerTest extends DatabaseTestCase
{
    public function testReleasesStaleProcessingBeforeCheckingRateLimit(): void
    {
        $id = $this->insertQueueRow([
            'status' => 'processing',
            'lease_id' => 'stale-lease',
            'lease_expires_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
        $env = new Env([
            'EMAIL_PROCESSING_TIMEOUT_SECONDS' => '120',
            'EMAIL_RATE_LIMIT_COUNT' => '0',
        ]);
        $provider = new class implements EmailProviderInterface {
            public function send(EmailMessage $message): EmailSendResult
            {
                throw new \LogicException('Provider should not be called');
            }
        };
        $worker = new EmailWorker(
            $this->repository,
            $provider,
            new RateLimiter(new RateLimitRepository($this->pdo), $env),
            new NullLogger(),
            $env,
            $this->attachmentStorage
        );

        $worker->runOnce();

        $row = $this->fetchQueueRow($id);
        self::assertSame('retry', $row['status']);
        self::assertSame(1, $row['attempts']);
        self::assertSame('Email processing timed out after 120 seconds', $row['last_error']);
    }

    public function testReturnsClaimToQueueWhenRateLimitIsExhausted(): void
    {
        $id = $this->insertQueueRow();
        $env = new Env([
            'EMAIL_RATE_LIMIT_COUNT' => '0',
            'EMAIL_WORKER_BATCH_SIZE' => '1',
        ]);
        $provider = new class implements EmailProviderInterface {
            public function send(EmailMessage $message): EmailSendResult
            {
                throw new \LogicException('Provider should not be called');
            }
        };
        $worker = new EmailWorker(
            $this->repository,
            $provider,
            new RateLimiter(new RateLimitRepository($this->pdo), $env),
            new NullLogger(),
            $env,
            $this->attachmentStorage
        );

        $worker->runOnce();

        $row = $this->fetchQueueRow($id);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['lease_id']);
        self::assertSame(0, $row['attempts']);
    }

    public function testSendsAttachmentRecordsEventAndCleansLocalFile(): void
    {
        $emailId = Uuid::v4();
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true
        );
        $attachments = $this->attachmentStorage->store($emailId, [[
            'filename' => 'qr.png',
            'contentType' => 'image/png',
            'content' => $content,
            'sizeBytes' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]]);
        $this->repository->insert([
            'id' => $emailId,
            'sourceApp' => 'app-a',
            'idempotencyKey' => null,
            'to' => 'recipient@deliverable.test',
            'subject' => 'QR',
            'html' => '<p>QR</p>',
            'text' => null,
            'priority' => 'normal',
            'metadata' => null,
            'attachments' => $attachments,
        ]);
        $path = $this->attachmentStorage->absolutePath($attachments[0]['storagePath']);
        $provider = new class implements EmailProviderInterface {
            public int $attachmentCount = 0;

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->attachmentCount = count($message->attachments);

                return new EmailSendResult('<message@mailer.test>');
            }
        };
        $env = new Env(['EMAIL_WORKER_BATCH_SIZE' => '1']);
        $worker = new EmailWorker(
            $this->repository,
            $provider,
            new RateLimiter(new RateLimitRepository($this->pdo), $env),
            new NullLogger(),
            $env,
            $this->attachmentStorage
        );

        $worker->runOnce();

        self::assertSame(1, $provider->attachmentCount);
        self::assertSame('sent', $this->fetchQueueRow($emailId)['status']);
        self::assertFileDoesNotExist($path);
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_events WHERE email_id = '$emailId' AND event_type = 'sent'"
        )->fetchColumn());
    }
}
