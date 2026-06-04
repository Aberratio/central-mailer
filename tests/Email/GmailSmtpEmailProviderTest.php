<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Config\Env;
use CentralMailer\Email\GmailSmtpEmailProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class GmailSmtpEmailProviderTest extends TestCase
{
    public function testUsesConfiguredMessageIdDomain(): void
    {
        $provider = new GmailSmtpEmailProvider(new Env([
            'GMAIL_MESSAGE_ID_DOMAIN' => 'mailer.example.test',
            'GMAIL_FROM_EMAIL' => 'developer@gmail.com',
        ]));

        self::assertSame('mailer.example.test', $this->messageIdDomain($provider));
    }

    public function testFallsBackToFromEmailDomain(): void
    {
        $provider = new GmailSmtpEmailProvider(new Env(['GMAIL_FROM_EMAIL' => 'developer@gmail.com']));

        self::assertSame('gmail.com', $this->messageIdDomain($provider));
    }

    private function messageIdDomain(GmailSmtpEmailProvider $provider): string
    {
        return (new ReflectionMethod($provider, 'messageIdDomain'))->invoke($provider);
    }
}
