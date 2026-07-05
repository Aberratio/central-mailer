<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

final class RateLimitDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $retryAfter = null,
        public readonly ?int $used = null,
        public readonly ?int $limit = null
    ) {
    }

    public static function allowed(int $used, int $limit): self
    {
        return new self(true, null, null, $used, $limit);
    }

    public static function denied(string $reason, string $retryAfter, int $used, int $limit): self
    {
        return new self(false, $reason, $retryAfter, $used, $limit);
    }
}
