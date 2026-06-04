<?php

declare(strict_types=1);

namespace CentralMailer\Client;

final class ClientPolicy
{
    public function __construct(
        public readonly string $sourceApp,
        public readonly int $queueWeight,
        public readonly ?int $rateLimitCount,
        public readonly ?int $rateLimitWindowMinutes
    ) {
    }
}
