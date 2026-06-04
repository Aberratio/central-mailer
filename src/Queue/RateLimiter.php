<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Config\Env;

final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repository, private readonly Env $env)
    {
    }

    public function acquire(): bool
    {
        $limit = $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100);
        $windowMinutes = $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15);
        $since = (new \DateTimeImmutable(sprintf('-%d minutes', $windowMinutes)))->format('Y-m-d H:i:s');

        return $this->repository->tryReserve($limit, $since);
    }
}
