<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;

final class RateLimitRepositoryTest extends DatabaseTestCase
{
    public function testDoesNotReserveMoreThanConfiguredLimit(): void
    {
        $repository = new RateLimitRepository($this->pdo);
        $since = (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');

        self::assertTrue($repository->tryReserve('app-a', 2, $since, 15, null, $since, 15, $since)->allowed);
        self::assertTrue($repository->tryReserve('app-a', 2, $since, 15, null, $since, 15, $since)->allowed);
        $denied = $repository->tryReserve('app-a', 2, $since, 15, null, $since, 15, $since);
        self::assertFalse($denied->allowed);
        self::assertSame('global', $denied->reason);
        self::assertNotNull($denied->retryAfter);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM email_rate_limit_reservations')->fetchColumn());
    }

    public function testProviderScopeIsLimitedIndependentlyFromGlobalWindow(): void
    {
        $repository = new RateLimitRepository($this->pdo);
        $since = (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');

        self::assertTrue($repository->tryReserveScope('provider:gmail', 2, $since, 1440)->allowed);
        self::assertTrue($repository->tryReserveScope('provider:gmail', 2, $since, 1440)->allowed);
        $denied = $repository->tryReserveScope('provider:gmail', 2, $since, 1440);
        self::assertFalse($denied->allowed);
        self::assertSame('provider', $denied->reason);
        self::assertNotNull($denied->retryAfter);

        // Provider reservations must not consume the global window.
        $global = $repository->tryReserve('app-a', 2, $since, 15, null, $since, 15, $since);
        self::assertTrue($global->allowed);
        self::assertSame(1, $global->used);
    }

    public function testEnforcesPerClientLimitWithoutBlockingAnotherClient(): void
    {
        $repository = new RateLimitRepository($this->pdo);
        $since = (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');

        self::assertTrue($repository->tryReserve('app-a', 10, $since, 15, 1, $since, 15, $since)->allowed);
        $denied = $repository->tryReserve('app-a', 10, $since, 15, 1, $since, 15, $since);
        self::assertFalse($denied->allowed);
        self::assertSame('client', $denied->reason);
        self::assertNotNull($denied->retryAfter);
        self::assertTrue($repository->tryReserve('app-b', 10, $since, 15, 1, $since, 15, $since)->allowed);
    }
}
