<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

final class BatchEnqueueResult
{
    /** @param list<array{id: string, status: string}> $emails */
    public function __construct(
        public readonly string $id,
        public readonly array $emails,
        public readonly bool $created
    ) {
    }
}
