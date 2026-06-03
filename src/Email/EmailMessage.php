<?php

declare(strict_types=1);

namespace CentralMailer\Email;

final class EmailMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text
    ) {
    }
}
