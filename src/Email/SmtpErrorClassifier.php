<?php

declare(strict_types=1);

namespace CentralMailer\Email;

final class SmtpErrorClassifier
{
    /**
     * Enhanced status codes (RFC 3463) that identify a permanently dead recipient
     * mailbox, safe to feed into the suppression list.
     */
    private const RECIPIENT_PERMANENT_PATTERNS = [
        '/^5\.1\.\d+$/', // bad destination mailbox/system/address
        '/^5\.2\.1$/',   // mailbox disabled
    ];

    /**
     * @param array{error?: string, detail?: string, smtp_code?: string|int, smtp_code_ex?: string}|null $smtpError
     */
    public static function wrap(\Throwable $exception, ?array $smtpError): SendException
    {
        if ($exception instanceof SendException) {
            return $exception;
        }

        $smtpCode = null;
        $rawCode = $smtpError['smtp_code'] ?? '';
        if (is_int($rawCode) || preg_match('/^\d{3}$/', (string) $rawCode) === 1) {
            $smtpCode = (int) $rawCode;
        }
        $enhancedCode = null;
        $rawEnhanced = (string) ($smtpError['smtp_code_ex'] ?? '');
        if (preg_match('/^\d\.\d{1,3}\.\d{1,3}$/', $rawEnhanced) === 1) {
            $enhancedCode = $rawEnhanced;
        }

        if (self::isPermanent($smtpCode)) {
            return new PermanentSendException(
                $exception->getMessage(),
                $smtpCode,
                $enhancedCode,
                self::isRecipientPermanent($enhancedCode),
                $exception
            );
        }

        return new TransientSendException($exception->getMessage(), $smtpCode, $enhancedCode, false, $exception);
    }

    private static function isPermanent(?int $smtpCode): bool
    {
        if ($smtpCode === null || $smtpCode < 500 || $smtpCode > 599) {
            return false;
        }

        // 552 is historically used by servers where 452 (over quota) was meant
        // (RFC 5321 section 4.5.3.1.10) - treat it as transient.
        return $smtpCode !== 552;
    }

    private static function isRecipientPermanent(?string $enhancedCode): bool
    {
        if ($enhancedCode === null) {
            return false;
        }

        foreach (self::RECIPIENT_PERMANENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $enhancedCode) === 1) {
                return true;
            }
        }

        return false;
    }
}
