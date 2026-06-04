<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Queue\EnqueueRateLimitRepository;
use CentralMailer\Tests\Support\DatabaseTestCase;

final class EnqueueRateLimitRepositoryTest extends DatabaseTestCase
{
    public function testEnforcesLimitPerClient(): void
    {
        $repository = new EnqueueRateLimitRepository($this->pdo);
        $since = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $cleanupSince = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        self::assertTrue($repository->tryReserve('app-a', 1, $since, $cleanupSince));
        self::assertFalse($repository->tryReserve('app-a', 1, $since, $cleanupSince));
        self::assertTrue($repository->tryReserve('app-b', 1, $since, $cleanupSince));
    }
}
