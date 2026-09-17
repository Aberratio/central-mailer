<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Config;

use CentralMailer\Config\Env;
use CentralMailer\Config\LimitsConfig;
use PHPUnit\Framework\TestCase;

final class LimitsConfigTest extends TestCase
{
    public function testUsesDefaultsWhenEnvIsEmpty(): void
    {
        $limits = LimitsConfig::fromEnv(new Env([]));

        self::assertSame(100, $limits->globalCount);
        self::assertSame(15, $limits->globalWindowMinutes);
        self::assertFalse($limits->gmailEnabled());
        self::assertSame(1440, $limits->gmailWindowMinutes);
        self::assertSame(60, $limits->intakeCount);
        self::assertSame(1, $limits->intakeWindowMinutes);
        self::assertSame(20, $limits->workerBatchSize);
        self::assertSame(60, $limits->workerCronIntervalSeconds);
        self::assertSame(10_000, $limits->maxQueuedPerClient);
        self::assertSame(90, $limits->dataRetentionDays);
    }

    public function testReadsOverridesAndClampsLikeTheEnforcingCode(): void
    {
        $limits = LimitsConfig::fromEnv(new Env([
            'GMAIL_RATE_LIMIT_COUNT' => '450',
            'EMAIL_ENQUEUE_RATE_LIMIT_COUNT' => '0',
            'EMAIL_WORKER_CRON_INTERVAL_SECONDS' => '5',
            'EMAIL_DATA_RETENTION_DAYS' => '0',
        ]));

        self::assertTrue($limits->gmailEnabled());
        self::assertSame(450, $limits->gmailCount);
        self::assertSame(1, $limits->intakeCount);
        self::assertSame(10, $limits->workerCronIntervalSeconds);
        self::assertSame(1, $limits->dataRetentionDays);
    }
}
