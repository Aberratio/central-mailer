<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Email\EmailBrandConfig;
use CentralMailer\Email\EmailMessage;
use CentralMailer\Email\LogEmailProvider;
use CentralMailer\Email\ReplyTo;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ReplyToTest extends TestCase
{
    public function testMessageReplyToReplacesGlobalBrandReplyTo(): void
    {
        $mail = new PHPMailer(true);
        $brand = new EmailBrandConfig(replyToEmail: 'global@brand.test', replyToName: 'Global');

        ReplyTo::apply($mail, $this->message('organizer@club.test', 'Klub'), $brand);

        self::assertSame(['organizer@club.test' => ['organizer@club.test', 'Klub']], $mail->getReplyToAddresses());
    }

    public function testFallsBackToGlobalBrandReplyToAndClearsPreviousMessageValue(): void
    {
        $mail = new PHPMailer(true);
        $brand = new EmailBrandConfig(replyToEmail: 'global@brand.test', senderName: 'Sender');

        // The mailer instance is reused across sends: a previous per-message reply-to must not leak.
        ReplyTo::apply($mail, $this->message('organizer@club.test', null), $brand);
        ReplyTo::apply($mail, $this->message(null, null), $brand);

        self::assertSame(['global@brand.test' => ['global@brand.test', 'Sender']], $mail->getReplyToAddresses());
    }

    public function testNoReplyToAtAll(): void
    {
        $mail = new PHPMailer(true);

        ReplyTo::apply($mail, $this->message(null, null), new EmailBrandConfig());

        self::assertSame([], $mail->getReplyToAddresses());
    }

    public function testLogProviderWritesMessageReplyToInsteadOfGlobal(): void
    {
        $dir = sys_get_temp_dir() . '/cm-replyto-' . bin2hex(random_bytes(4));
        $provider = new LogEmailProvider($dir, 'noreply@example.test', new EmailBrandConfig(replyToEmail: 'global@brand.test'));

        $provider->send($this->message('organizer@club.test', 'Klub Biegowy'));

        $files = glob($dir . '/*.eml') ?: [];
        $eml = (string) file_get_contents($files[0]);
        unlink($files[0]);
        rmdir($dir);
        self::assertMatchesRegularExpression('/^Reply-To: .*organizer@club\.test/mi', $eml);
        self::assertStringNotContainsString('global@brand.test', $eml);
    }

    private function message(?string $replyToEmail, ?string $replyToName): EmailMessage
    {
        return new EmailMessage('m1', 'rcpt@deliverable.test', 'S', '<p>x</p>', null, [], [], $replyToEmail, $replyToName);
    }
}
