<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Config\Env;
use CentralMailer\Email\EmailAttachment;
use CentralMailer\Email\EmailBrandConfig;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\EmailProviderInterface;
use CentralMailer\Email\GmailSmtpEmailProvider;
use CentralMailer\Email\LogEmailProvider;
use CentralMailer\Email\MailTransport;
use CentralMailer\Email\SmtpEmailProvider;
use PHPUnit\Framework\TestCase;

final class LogEmailProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cm-mail-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testWritesCompleteMessageToEmlFileAndReportsSuccess(): void
    {
        $inline = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($inline, 'PNGDATA');
        $plain = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($plain, 'hello');

        $provider = new LogEmailProvider(
            $this->dir,
            'noreply@example.test',
            new EmailBrandConfig(senderName: 'Sender', replyToEmail: 'reply@example.test', replyToName: 'Reply Team')
        );
        $result = $provider->send(new EmailMessage(
            'msg-1',
            'rcpt@example.com',
            'Temat wiadomości',
            '<p>Treść <img src="cid:logo"></p>',
            'Treść',
            [
                new EmailAttachment($plain, 'doc.txt', 'text/plain'),
                new EmailAttachment($inline, 'logo.png', 'image/png', 'logo'),
            ],
            ['X-Custom' => 'yes']
        ));

        unlink($inline);
        unlink($plain);

        self::assertNotNull($result->providerMessageId);
        $files = glob($this->dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('msg-1', basename($files[0]));
        $eml = (string) file_get_contents($files[0]);
        self::assertStringContainsString('rcpt@example.com', $eml);
        self::assertMatchesRegularExpression('/^Reply-To: .*reply@example\.test/mi', $eml);
        self::assertMatchesRegularExpression('/^Subject: /mi', $eml);
        self::assertStringContainsString('X-Custom: yes', $eml);
        self::assertStringContainsString('filename=doc.txt', str_replace('"', '', $eml));
        self::assertStringContainsString('Content-ID: <logo>', $eml);
        self::assertStringContainsString('<p>Tre', $eml);
    }

    public function testSecondSendDoesNotLeakRecipientsOrHeaders(): void
    {
        $provider = new LogEmailProvider($this->dir, 'noreply@example.test');
        $provider->send(new EmailMessage('a', 'a@example.com', 'A', '<p>a</p>', null, [], ['X-First' => '1']));
        $provider->send(new EmailMessage('b', 'b@example.com', 'B', '<p>b</p>', null));

        $second = (string) file_get_contents($this->dir . '/' . basename(glob($this->dir . '/*-b.eml')[0]));
        self::assertStringContainsString('b@example.com', $second);
        self::assertStringNotContainsString('a@example.com', $second);
        self::assertStringNotContainsString('X-First', $second);
    }

    public function testFactoryDefaultsToSmtpAndSelectsLogWhenConfigured(): void
    {
        $root = sys_get_temp_dir();
        self::assertInstanceOf(SmtpEmailProvider::class, MailTransport::createProvider(new Env([]), 'standard', $root));
        self::assertInstanceOf(GmailSmtpEmailProvider::class, MailTransport::createProvider(new Env([]), 'technical', $root));

        $env = new Env(['APP_ENV' => 'local', 'MAIL_TRANSPORT' => 'log', 'MAIL_LOG_DIR' => $this->dir]);
        foreach (['standard', 'technical'] as $queue) {
            $provider = MailTransport::createProvider($env, $queue, $root);
            self::assertInstanceOf(LogEmailProvider::class, $provider);
            self::assertInstanceOf(EmailProviderInterface::class, $provider);
        }
    }

    public function testFactoryRefusesLogTransportOutsideLocalAndTesting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MAIL_TRANSPORT=log');

        MailTransport::createProvider(new Env(['APP_ENV' => 'production', 'MAIL_TRANSPORT' => 'log']), 'standard', sys_get_temp_dir());
    }

    public function testDefaultDirectoryIsStorageMailUnderRoot(): void
    {
        $root = $this->dir;
        mkdir($root);
        $provider = MailTransport::createProvider(new Env(['APP_ENV' => 'testing', 'MAIL_TRANSPORT' => 'log']), 'standard', $root);
        $provider->send(new EmailMessage('x', 'x@example.com', 'X', '<p>x</p>', null));

        self::assertCount(1, glob($root . '/storage/mail/*.eml') ?: []);
        foreach (glob($root . '/storage/mail/*') as $f) {
            unlink($f);
        }
        rmdir($root . '/storage/mail');
        rmdir($root . '/storage');
    }
}
