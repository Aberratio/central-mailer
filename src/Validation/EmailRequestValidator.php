<?php

declare(strict_types=1);

namespace CentralMailer\Validation;

use CentralMailer\Config\Env;

final class EmailRequestValidator
{
    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_HTML_BYTES = 1_000_000;
    private const MAX_TEXT_BYTES = 1_000_000;
    private const NON_DELIVERABLE_DOMAINS = [
        'example.com',
        'example.net',
        'example.org',
    ];

    public function __construct(private readonly Env $env)
    {
    }

    /** @param array<string, mixed> $payload */
    public function validateQueuePayload(array $payload): array
    {
        $to = $this->email($payload['to'] ?? null);
        $subject = $this->requiredString($payload['subject'] ?? null, 'subject');
        $html = $this->requiredString($payload['html'] ?? null, 'html');
        $text = $this->optionalString($payload['text'] ?? null, 'text');
        $priority = $payload['priority'] ?? 'normal';
        $metadata = $payload['metadata'] ?? null;

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new \InvalidArgumentException('Subject is too long');
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new \InvalidArgumentException('HTML body is too large');
        }

        if ($text !== null && strlen($text) > self::MAX_TEXT_BYTES) {
            throw new \InvalidArgumentException('Text body is too large');
        }

        if (!in_array($priority, ['normal', 'high'], true)) {
            throw new \InvalidArgumentException('Priority must be normal or high');
        }

        if ($metadata !== null && !is_array($metadata)) {
            throw new \InvalidArgumentException('Metadata must be an object');
        }

        return [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'priority' => $priority,
            'metadata' => $metadata,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function validateTestPayload(array $payload): array
    {
        return ['to' => $this->email($payload['to'] ?? null)];
    }

    private function email(mixed $value): string
    {
        $email = $this->requiredString($value, 'to');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Recipient email is invalid');
        }

        $this->validateRecipientDomain($email);

        return $email;
    }

    private function validateRecipientDomain(string $email): void
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '' || in_array($domain, self::NON_DELIVERABLE_DOMAINS, true)) {
            throw new \InvalidArgumentException('Recipient email domain cannot receive email');
        }

        if (!$this->env->bool('EMAIL_VALIDATE_RECIPIENT_MX', true)) {
            return;
        }

        $mxRecords = dns_get_record($domain, DNS_MX) ?: [];
        foreach ($mxRecords as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($target === '') {
                throw new \InvalidArgumentException('Recipient email domain cannot receive email');
            }
        }

        if ($mxRecords === [] && !checkdnsrr($domain, 'A') && !checkdnsrr($domain, 'AAAA')) {
            throw new \InvalidArgumentException('Recipient email domain has no mail server');
        }
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s is required', $field));
        }

        return trim($value);
    }

    private function optionalString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string', $field));
        }

        return $value;
    }
}
