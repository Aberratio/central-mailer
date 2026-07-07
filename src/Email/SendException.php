<?php

declare(strict_types=1);

namespace CentralMailer\Email;

abstract class SendException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $smtpCode = null,
        public readonly ?string $enhancedCode = null,
        public readonly bool $recipientPermanent = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        if ($this->smtpCode === null) {
            $previous = $this->getPrevious();

            return $previous !== null ? $previous::class : static::class;
        }

        return $this->enhancedCode !== null
            ? sprintf('smtp:%d:%s', $this->smtpCode, $this->enhancedCode)
            : sprintf('smtp:%d', $this->smtpCode);
    }
}
