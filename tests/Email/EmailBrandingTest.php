<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Email\EmailBrandConfig;
use CentralMailer\Email\EmailBranding;
use CentralMailer\Email\EmailMessage;
use PHPUnit\Framework\TestCase;

final class EmailBrandingTest extends TestCase
{
    public function testAppliesDefaultBranding(): void
    {
        $message = $this->message('<p>Body</p>', 'Body');

        $decorated = (new EmailBranding())->apply($message);

        self::assertStringContainsString('Zmierzymy Czas', $decorated->html);
        self::assertStringContainsString('Wiadomosc zostala wyslana automatycznie.', $decorated->html);
        self::assertSame("Body\n\n--\nWiadomosc zostala wyslana automatycznie.\nZmierzymy Czas", $decorated->text);
    }

    public function testAddsHeaderFooterAndPlainTextFooter(): void
    {
        $branding = new EmailBranding(new EmailBrandConfig(
            brandName: 'Example & Co.',
            logoUrl: 'https://example.test/logo.png?size=large&format=png',
            footerHtml: '<a href="https://example.test">Contact</a>',
            footerText: 'Contact: example.test'
        ));

        $decorated = $branding->apply($this->message('<p>Body</p>', 'Body'));

        self::assertStringContainsString('Example &amp; Co.', $decorated->html);
        self::assertStringContainsString('https://example.test/logo.png?size=large&amp;format=png', $decorated->html);
        self::assertStringContainsString('<p>Body</p>', $decorated->html);
        self::assertStringContainsString('<a href="https://example.test">Contact</a>', $decorated->html);
        self::assertSame("Body\n\n--\nContact: example.test\nExample & Co.", $decorated->text);
    }

    public function testInsertsBrandingInsideFullHtmlDocumentBody(): void
    {
        $branding = new EmailBranding(new EmailBrandConfig(
            brandName: 'Example',
            footerHtml: '<div>Footer $1</div>'
        ));

        $decorated = $branding->apply($this->message('<html><body class="mail"><p>Body</p></body></html>', null));

        self::assertMatchesRegularExpression('/<body class="mail">.*Example.*<p>Body<\/p>.*Footer \$1.*<\/body>/s', $decorated->html);
        self::assertNull($decorated->text);
    }

    private function message(string $html, ?string $text): EmailMessage
    {
        return new EmailMessage('message-id', 'recipient@example.test', 'Subject', $html, $text);
    }
}
