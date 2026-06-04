<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

final class EnqueueResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly bool $created
    ) {
    }
}
