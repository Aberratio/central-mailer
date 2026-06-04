<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Config\Env;

final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repository, private readonly Env $env)
    {
    }

    public function acquire(string $sourceApp, ?int $clientLimit, ?int $clientWindowMinutes): bool
    {
        $limit = $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100);
        $windowMinutes = $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15);
        $since = (new \DateTimeImmutable(sprintf('-%d minutes', $windowMinutes)))->format('Y-m-d H:i:s');
        $clientSince = (new \DateTimeImmutable(sprintf('-%d minutes', $clientWindowMinutes ?? $windowMinutes)))->format('Y-m-d H:i:s');
        $cleanupSince = (new \DateTimeImmutable(sprintf(
            '-%d minutes',
            $this->env->int('EMAIL_RATE_LIMIT_RESERVATION_RETENTION_MINUTES', 10080)
        )))->format('Y-m-d H:i:s');

        return $this->repository->tryReserve($sourceApp, $limit, $since, $clientLimit, $clientSince, $cleanupSince);
    }
}
