<?php

declare(strict_types=1);

namespace CentralMailer\Email;

interface EmailProviderInterface
{
    public function send(EmailMessage $message): EmailSendResult;
}
