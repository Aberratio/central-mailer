<?php

declare(strict_types=1);

namespace CentralMailer\Email;

final class EmailAttachment
{
    public readonly bool $inline;

    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly ?string $contentId = null,
        bool $inline = false
    ) {
        $this->inline = $inline || $contentId !== null;
    }
}
