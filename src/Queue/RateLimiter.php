<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Config\Env;

final class RateLimiter
{
    public function __construct(private readonly EmailQueueRepository $repository, private readonly Env $env)
    {
    }

    public function remaining(): int
    {
        $limit = $this->env->int('EMAIL_RATE_LIMIT_COUNT', 100);
        $windowMinutes = $this->env->int('EMAIL_RATE_LIMIT_WINDOW_MINUTES', 15);
        $since = (new \DateTimeImmutable(sprintf('-%d minutes', $windowMinutes)))->format('Y-m-d H:i:s');

        return max(0, $limit - $this->repository->countSentSince($since));
    }
}
