<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Config\Env;
use CentralMailer\Email\EmailBrandConfig;
use CentralMailer\Email\EmailMessage;
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

    public function testBuildsStableMessageIdFromQueueId(): void
    {
        $provider = new SmtpEmailProvider(new Env([
            'SMTP_MESSAGE_ID_DOMAIN' => 'mailer.example.test',
            'SMTP_FROM_EMAIL' => 'sender@fallback.test',
        ]));
        $message = new EmailMessage('71d9e180-b457-4fc8-b5bb-fc35ba5bc481', 'recipient@test.local', 'Subject', '<p>Body</p>', null);

        $method = new ReflectionMethod($provider, 'messageId');

        self::assertSame(
            '<71d9e180-b457-4fc8-b5bb-fc35ba5bc481@mailer.example.test>',
            $method->invoke($provider, $message)
        );
    }

    public function testUsesSenderNameFromBrandConfig(): void
    {
        $provider = new SmtpEmailProvider(new Env([]), new EmailBrandConfig(senderName: 'Global sender'));

        $property = new \ReflectionProperty($provider, 'brandConfig');

        self::assertSame('Global sender', $property->getValue($provider)->senderName);
    }

    private function messageIdDomain(SmtpEmailProvider $provider): string
    {
        return (new ReflectionMethod($provider, 'messageIdDomain'))->invoke($provider);
    }
}
