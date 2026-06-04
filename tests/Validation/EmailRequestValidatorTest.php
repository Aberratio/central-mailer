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

        $validator->validateTestPayload(['to' => 'recipient@example.com']);
    }

    public function testSkipsDnsLookupsWhenValidationIsDisabled(): void
    {
        $validator = new EmailRequestValidator(new Env(['EMAIL_VALIDATE_RECIPIENT_MX' => 'false']));

        $result = $validator->validateTestPayload(['to' => 'recipient@no-mail.invalid']);

        self::assertSame(['to' => 'recipient@no-mail.invalid'], $result);
        self::assertSame(0, DnsStub::$mxLookupCount);
        self::assertSame(0, DnsStub::$addressLookupCount);
    }

    public function testAcceptsDomainWithMxRecord(): void
    {
        DnsStub::$mxRecords = [['target' => 'mail.deliverable.test.']];
        $validator = new EmailRequestValidator(new Env([]));

        $result = $validator->validateTestPayload(['to' => 'recipient@deliverable.test']);

        self::assertSame(['to' => 'recipient@deliverable.test'], $result);
        self::assertSame(1, DnsStub::$mxLookupCount);
        self::assertSame(0, DnsStub::$addressLookupCount);
    }

    public function testRejectsNullMxRecord(): void
    {
        DnsStub::$mxRecords = [['target' => '.']];
        $validator = new EmailRequestValidator(new Env([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient email domain cannot receive email');

        $validator->validateTestPayload(['to' => 'recipient@deliverable.test']);
    }

    public function testRejectsDomainWithoutMxOrAddressRecord(): void
    {
        $validator = new EmailRequestValidator(new Env([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient email domain has no mail server');

        $validator->validateTestPayload(['to' => 'recipient@no-mail.invalid']);
    }

    public function testAcceptsAddressRecordFallbackWhenMxRecordIsMissing(): void
    {
        DnsStub::$hasARecord = true;
        $validator = new EmailRequestValidator(new Env([]));

        $result = $validator->validateTestPayload(['to' => 'recipient@deliverable.test']);

        self::assertSame(['to' => 'recipient@deliverable.test'], $result);
        self::assertSame(1, DnsStub::$addressLookupCount);
    }
}
