<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Config\Env;
use CentralMailer\Email\EmailAttachment;
use CentralMailer\Email\EmailBrandConfig;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\SmtpEmailProvider;
use PHPMailer\PHPMailer\PHPMailer;
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

    public function testRejectsUnencryptedSmtpConfiguration(): void
    {
        $provider = new SmtpEmailProvider(new Env([
            'SMTP_HOST' => 'smtp.example.test',
            'SMTP_USER' => 'user',
            'SMTP_PASSWORD' => 'password',
            'SMTP_FROM_EMAIL' => 'sender@example.test',
            'SMTP_SECURE' => 'none',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP_SECURE must be tls or ssl');

        (new ReflectionMethod($provider, 'mailer'))->invoke($provider);
    }

    public function testInlineAttachmentIsEmbeddedWithoutFilenameHeader(): void
    {
        $provider = new SmtpEmailProvider(new Env([
            'SMTP_MESSAGE_ID_DOMAIN' => 'mailer.example.test',
            'SMTP_FROM_EMAIL' => 'sender@example.test',
        ]));
        $mailer = new class(true) extends PHPMailer {
            public string $sentMime = '';

            public function send()
            {
                $this->preSend();
                $this->sentMime = $this->getSentMIMEMessage();

                return true;
            }
        };
        $mailer->isHTML(true);
        $mailer->setFrom('sender@example.test');
        $this->injectMailer($provider, $mailer);

        $path = $this->writeTinyPng();
        try {
            $provider->send(new EmailMessage(
                '71d9e180-b457-4fc8-b5bb-fc35ba5bc481',
                'recipient@test.local',
                'QR',
                '<img src="cid:qr-inline">',
                null,
                [
                    new EmailAttachment($path, 'kod-qr-inline.png', 'image/png', 'qr-inline', true),
                    new EmailAttachment($path, 'kod-qr.png', 'image/png'),
                ]
            ));
        } finally {
            @unlink($path);
        }

        self::assertStringContainsString('Content-ID: <qr-inline>', $mailer->sentMime);
        self::assertStringContainsString('Content-Disposition: inline', $mailer->sentMime);
        self::assertStringNotContainsString('kod-qr-inline.png', $mailer->sentMime);
        self::assertStringContainsString('kod-qr.png', $mailer->sentMime);
        self::assertStringContainsString('Content-Disposition: attachment;', $mailer->sentMime);
    }

    private function messageIdDomain(SmtpEmailProvider $provider): string
    {
        return (new ReflectionMethod($provider, 'messageIdDomain'))->invoke($provider);
    }

    private function injectMailer(SmtpEmailProvider $provider, PHPMailer $mailer): void
    {
        $property = new \ReflectionProperty($provider, 'mailer');
        $property->setValue($provider, $mailer);
    }

    private function writeTinyPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'central-mailer-qr-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary PNG');
        }

        file_put_contents(
            $path,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=', true)
        );

        return $path;
    }
}
