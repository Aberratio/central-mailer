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

        self::assertStringNotContainsString('Zmierzymy Czas', $decorated->html);
        self::assertStringContainsString('<p>Body</p>', $decorated->html);
        self::assertStringContainsString('zmierzymyczas.pl', $decorated->html);
        self::assertStringContainsString('Wiadomosc zostala wyslana automatycznie.', $decorated->html);
        self::assertSame("Body\n\n--\nWiadomosc zostala wyslana automatycznie.\nzmierzymyczas.pl", $decorated->text);
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

        self::assertMatchesRegularExpression('/<body class="mail">.*<p>Body<\/p>.*Footer \$1.*Example.*<\/body>/s', $decorated->html);
        self::assertNull($decorated->text);
    }

    public function testDecoratesHtmlFragmentWithoutBodyTags(): void
    {
        $branding = new EmailBranding(new EmailBrandConfig(
            brandName: 'Example',
            footerHtml: '<div>Footer</div>'
        ));

        $decorated = $branding->apply($this->message('<p>Fragment only</p>', null));

        self::assertStringContainsString('<p>Fragment only</p>', $decorated->html);
        self::assertStringContainsString('Footer', $decorated->html);
        self::assertStringContainsString('Example', $decorated->html);
    }

    public function testKeepsBodyAttributesWithMultilineTag(): void
    {
        $branding = new EmailBranding(new EmailBrandConfig(
            brandName: 'Example',
            footerHtml: '<div>Footer</div>'
        ));
        $html = "<html><body style=\"margin:0;\n padding:0\" data-theme='dark'><p>Body</p></body></html>";

        $decorated = $branding->apply($this->message($html, null));

        self::assertStringContainsString('<p>Body</p>', $decorated->html);
        self::assertStringContainsString('Footer', $decorated->html);
        self::assertStringContainsString("data-theme='dark'", $decorated->html);
        self::assertSame(2, substr_count($decorated->html, '<body') + substr_count($decorated->html, '</body>'));
    }

    public function testPreservesCustomHeadersWhenDecorating(): void
    {
        $message = new EmailMessage(
            'message-id',
            'recipient@example.test',
            'Subject',
            '<p>Body</p>',
            null,
            [],
            ['List-Unsubscribe' => '<https://example.test/unsubscribe?token=abc>']
        );

        $decorated = (new EmailBranding(new EmailBrandConfig(footerHtml: '<div>Footer</div>')))->apply($message);

        self::assertSame($message->headers, $decorated->headers);
    }

    private function message(string $html, ?string $text): EmailMessage
    {
        return new EmailMessage('message-id', 'recipient@example.test', 'Subject', $html, $text);
    }
}
