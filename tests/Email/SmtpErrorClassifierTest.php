<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Email;

use CentralMailer\Email\PermanentSendException;
use CentralMailer\Email\SmtpErrorClassifier;
use CentralMailer\Email\TransientSendException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpErrorClassifierTest extends TestCase
{
    /** @return array<string, array{0: array<string, string>|null, 1: class-string, 2: bool}> */
    public static function classificationCases(): array
    {
        return [
            'no smtp error (connect/tls failure)' => [null, TransientSendException::class, false],
            'empty smtp error' => [['smtp_code' => '', 'smtp_code_ex' => ''], TransientSendException::class, false],
            '421 service unavailable' => [['smtp_code' => '421'], TransientSendException::class, false],
            '450 mailbox busy' => [['smtp_code' => '450', 'smtp_code_ex' => '4.2.1'], TransientSendException::class, false],
            '550 bad mailbox' => [['smtp_code' => '550', 'smtp_code_ex' => '5.1.1'], PermanentSendException::class, true],
            '550 bad domain' => [['smtp_code' => '550', 'smtp_code_ex' => '5.1.2'], PermanentSendException::class, true],
            '550 policy rejection' => [['smtp_code' => '550', 'smtp_code_ex' => '5.7.1'], PermanentSendException::class, false],
            '553 without enhanced code' => [['smtp_code' => '553'], PermanentSendException::class, false],
            '552 quota treated as transient' => [['smtp_code' => '552', 'smtp_code_ex' => '5.2.2'], TransientSendException::class, false],
            '554 transaction failed' => [['smtp_code' => '554'], PermanentSendException::class, false],
            '550 mailbox disabled' => [['smtp_code' => '550', 'smtp_code_ex' => '5.2.1'], PermanentSendException::class, true],
        ];
    }

    /** @param array<string, string>|null $smtpError */
    #[DataProvider('classificationCases')]
    public function testClassifiesSmtpErrors(?array $smtpError, string $expectedClass, bool $expectedRecipientPermanent): void
    {
        $wrapped = SmtpErrorClassifier::wrap(new \RuntimeException('SMTP error'), $smtpError);

        self::assertInstanceOf($expectedClass, $wrapped);
        self::assertSame($expectedRecipientPermanent, $wrapped->recipientPermanent);
        self::assertSame('SMTP error', $wrapped->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $wrapped->getPrevious());
    }

    public function testDoesNotDoubleWrapSendExceptions(): void
    {
        $original = new PermanentSendException('already wrapped', 550, '5.1.1', true);

        self::assertSame($original, SmtpErrorClassifier::wrap($original, null));
    }

    public function testErrorCodeFormatsSmtpAndEnhancedCode(): void
    {
        $withEnhanced = SmtpErrorClassifier::wrap(new \RuntimeException('x'), ['smtp_code' => '550', 'smtp_code_ex' => '5.1.1']);
        $withoutEnhanced = SmtpErrorClassifier::wrap(new \RuntimeException('x'), ['smtp_code' => '550']);
        $withoutCode = SmtpErrorClassifier::wrap(new \LogicException('x'), null);

        self::assertSame('smtp:550:5.1.1', $withEnhanced->errorCode());
        self::assertSame('smtp:550', $withoutEnhanced->errorCode());
        self::assertSame(\LogicException::class, $withoutCode->errorCode());
    }
}
