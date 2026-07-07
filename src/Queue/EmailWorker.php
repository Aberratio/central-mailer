<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Config\Env;
use CentralMailer\Email\EmailAttachment;
use CentralMailer\Email\EmailBranding;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\EmailProviderInterface;
use CentralMailer\Email\PermanentSendException;
use CentralMailer\Email\SendException;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Suppression\UnsubscribeToken;
use CentralMailer\Support\AlertNotifier;
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
        ?EmailBranding $branding = null,
        private readonly ?WorkerHeartbeatRepository $heartbeatRepository = null,
        private readonly ?string $workerId = null,
        private readonly ?\Closure $shouldStop = null,
        private readonly ?AlertNotifier $alertNotifier = null,
        private readonly ?SuppressionRepository $suppressions = null
    ) {
        if (!in_array($this->queue, ['standard', 'technical'], true)) {
            throw new \InvalidArgumentException('Queue must be standard or technical');
        }

        $this->branding = $branding ?? new EmailBranding();
    }

    public function runOnce(): int
    {
        $this->heartbeat(0);
        try {
            $processed = $this->runOnceInternal();
            $this->heartbeat($processed);

            return $processed;
        } catch (\Throwable $exception) {
            $this->heartbeat(0, $exception->getMessage());
            throw $exception;
        }
    }

    private function runOnceInternal(): int
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
            if ($i > 0 && $this->stopRequested()) {
                $this->logger->info('Worker stop requested, ending technical run early', ['processed' => $processed]);

                return $processed;
            }

            $emails = $this->repository->claimBatch(1, $leaseSeconds, $priorityAgingSeconds, $this->queue);
            if ($emails === []) {
                return $processed;
            }

            $decision = $this->rateLimiter->acquire(
                (string) $emails[0]['source_app'],
                $emails[0]['client_rate_limit_count'] === null ? null : (int) $emails[0]['client_rate_limit_count'],
                $emails[0]['client_rate_limit_window_minutes'] === null ? null : (int) $emails[0]['client_rate_limit_window_minutes']
            );
            // Gmail app-password SMTP has a hard daily cap (~500 consumer / 2000 Workspace);
            // exceeding it locks the account, so model it as a provider-scoped limit.
            $gmailLimit = $this->env->int('GMAIL_RATE_LIMIT_COUNT', 0);
            if ($decision->allowed && $gmailLimit > 0) {
                $decision = $this->rateLimiter->acquireProvider(
                    'gmail',
                    $gmailLimit,
                    $this->env->int('GMAIL_RATE_LIMIT_WINDOW_MINUTES', 1440)
                );
            }
            if (!$decision->allowed) {
                $this->repository->releaseClaim(
                    (string) $emails[0]['id'],
                    (string) $emails[0]['lease_id'],
                    (string) $emails[0]['_previous_status'],
                    $decision->retryAfter,
                    $decision->reason,
                    ((int) $emails[0]['attempts']) + 1
                );
                $this->logger->info('Email rate limit reached', [
                    'reason' => $decision->reason,
                    'retryAfter' => $decision->retryAfter,
                    'limit' => $decision->limit,
                    'used' => $decision->used,
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
            if ($index > 0 && $this->stopRequested()) {
                $this->releaseRemainingClaims($emails, $index);
                $this->logger->info('Worker stop requested, releasing unsent batch claims', [
                    'processed' => $processed,
                    'releasedClaims' => count($emails) - $index,
                ]);

                return $processed;
            }

            $decision = $this->rateLimiter->acquire(
                (string) $email['source_app'],
                $email['client_rate_limit_count'] === null ? null : (int) $email['client_rate_limit_count'],
                $email['client_rate_limit_window_minutes'] === null ? null : (int) $email['client_rate_limit_window_minutes']
            );
            if (!$decision->allowed) {
                if ($decision->reason === 'client') {
                    $this->repository->releaseClaim(
                        (string) $email['id'],
                        (string) $email['lease_id'],
                        (string) $email['_previous_status'],
                        $decision->retryAfter,
                        $decision->reason,
                        ((int) $email['attempts']) + 1
                    );
                    $this->logger->info('Email client rate limit reached', [
                        'sourceApp' => $email['source_app'],
                        'retryAfter' => $decision->retryAfter,
                        'limit' => $decision->limit,
                        'used' => $decision->used,
                    ]);

                    continue;
                }

                $this->releaseRemainingClaims($emails, $index, $decision);
                $this->logger->info('Email rate limit reached', [
                    'reason' => $decision->reason,
                    'retryAfter' => $decision->retryAfter,
                    'limit' => $decision->limit,
                    'used' => $decision->used,
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
    private function releaseRemainingClaims(array $emails, int $startIndex, ?RateLimitDecision $decision = null): void
    {
        for ($i = $startIndex, $count = count($emails); $i < $count; $i++) {
            $this->repository->releaseClaim(
                (string) $emails[$i]['id'],
                (string) $emails[$i]['lease_id'],
                (string) $emails[$i]['_previous_status'],
                $decision?->retryAfter,
                $decision?->reason,
                ((int) $emails[$i]['attempts']) + 1
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

        // Enforcement of record: the suppression list may have grown between enqueue and send.
        if ($this->suppressions !== null && $this->suppressions->isSuppressed(
            (string) $row['recipient_email'],
            (string) $row['source_app'],
            (string) ($row['category'] ?? 'transactional')
        )) {
            $this->repository->markFailedOrRetry(
                (string) $row['id'],
                (string) $row['lease_id'],
                ((int) $row['attempts']) + 1,
                (int) $row['max_attempts'],
                'Recipient address is suppressed',
                null,
                'suppressed',
                true
            );
            $this->cleanupAttachments((string) $row['id']);
            $this->logger->info('Email skipped, recipient is suppressed', [
                'id' => $row['id'],
                'sourceApp' => $row['source_app'],
                'recipient' => $row['recipient_email'],
            ]);

            return;
        }

        try {
            $attempt = ((int) $row['attempts']) + 1;
            $this->repository->recordAttemptStarted(
                (string) $row['id'],
                (string) $row['lease_id'],
                $attempt,
                $this->queue,
                $this->workerId(),
                (string) $row['lease_expires_at']
            );
            $attachments = array_map(
                fn (array $attachment): EmailAttachment => new EmailAttachment(
                    $this->attachmentStorage->absolutePath((string) $attachment['storage_path']),
                    (string) $attachment['filename'],
                    (string) $attachment['content_type'],
                    $attachment['content_id'] === null ? null : (string) $attachment['content_id'],
                    $attachment['content_id'] !== null
                ),
                $this->repository->findAttachments((string) $row['id'])
            );
            $message = new EmailMessage(
                $row['id'],
                $row['recipient_email'],
                $row['resolved_subject'],
                $row['resolved_html_body'],
                $row['resolved_text_body'],
                $attachments,
                $this->unsubscribeHeaders($row)
            );
            $result = $this->provider->send($this->branding->apply($message));

            $markResult = $this->repository->markSentAfterProviderAccepted(
                (string) $row['id'],
                (string) $row['lease_id'],
                $result->providerMessageId,
                $attempt,
                $this->workerId()
            );
            if ($markResult === 'lost') {
                $this->logger->critical('Email was accepted by provider after its processing lease was lost', [
                    'id' => $row['id'],
                    'sourceApp' => $row['source_app'],
                    'providerMessageId' => $result->providerMessageId,
                ]);
                $this->alert('lease_lost_provider_accepted', 'Email was accepted by provider after its processing lease was lost', $row, [
                    'providerMessageId' => $result->providerMessageId,
                ]);

                return;
            }
            $this->cleanupAttachments((string) $row['id']);

            $this->logger->info($markResult === 'reconciled' ? 'Email sent after lease reconciliation' : 'Email sent', [
                'id' => $row['id'],
                'sourceApp' => $row['source_app'],
                'providerMessageId' => $result->providerMessageId,
                'finalStatus' => 'sent',
            ]);
        } catch (\Throwable $exception) {
            $attempts = ((int) $row['attempts']) + 1;
            $maxAttempts = (int) $row['max_attempts'];
            $permanent = $exception instanceof PermanentSendException;
            $errorCode = $exception instanceof SendException ? $exception->errorCode() : $exception::class;
            // A permanent rejection (5xx) will repeat on any relay - retrying or falling
            // back to the standard queue only burns attempts and sender reputation.
            if (!$permanent && $this->shouldFallbackTechnicalToStandard($attempts, $maxAttempts)) {
                $fallback = $this->repository->fallbackTechnicalToStandard(
                    $row['id'],
                    $row['lease_id'],
                    $attempts,
                    $exception->getMessage(),
                    $errorCode
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

            if ($permanent
                && $exception instanceof PermanentSendException
                && $exception->recipientPermanent
                && $this->suppressions !== null
            ) {
                // A dead mailbox is dead for every client - suppress globally.
                $this->suppressions->add(
                    (string) $row['recipient_email'],
                    'bounce',
                    'all',
                    '',
                    (string) $row['id'],
                    $exception->getMessage()
                );
            }

            $nextAttemptAt = !$permanent && $attempts < $maxAttempts ? $this->nextAttemptAt($attempts) : null;
            $finalStatus = $this->repository->markFailedOrRetry(
                $row['id'],
                $row['lease_id'],
                $attempts,
                $maxAttempts,
                $exception->getMessage(),
                $nextAttemptAt,
                $errorCode,
                $permanent
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
                $this->alert('email_failed', 'Email permanently failed after exhausting attempts', $row, [
                    'attempts' => $attempts,
                    'error' => $exception->getMessage(),
                ]);
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

    private function stopRequested(): bool
    {
        return $this->shouldStop !== null && ($this->shouldStop)();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context
     */
    private function alert(string $type, string $message, array $row, array $context = []): void
    {
        // Alert emails failing must not trigger further alert emails.
        if ($this->alertNotifier === null || (string) $row['source_app'] === AlertNotifier::SOURCE_APP) {
            return;
        }

        $this->alertNotifier->notify($type, $message, [
            'id' => (string) $row['id'],
            'sourceApp' => (string) $row['source_app'],
            'queue' => $this->queue,
            ...$context,
        ]);
    }

    private function shouldFallbackTechnicalToStandard(int $attempts, int $maxAttempts): bool
    {
        return $this->queue === 'technical'
            && $this->env->bool('TECHNICAL_EMAIL_FALLBACK_TO_STANDARD', true)
            && $attempts >= $maxAttempts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function unsubscribeHeaders(array $row): array
    {
        if (($row['category'] ?? 'transactional') !== 'marketing') {
            return [];
        }

        $secret = $this->env->nullableString('UNSUBSCRIBE_SECRET');
        $baseUrl = $this->env->nullableString('PUBLIC_BASE_URL') ?? $this->env->nullableString('APP_URL');
        if ($secret === null || $baseUrl === null) {
            $this->logger->warning('Marketing email sent without List-Unsubscribe headers, configure UNSUBSCRIBE_SECRET and APP_URL', [
                'id' => $row['id'],
            ]);

            return [];
        }

        $token = (new UnsubscribeToken($secret, $this->env->nullableString('UNSUBSCRIBE_SECRET_PREVIOUS')))
            ->generate((string) $row['recipient_email'], (string) $row['source_app']);
        $url = rtrim($baseUrl, '/') . '/unsubscribe?token=' . urlencode($token);
        $targets = [];
        $mailto = $this->env->nullableString('UNSUBSCRIBE_MAILTO');
        if ($mailto !== null) {
            $targets[] = sprintf('<mailto:%s?subject=unsubscribe>', $mailto);
        }
        $targets[] = sprintf('<%s>', $url);

        return [
            'List-Unsubscribe' => implode(', ', $targets),
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    private function nextAttemptAt(int $attempts): string
    {
        // Jitter prevents emails failed in the same tick from retrying in one synchronized wave.
        $delaySeconds = min(3600, 60 * (2 ** max(0, $attempts - 1))) + random_int(0, 30);

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
        // PHPMailer's Timeout bounds a single socket read, not the whole send. One send
        // issues ~10+ SMTP commands, so the lease floor must cover the per-command worst
        // case or a slow-but-live send would lose its lease and risk a duplicate.
        $smtpTimeout = $this->queue === 'technical'
            ? $this->env->int('GMAIL_SMTP_TIMEOUT_SECONDS', 30)
            : $this->env->int('SMTP_TIMEOUT_SECONDS', 30);

        return max(
            60,
            $this->env->int('EMAIL_PROCESSING_TIMEOUT_SECONDS', 300),
            $smtpTimeout * 12 + 60,
            $this->env->int('EMAIL_WORKER_LEASE_MIN_SECONDS', 0)
        );
    }

    private function heartbeat(int $processedDelta, ?string $lastError = null): void
    {
        if ($this->heartbeatRepository === null) {
            return;
        }

        $this->heartbeatRepository->beat(
            $this->workerId(),
            $this->queue,
            $processedDelta,
            $processedDelta > 0 ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            $lastError
        );
    }

    private function workerId(): string
    {
        return $this->workerId ?? sprintf(
            '%s:%s:%s',
            $this->queue,
            gethostname() ?: 'unknown',
            getmypid() ?: 'pid'
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
