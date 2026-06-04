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

        self::assertTrue($repository->tryReserve('app-a', 2, $since, null, $since, $since));
        self::assertTrue($repository->tryReserve('app-a', 2, $since, null, $since, $since));
        self::assertFalse($repository->tryReserve('app-a', 2, $since, null, $since, $since));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM email_rate_limit_reservations')->fetchColumn());
    }

    public function testEnforcesPerClientLimitWithoutBlockingAnotherClient(): void
    {
        $repository = new RateLimitRepository($this->pdo);
        $since = (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');

        self::assertTrue($repository->tryReserve('app-a', 10, $since, 1, $since, $since));
        self::assertFalse($repository->tryReserve('app-a', 10, $since, 1, $since, $since));
        self::assertTrue($repository->tryReserve('app-b', 10, $since, 1, $since, $since));
    }
}
