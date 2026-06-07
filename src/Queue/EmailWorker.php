<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Config\Env;
use CentralMailer\Email\EmailAttachment;
use CentralMailer\Email\EmailBranding;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\EmailProviderInterface;
use Psr\Log\LoggerInterface;

final class EmailWorker
{
    private readonly EmailBranding $branding;

    public function __construct(
        private readonly EmailQueueRepository $repository,
        private readonly EmailProviderInterface $provider,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly Env $env,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly string $queue = 'standard',
        ?EmailBranding $branding = null
    ) {
        if (!in_array($this->queue, ['standard', 'technical'], true)) {
            throw new \InvalidArgumentException('Queue must be standard or technical');
        }

        $this->branding = $branding ?? new EmailBranding();
    }

    public function runOnce(): int
    {
        $this->releaseStaleProcessing();
        $this->cleanupTerminalAttachments();

        $batchSize = $this->env->int('EMAIL_WORKER_BATCH_SIZE', 20);
        $leaseSeconds = $this->processingTimeoutSeconds();
        $priorityAgingSeconds = $this->env->int('EMAIL_PRIORITY_AGING_SECONDS', 900);
        if ($this->queue === 'standard') {
            return $this->runStandardBatch($batchSize, $leaseSeconds, $priorityAgingSeconds);
        }

        $processed = 0;
        for ($i = 0; $i < $batchSize; $i++) {
            $emails = $this->repository->claimBatch(1, $leaseSeconds, $priorityAgingSeconds, $this->queue);
            if ($emails === []) {
                return $processed;
            }

            if (!$this->rateLimiter->acquire(
                (string) $emails[0]['source_app'],
                $emails[0]['client_rate_limit_count'] === null ? null : (int) $emails[0]['client_rate_limit_count'],
                $emails[0]['client_rate_limit_window_minutes'] === null ? null : (int) $emails[0]['client_rate_limit_window_minutes']
            )) {
                $this->repository->releaseClaim(
                    (string) $emails[0]['id'],
                    (string) $emails[0]['lease_id'],
                    (string) $emails[0]['_previous_status']
                );
                $this->logger->info('Email rate limit reached', [
                    'limit' => $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100),
                    'windowMinutes' => $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15),
                ]);

                return $processed;
            }

            $this->sendOne($emails[0]);
            $processed++;
        }

        return $processed;
    }

    private function runStandardBatch(int $batchSize, int $leaseSeconds, int $priorityAgingSeconds): int
    {
        $emails = $this->repository->claimBatch($batchSize, $leaseSeconds, $priorityAgingSeconds, $this->queue);
        if ($emails === []) {
            return 0;
        }

        $processed = 0;
        foreach ($emails as $index => $email) {
            if (!$this->rateLimiter->acquire(
                (string) $email['source_app'],
                $email['client_rate_limit_count'] === null ? null : (int) $email['client_rate_limit_count'],
                $email['client_rate_limit_window_minutes'] === null ? null : (int) $email['client_rate_limit_window_minutes']
            )) {
                $this->releaseRemainingClaims($emails, $index);
                $this->logger->info('Email rate limit reached', [
                    'limit' => $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100),
                    'windowMinutes' => $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15),
                    'releasedClaims' => count($emails) - $index,
                ]);

                return $processed;
            }

            $this->sendOne($email);
            $processed++;
        }

        return $processed;
    }

    /**
     * @param list<array<string, mixed>> $emails
     */
    private function releaseRemainingClaims(array $emails, int $startIndex): void
    {
        for ($i = $startIndex, $count = count($emails); $i < $count; $i++) {
            $this->repository->releaseClaim(
                (string) $emails[$i]['id'],
                (string) $emails[$i]['lease_id'],
                (string) $emails[$i]['_previous_status']
            );
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
            'queue' => $this->queue,
        ]);

        try {
            $attachments = array_map(
                fn (array $attachment): EmailAttachment => new EmailAttachment(
                    $this->attachmentStorage->absolutePath((string) $attachment['storage_path']),
                    (string) $attachment['filename'],
                    (string) $attachment['content_type']
                ),
                $this->repository->findAttachments((string) $row['id'])
            );
            $message = new EmailMessage(
                $row['id'],
                $row['recipient_email'],
                $row['resolved_subject'],
                $row['resolved_html_body'],
                $row['resolved_text_body'],
                $attachments
            );
            $result = $this->provider->send($this->branding->apply($message));

            $marked = $this->repository->markSent(
                $row['id'],
                $row['lease_id'],
                $result->providerMessageId,
                ((int) $row['attempts']) + 1
            );
            if (!$marked) {
                $this->logger->critical('Email was accepted by provider after its processing lease was lost', [
                    'id' => $row['id'],
                    'sourceApp' => $row['source_app'],
                    'providerMessageId' => $result->providerMessageId,
                ]);

                return;
            }
            $this->cleanupAttachments((string) $row['id']);

            $this->logger->info('Email sent', [
                'id' => $row['id'],
                'sourceApp' => $row['source_app'],
                'providerMessageId' => $result->providerMessageId,
                'finalStatus' => 'sent',
            ]);
        } catch (\Throwable $exception) {
            $attempts = ((int) $row['attempts']) + 1;
            $maxAttempts = (int) $row['max_attempts'];
            if ($this->shouldFallbackTechnicalToStandard($attempts, $maxAttempts)) {
                $fallback = $this->repository->fallbackTechnicalToStandard(
                    $row['id'],
                    $row['lease_id'],
                    $attempts,
                    $exception->getMessage(),
                    $exception::class
                );
                if (!$fallback) {
                    $this->logger->warning('Technical email fallback failed after its processing lease was lost', [
                        'id' => $row['id'],
                        'sourceApp' => $row['source_app'],
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                $this->logger->warning('Technical email moved to standard queue after send failures', [
                    'id' => $row['id'],
                    'sourceApp' => $row['source_app'],
                    'attempts' => $attempts,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            $nextAttemptAt = $attempts < $maxAttempts ? $this->nextAttemptAt($attempts) : null;
            $finalStatus = $this->repository->markFailedOrRetry(
                $row['id'],
                $row['lease_id'],
                $attempts,
                $maxAttempts,
                $exception->getMessage(),
                $nextAttemptAt,
                $exception::class
            );

            if ($finalStatus === null) {
                $this->logger->warning('Email send failed after its processing lease was lost', [
                    'id' => $row['id'],
                    'sourceApp' => $row['source_app'],
                    'error' => $exception->getMessage(),
                ]);

                return;
            }
            if ($finalStatus === 'failed') {
                $this->cleanupAttachments((string) $row['id']);
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

    private function shouldFallbackTechnicalToStandard(int $attempts, int $maxAttempts): bool
    {
        return $this->queue === 'technical'
            && $this->env->bool('TECHNICAL_EMAIL_FALLBACK_TO_STANDARD', true)
            && $attempts >= $maxAttempts;
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
            $this->queue === 'technical'
                ? $this->env->int('GMAIL_SMTP_TIMEOUT_SECONDS', 30) + 30
                : $this->env->int('SMTP_TIMEOUT_SECONDS', 30) + 30
        );
    }

    private function cleanupTerminalAttachments(): void
    {
        foreach ($this->repository->findTerminalAttachmentEmailIds() as $emailId) {
            $this->cleanupAttachments($emailId);
        }
    }

    private function cleanupAttachments(string $emailId): void
    {
        try {
            $this->attachmentStorage->delete($emailId);
            $this->repository->markAttachmentsDeleted($emailId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to clean up email attachments', [
                'id' => $emailId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
