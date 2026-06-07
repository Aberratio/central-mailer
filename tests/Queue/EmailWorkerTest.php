<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Config\Env;
use CentralMailer\Email\EmailBrandConfig;
use CentralMailer\Email\EmailBranding;
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

    public function testStandardWorkerClaimsConfiguredBatchInOneLease(): void
    {
        $ids = [
            $this->insertQueueRow(['id' => 'batch-one', 'created_at' => '2026-01-01 10:00:00']),
            $this->insertQueueRow(['id' => 'batch-two', 'created_at' => '2026-01-01 10:01:00']),
            $this->insertQueueRow(['id' => 'batch-three', 'created_at' => '2026-01-01 10:02:00']),
        ];
        $provider = new class implements EmailProviderInterface {
            /** @var list<string> */
            public array $sentIds = [];

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->sentIds[] = $message->id;

                return new EmailSendResult('<' . $message->id . '@mailer.test>');
            }
        };

        $processed = $this->worker($provider, 'standard', ['EMAIL_WORKER_BATCH_SIZE' => '3'])->runOnce();

        self::assertSame(3, $processed);
        self::assertSame($ids, $provider->sentIds);
        foreach ($ids as $id) {
            self::assertSame('sent', $this->fetchQueueRow($id)['status']);
        }

        $eventRows = $this->pdo->query(
            "SELECT details FROM email_events WHERE event_type = 'processing' ORDER BY id ASC"
        )->fetchAll();
        $leaseIds = array_map(
            static fn (array $row): string => json_decode($row['details'], true, flags: JSON_THROW_ON_ERROR)['leaseId'],
            $eventRows
        );
        self::assertCount(1, array_unique($leaseIds));
    }

    public function testStandardWorkerReleasesUnsentBatchClaimsWhenRateLimitIsReached(): void
    {
        $firstId = $this->insertQueueRow(['id' => 'limited-one', 'created_at' => '2026-01-01 10:00:00']);
        $secondId = $this->insertQueueRow(['id' => 'limited-two', 'created_at' => '2026-01-01 10:01:00']);
        $thirdId = $this->insertQueueRow(['id' => 'limited-three', 'created_at' => '2026-01-01 10:02:00']);
        $provider = new class implements EmailProviderInterface {
            /** @var list<string> */
            public array $sentIds = [];

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->sentIds[] = $message->id;

                return new EmailSendResult('<' . $message->id . '@mailer.test>');
            }
        };

        $processed = $this->worker($provider, 'standard', [
            'EMAIL_WORKER_BATCH_SIZE' => '3',
            'EMAIL_RATE_LIMIT_COUNT' => '2',
        ])->runOnce();

        self::assertSame(2, $processed);
        self::assertSame([$firstId, $secondId], $provider->sentIds);
        self::assertSame('sent', $this->fetchQueueRow($firstId)['status']);
        self::assertSame('sent', $this->fetchQueueRow($secondId)['status']);

        $third = $this->fetchQueueRow($thirdId);
        self::assertSame('pending', $third['status']);
        self::assertNull($third['lease_id']);
        self::assertSame(0, $third['attempts']);
    }

    public function testAppliesGlobalBrandingBeforeSending(): void
    {
        $this->insertQueueRow([
            'html_body' => '<p>Body</p>',
            'text_body' => 'Body',
        ]);
        $provider = new class implements EmailProviderInterface {
            public ?EmailMessage $message = null;

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->message = $message;

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
            $this->attachmentStorage,
            'standard',
            new EmailBranding(new EmailBrandConfig(
                brandName: 'Example',
                footerHtml: '<div>Footer</div>',
                footerText: 'Footer'
            ))
        );

        $worker->runOnce();

        self::assertNotNull($provider->message);
        self::assertStringContainsString('Example', $provider->message->html);
        self::assertStringContainsString('Footer', $provider->message->html);
        self::assertSame("Body\n\n--\nFooter\nExample", $provider->message->text);
    }

    public function testStandardWorkerDoesNotSendTechnicalEmail(): void
    {
        $technicalId = $this->insertQueueRow(['priority' => 'technical']);
        $provider = new class implements EmailProviderInterface {
            public int $sendCount = 0;

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->sendCount++;

                return new EmailSendResult('<message@mailer.test>');
            }
        };

        $this->worker($provider)->runOnce();

        self::assertSame(0, $provider->sendCount);
        self::assertSame('pending', $this->fetchQueueRow($technicalId)['status']);
    }

    public function testTechnicalWorkerSendsOnlyTechnicalEmailsInFifoOrder(): void
    {
        $standardId = $this->insertQueueRow([
            'id' => 'standard-oldest',
            'priority' => 'normal',
            'created_at' => '2026-01-01 09:00:00',
        ]);
        $firstTechnicalId = $this->insertQueueRow([
            'id' => 'technical-first',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $secondTechnicalId = $this->insertQueueRow([
            'id' => 'technical-second',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:01:00',
        ]);
        $provider = new class implements EmailProviderInterface {
            /** @var list<string> */
            public array $sentIds = [];

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->sentIds[] = $message->id;

                return new EmailSendResult('<' . $message->id . '@gmail.test>');
            }
        };

        $this->worker($provider, 'technical', ['EMAIL_WORKER_BATCH_SIZE' => '2'])->runOnce();

        self::assertSame([$firstTechnicalId, $secondTechnicalId], $provider->sentIds);
        self::assertSame('pending', $this->fetchQueueRow($standardId)['status']);
        self::assertSame('sent', $this->fetchQueueRow($firstTechnicalId)['status']);
        self::assertSame('sent', $this->fetchQueueRow($secondTechnicalId)['status']);
    }

    public function testTechnicalWorkerRetryBlocksLaterTechnicalEmail(): void
    {
        $firstTechnicalId = $this->insertQueueRow([
            'id' => 'technical-failing',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $secondTechnicalId = $this->insertQueueRow([
            'id' => 'technical-blocked',
            'priority' => 'technical',
            'created_at' => '2026-01-01 10:01:00',
        ]);
        $provider = new class implements EmailProviderInterface {
            /** @var list<string> */
            public array $attemptedIds = [];

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->attemptedIds[] = $message->id;

                throw new \RuntimeException('Temporary Gmail SMTP failure');
            }
        };

        $this->worker($provider, 'technical', ['EMAIL_WORKER_BATCH_SIZE' => '2'])->runOnce();

        self::assertSame([$firstTechnicalId], $provider->attemptedIds);
        self::assertSame('retry', $this->fetchQueueRow($firstTechnicalId)['status']);
        self::assertSame('pending', $this->fetchQueueRow($secondTechnicalId)['status']);
    }

    public function testTechnicalWorkerFallsBackToStandardQueueAfterFinalFailure(): void
    {
        $technicalId = $this->insertQueueRow([
            'id' => 'technical-fallback',
            'priority' => 'technical',
            'max_attempts' => 1,
        ]);
        $failingProvider = new class implements EmailProviderInterface {
            public function send(EmailMessage $message): EmailSendResult
            {
                throw new \RuntimeException('Gmail SMTP is unavailable');
            }
        };

        $this->worker($failingProvider, 'technical')->runOnce();

        $fallbackRow = $this->fetchQueueRow($technicalId);
        self::assertSame('normal', $fallbackRow['priority']);
        self::assertSame('pending', $fallbackRow['status']);
        self::assertSame(0, $fallbackRow['attempts']);
        self::assertSame('Gmail SMTP is unavailable', $fallbackRow['last_error']);
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_events WHERE email_id = '$technicalId' AND event_type = 'technical_fallback'"
        )->fetchColumn());

        $standardProvider = new class implements EmailProviderInterface {
            /** @var list<string> */
            public array $sentIds = [];

            public function send(EmailMessage $message): EmailSendResult
            {
                $this->sentIds[] = $message->id;

                return new EmailSendResult('<standard@mailer.test>');
            }
        };
        $this->worker($standardProvider)->runOnce();

        self::assertSame([$technicalId], $standardProvider->sentIds);
        self::assertSame('sent', $this->fetchQueueRow($technicalId)['status']);
    }

    public function testTechnicalWorkerCanDisableFallbackToStandardQueue(): void
    {
        $technicalId = $this->insertQueueRow([
            'id' => 'technical-no-fallback',
            'priority' => 'technical',
            'max_attempts' => 1,
        ]);
        $provider = new class implements EmailProviderInterface {
            public function send(EmailMessage $message): EmailSendResult
            {
                throw new \RuntimeException('Gmail SMTP is unavailable');
            }
        };

        $this->worker($provider, 'technical', ['TECHNICAL_EMAIL_FALLBACK_TO_STANDARD' => 'false'])->runOnce();

        $row = $this->fetchQueueRow($technicalId);
        self::assertSame('technical', $row['priority']);
        self::assertSame('failed', $row['status']);
    }

    /**
     * @param array<string, string> $envValues
     */
    private function worker(
        EmailProviderInterface $provider,
        string $queue = 'standard',
        array $envValues = []
    ): EmailWorker {
        $env = new Env([
            'EMAIL_WORKER_BATCH_SIZE' => '1',
            ...$envValues,
        ]);

        return new EmailWorker(
            $this->repository,
            $provider,
            new RateLimiter(new RateLimitRepository($this->pdo), $env),
            new NullLogger(),
            $env,
            $this->attachmentStorage,
            $queue
        );
    }
}
