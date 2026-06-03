<?php

declare(strict_types=1);

namespace CentralMailer\Email;

final class EmailSendResult
{
    public function __construct(public readonly ?string $providerMessageId = null)
    {
    }
}
