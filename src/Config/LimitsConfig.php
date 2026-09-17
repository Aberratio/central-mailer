<?php

declare(strict_types=1);

namespace CentralMailer\Config;

/**
 * Single source of the sending/intake limits and worker pacing read from env, so the values
 * enforced by the worker and middleware are exactly the ones the admin panel reports.
 */
final class LimitsConfig
{
    public function __construct(
        public readonly int $globalCount,
        public readonly int $globalWindowMinutes,
        public readonly int $gmailCount,
        public readonly int $gmailWindowMinutes,
        public readonly int $intakeCount,
        public readonly int $intakeWindowMinutes,
        public readonly int $standardWorkerSleepSeconds,
        public readonly int $technicalWorkerSleepSeconds,
        public readonly int $workerBatchSize,
        public readonly int $workerCronIntervalSeconds,
        public readonly int $maxQueuedPerClient,
        public readonly int $maxActiveAttachmentBytesPerClient,
        public readonly int $dataRetentionDays
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            globalCount: $env->int('EMAIL_RATE_LIMIT_COUNT', 100),
            globalWindowMinutes: $env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15),
            gmailCount: $env->int('GMAIL_RATE_LIMIT_COUNT', 0),
            gmailWindowMinutes: $env->int('GMAIL_RATE_LIMIT_WINDOW_MINUTES', 1440),
            intakeCount: max(1, $env->int('EMAIL_ENQUEUE_RATE_LIMIT_COUNT', 60)),
            intakeWindowMinutes: max(1, $env->int('EMAIL_ENQUEUE_RATE_LIMIT_WINDOW_MINUTES', 1)),
            standardWorkerSleepSeconds: $env->int('EMAIL_WORKER_SLEEP_SECONDS', 10),
            technicalWorkerSleepSeconds: $env->int('TECHNICAL_EMAIL_WORKER_SLEEP_SECONDS', 10),
            workerBatchSize: $env->int('EMAIL_WORKER_BATCH_SIZE', 20),
            workerCronIntervalSeconds: max(10, $env->int('EMAIL_WORKER_CRON_INTERVAL_SECONDS', 60)),
            maxQueuedPerClient: max(1, $env->int('EMAIL_MAX_QUEUED_PER_CLIENT', 10_000)),
            maxActiveAttachmentBytesPerClient: max(0, $env->int('EMAIL_MAX_ACTIVE_ATTACHMENT_BYTES_PER_CLIENT', 100_000_000)),
            dataRetentionDays: max(1, $env->int('EMAIL_DATA_RETENTION_DAYS', 90))
        );
    }

    public function gmailEnabled(): bool
    {
        return $this->gmailCount > 0;
    }
}
