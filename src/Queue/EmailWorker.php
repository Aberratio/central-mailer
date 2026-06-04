<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Config\Env;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\EmailProviderInterface;
use Psr\Log\LoggerInterface;

final class EmailWorker
{
    public function __construct(
        private readonly EmailQueueRepository $repository,
        private readonly EmailProviderInterface $provider,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly Env $env
    ) {
    }

    public function runOnce(): void
    {
        $this->releaseStaleProcessing();

        $batchSize = $this->env->int('EMAIL_WORKER_BATCH_SIZE', 20);
        $leaseSeconds = $this->processingTimeoutSeconds();
        for ($i = 0; $i < $batchSize; $i++) {
            $emails = $this->repository->claimBatch(1, $leaseSeconds);
            if ($emails === []) {
                return;
            }

            if (!$this->rateLimiter->acquire()) {
                $this->repository->releaseClaim(
                    (string) $emails[0]['id'],
                    (string) $emails[0]['lease_id'],
                    (string) $emails[0]['_previous_status']
                );
                $this->logger->info('Email rate limit reached', [
                    'limit' => $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100),
                    'windowMinutes' => $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15),
                ]);

                return;
            }

            $this->sendOne($emails[0]);
        }
    }

    /** @param array<string, mixed> $row */
    private function sendOne(array $row): void
    {
        $this->logger->info('Attempting email send', [
            'id' => $row['id'],
            'sourceApp' => $row['source_app'],
            'recipient' => $row['recipient_email'],
            'attempts' => (int) $row['attempts'],
        ]);

        try {
            $result = $this->provider->send(new EmailMessage(
                $row['id'],
                $row['recipient_email'],
                $row['subject'],
                $row['html_body'],
                $row['text_body']
            ));

            $marked = $this->repository->markSent($row['id'], $row['lease_id'], $result->providerMessageId);
            if (!$marked) {
                $this->logger->critical('Email was accepted by provider after its processing lease was lost', [
                    'id' => $row['id'],
                    'sourceApp' => $row['source_app'],
                    'providerMessageId' => $result->providerMessageId,
                ]);

                return;
            }

            $this->logger->info('Email sent', [
                'id' => $row['id'],
                'sourceApp' => $row['source_app'],
                'providerMessageId' => $result->providerMessageId,
                'finalStatus' => 'sent',
            ]);
        } catch (\Throwable $exception) {
            $attempts = ((int) $row['attempts']) + 1;
            $maxAttempts = (int) $row['max_attempts'];
            $nextAttemptAt = $attempts < $maxAttempts ? $this->nextAttemptAt($attempts) : null;
            $finalStatus = $this->repository->markFailedOrRetry(
                $row['id'],
                $row['lease_id'],
                $attempts,
                $maxAttempts,
                $exception->getMessage(),
                $nextAttemptAt
            );

            if ($finalStatus === null) {
                $this->logger->warning('Email send failed after its processing lease was lost', [
                    'id' => $row['id'],
                    'sourceApp' => $row['source_app'],
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            $this->logger->warning('Email send failed', [
                'id' => $row['id'],
                'sourceApp' => $row['source_app'],
                'attempts' => $attempts,
                'maxAttempts' => $maxAttempts,
                'finalStatus' => $finalStatus,
                'nextAttemptAt' => $nextAttemptAt,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function nextAttemptAt(int $attempts): string
    {
        $delaySeconds = min(3600, 60 * (2 ** max(0, $attempts - 1)));

        return (new \DateTimeImmutable(sprintf('+%d seconds', $delaySeconds)))->format('Y-m-d H:i:s');
    }

    private function releaseStaleProcessing(): void
    {
        $timeoutSeconds = $this->processingTimeoutSeconds();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $released = $this->repository->releaseStaleProcessing(
            $now,
            sprintf('Email processing timed out after %d seconds', $timeoutSeconds)
        );

        if ($released > 0) {
            $this->logger->warning('Released stale processing emails', [
                'count' => $released,
                'timeoutSeconds' => $timeoutSeconds,
            ]);
        }
    }

    private function processingTimeoutSeconds(): int
    {
        return max(
            1,
            $this->env->int('EMAIL_PROCESSING_TIMEOUT_SECONDS', 300),
            $this->env->int('SMTP_TIMEOUT_SECONDS', 30) + 30
        );
    }
}
