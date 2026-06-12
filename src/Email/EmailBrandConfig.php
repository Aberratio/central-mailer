<?php

declare(strict_types=1);

namespace CentralMailer\Email;

final class EmailBrandConfig
{
    public function __construct(
        public readonly string $senderName = 'Zmierzymy Czas',
        public readonly string $brandName = 'zmierzymyczas.pl',
        public readonly ?string $logoUrl = null,
        public readonly ?string $replyToEmail = null,
        public readonly ?string $replyToName = null,
        public readonly string $footerHtml = '<div>Wiadomosc zostala wyslana automatycznie.</div>',
        public readonly string $footerText = 'Wiadomosc zostala wyslana automatycznie.'
    ) {
    }
}
