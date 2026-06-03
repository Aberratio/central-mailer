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
        $remaining = $this->rateLimiter->remaining();
        if ($remaining <= 0) {
            $this->logger->info('Email rate limit reached', [
                'limit' => $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100),
                'windowMinutes' => $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15),
            ]);

            return;
        }

        $batchSize = min($this->env->int('EMAIL_WORKER_BATCH_SIZE', 20), $remaining);
        $emails = $this->repository->claimBatch($batchSize);

        foreach ($emails as $row) {
            $this->sendOne($row);
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

            $this->repository->markSent($row['id'], $result->providerMessageId);
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
                $attempts,
                $maxAttempts,
                $exception->getMessage(),
                $nextAttemptAt
            );

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
}
