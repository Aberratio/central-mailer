<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Config\Env;
use CentralMailer\Email\SmtpEmailProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SmtpEmailProviderTest extends TestCase
{
    public function testUsesConfiguredMessageIdDomain(): void
    {
        $provider = new SmtpEmailProvider(new Env([
            'SMTP_MESSAGE_ID_DOMAIN' => 'mailer.example.test',
            'SMTP_FROM_EMAIL' => 'sender@fallback.test',
        ]));

        self::assertSame('mailer.example.test', $this->messageIdDomain($provider));
    }

    public function testFallsBackToFromEmailDomain(): void
    {
        $provider = new SmtpEmailProvider(new Env(['SMTP_FROM_EMAIL' => 'sender@fallback.test']));

        self::assertSame('fallback.test', $this->messageIdDomain($provider));
    }

    public function testFallsBackToLocalhostForFromAddressWithoutDomain(): void
    {
        $provider = new SmtpEmailProvider(new Env(['SMTP_FROM_EMAIL' => 'sender']));

        self::assertSame('localhost.localdomain', $this->messageIdDomain($provider));
    }

    private function messageIdDomain(SmtpEmailProvider $provider): string
    {
        return (new ReflectionMethod($provider, 'messageIdDomain'))->invoke($provider);
    }
}
