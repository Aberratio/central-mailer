<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Validation;

use CentralMailer\Config\Env;
use CentralMailer\Tests\Support\DnsStub;
use CentralMailer\Validation\EmailRequestValidator;
use PHPUnit\Framework\TestCase;

final class EmailRequestValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        DnsStub::reset();
    }

    public function testRejectsReservedExampleDomainEvenWhenDnsValidationIsDisabled(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient email domain cannot receive email');

        $validator->validateQueuePayload($this->payload('recipient@example.com'));
    }

    public function testSkipsDnsLookupsWhenValidationIsDisabled(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateQueuePayload($this->payload('recipient@no-mail.invalid'));

        self::assertSame('recipient@no-mail.invalid', $result['to']);
        self::assertSame('normal', $result['priority']);
        self::assertSame(0, DnsStub::$mxLookupCount);
        self::assertSame(0, DnsStub::$addressLookupCount);
    }

    public function testAcceptsDomainWithMxRecord(): void
    {
        DnsStub::$mxRecords = [['target' => 'mail.deliverable.test.']];
        $validator = new EmailRequestValidator(new Env([]));

        $result = $validator->validateQueuePayload($this->payload('recipient@deliverable.test'));

        self::assertSame('recipient@deliverable.test', $result['to']);
        self::assertSame('normal', $result['priority']);
        self::assertSame(1, DnsStub::$mxLookupCount);
        self::assertSame(0, DnsStub::$addressLookupCount);
    }

    public function testRejectsNullMxRecord(): void
    {
        DnsStub::$mxRecords = [['target' => '.']];
        $validator = new EmailRequestValidator(new Env([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient email domain cannot receive email');

        $validator->validateQueuePayload($this->payload('recipient@deliverable.test'));
    }

    public function testRejectsDomainWithoutMxOrAddressRecord(): void
    {
        $validator = new EmailRequestValidator(new Env([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient email domain has no mail server');

        $validator->validateQueuePayload($this->payload('recipient@no-mail.invalid'));
    }

    public function testAcceptsAddressRecordFallbackWhenMxRecordIsMissing(): void
    {
        DnsStub::$hasARecord = true;
        $validator = new EmailRequestValidator(new Env([]));

        $result = $validator->validateQueuePayload($this->payload('recipient@deliverable.test'));

        self::assertSame('recipient@deliverable.test', $result['to']);
        self::assertSame('normal', $result['priority']);
        self::assertSame(1, DnsStub::$addressLookupCount);
    }

    public function testAcceptsTechnicalPriority(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateQueuePayload([
            'to' => 'developer@internal.test',
            'subject' => 'Technical alert',
            'html' => '<p>Alert</p>',
            'priority' => 'technical',
        ]);

        self::assertSame('technical', $result['priority']);
    }

    public function testSingleEmailDefaultsToTransactionalCategory(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateQueuePayload([
            'to' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
        ]);

        self::assertSame('transactional', $result['category']);
    }

    public function testAcceptsExplicitMarketingCategory(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateQueuePayload([
            'to' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
            'category' => 'marketing',
        ]);

        self::assertSame('marketing', $result['category']);
    }

    public function testTechnicalPriorityForcesTransactionalCategory(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateQueuePayload([
            'to' => 'developer@internal.test',
            'subject' => 'Technical alert',
            'html' => '<p>Alert</p>',
            'priority' => 'technical',
            'category' => 'marketing',
        ]);

        self::assertSame('transactional', $result['category']);
    }

    public function testRejectsInvalidCategory(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category must be transactional or marketing');

        $validator->validateQueuePayload([
            'to' => 'recipient@deliverable.test',
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
            'category' => 'newsletter',
        ]);
    }

    public function testBatchDefaultsToMarketingCategory(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateBatchPayload([
            'subject' => 'Newsletter',
            'html' => '<p>News</p>',
            'recipients' => [['to' => 'recipient@deliverable.test']],
        ]);

        self::assertSame('marketing', $result['category']);
    }

    public function testBatchCanOptIntoTransactionalCategory(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateBatchPayload([
            'subject' => 'Invoices',
            'html' => '<p>Invoice</p>',
            'category' => 'transactional',
            'recipients' => [['to' => 'recipient@deliverable.test']],
        ]);

        self::assertSame('transactional', $result['category']);
    }

    public function testAcceptsInlineAttachmentFlagWithContentId(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateQueuePayload([
            'to' => 'recipient@deliverable.test',
            'subject' => 'QR',
            'html' => '<img src="cid:qr-inline">',
            'attachments' => [[
                'filename' => 'qr.png',
                'contentBase64' => self::tinyPngBase64(),
                'contentId' => 'qr-inline',
                'inline' => true,
            ]],
        ]);

        self::assertTrue($result['attachments'][0]['inline']);
        self::assertSame('qr-inline', $result['attachments'][0]['contentId']);
    }

    public function testRejectsInlineAttachmentWithoutContentId(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Inline attachment at index 0 requires contentId');

        $validator->validateQueuePayload([
            'to' => 'recipient@deliverable.test',
            'subject' => 'QR',
            'html' => '<img src="cid:qr-inline">',
            'attachments' => [[
                'filename' => 'qr.png',
                'contentBase64' => self::tinyPngBase64(),
                'inline' => true,
            ]],
        ]);
    }

    public function testRejectsContentIdWhenInlineIsFalse(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment inline at index 0 cannot be false when contentId is set');

        $validator->validateQueuePayload([
            'to' => 'recipient@deliverable.test',
            'subject' => 'QR',
            'html' => '<img src="cid:qr-inline">',
            'attachments' => [[
                'filename' => 'qr.png',
                'contentBase64' => self::tinyPngBase64(),
                'contentId' => 'qr-inline',
                'inline' => false,
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $to): array
    {
        return [
            'to' => $to,
            'subject' => 'Subject',
            'html' => '<p>Body</p>',
        ];
    }

    private static function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=';
    }
}
