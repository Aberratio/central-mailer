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
            $env
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
            $env
        );

        $worker->runOnce();

        $row = $this->fetchQueueRow($id);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['lease_id']);
        self::assertSame(0, $row['attempts']);
    }
}
