<?php

declare(strict_types=1);

namespace CentralMailer\Validation;

use CentralMailer\Config\Env;

final class EmailRequestValidator
{
    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_HTML_BYTES = 1_000_000;
    private const MAX_TEXT_BYTES = 1_000_000;
    private const MAX_METADATA_BYTES = 64_000;
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
        $priority = $this->priority($payload['priority'] ?? 'normal');
        $metadata = $payload['metadata'] ?? null;
        $attachments = $this->attachments($payload['attachments'] ?? []);

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new \InvalidArgumentException('Subject is too long');
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new \InvalidArgumentException('HTML body is too large');
        }

        if ($text !== null && strlen($text) > self::MAX_TEXT_BYTES) {
            throw new \InvalidArgumentException('Text body is too large');
        }

        if ($metadata !== null && !is_array($metadata)) {
            throw new \InvalidArgumentException('Metadata must be an object');
        }
        if ($metadata !== null && strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) > self::MAX_METADATA_BYTES) {
            throw new \InvalidArgumentException('Metadata is too large');
        }

        return [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'priority' => $priority,
            'metadata' => $metadata,
            'attachments' => $attachments,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function validateBatchPayload(array $payload): array
    {
        $recipients = $payload['recipients'] ?? null;
        if (!is_array($recipients) || $recipients === []) {
            throw new \InvalidArgumentException('Recipients must be a non-empty array');
        }
        $maxRecipients = $this->env->int('EMAIL_BATCH_MAX_RECIPIENTS', 1000);
        if (count($recipients) > $maxRecipients) {
            throw new \InvalidArgumentException(sprintf('Batch cannot contain more than %d recipients', $maxRecipients));
        }

        $firstRecipient = $recipients[0];
        if (!is_array($firstRecipient)) {
            throw new \InvalidArgumentException('Recipient at index 0 must be an object');
        }

        $common = $this->validateQueuePayload([
            'to' => $firstRecipient['to'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'html' => $payload['html'] ?? null,
            'text' => $payload['text'] ?? null,
            'priority' => $payload['priority'] ?? 'normal',
            'metadata' => $payload['metadata'] ?? null,
            'attachments' => $payload['attachments'] ?? [],
        ]);
        unset($common['to'], $common['attachments']);
        $commonAttachments = $this->attachments($payload['attachments'] ?? []);

        $validatedRecipients = [];
        foreach ($recipients as $index => $recipient) {
            if (!is_array($recipient)) {
                throw new \InvalidArgumentException(sprintf('Recipient at index %d must be an object', $index));
            }
            $metadata = $recipient['metadata'] ?? null;
            if ($metadata !== null && !is_array($metadata)) {
                throw new \InvalidArgumentException(sprintf('Recipient metadata at index %d must be an object', $index));
            }
            if ($metadata !== null && strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) > self::MAX_METADATA_BYTES) {
                throw new \InvalidArgumentException(sprintf('Recipient metadata at index %d is too large', $index));
            }
            $subject = $this->optionalString($recipient['subject'] ?? null, 'recipient subject');
            if ($subject !== null && mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                throw new \InvalidArgumentException(sprintf('Recipient subject at index %d is too long', $index));
            }
            $html = $this->optionalString($recipient['html'] ?? null, 'recipient html');
            if ($html !== null && strlen($html) > self::MAX_HTML_BYTES) {
                throw new \InvalidArgumentException(sprintf('Recipient HTML body at index %d is too large', $index));
            }
            $text = $this->optionalString($recipient['text'] ?? null, 'recipient text');
            if ($text !== null && strlen($text) > self::MAX_TEXT_BYTES) {
                throw new \InvalidArgumentException(sprintf('Recipient text body at index %d is too large', $index));
            }

            $recipientAttachments = $this->attachments($recipient['attachments'] ?? []);
            $maxCount = $this->env->int('EMAIL_ATTACHMENT_MAX_COUNT', 5);
            if (count($commonAttachments) + count($recipientAttachments) > $maxCount) {
                throw new \InvalidArgumentException(sprintf('Recipient attachments at index %d exceed the %d file limit', $index, $maxCount));
            }
            $combinedAttachmentBytes = array_sum(array_column($commonAttachments, 'sizeBytes'))
                + array_sum(array_column($recipientAttachments, 'sizeBytes'));
            $maxBytes = $this->env->int('EMAIL_ATTACHMENT_MAX_TOTAL_BYTES', 5_000_000);
            if ($combinedAttachmentBytes > $maxBytes) {
                throw new \InvalidArgumentException(sprintf('Recipient attachments at index %d exceed the %d byte limit', $index, $maxBytes));
            }

            $validatedRecipients[] = [
                'to' => $this->email($recipient['to'] ?? null),
                'metadata' => $metadata,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'attachments' => $recipientAttachments,
            ];
        }

        return [...$common, 'attachments' => $commonAttachments, 'recipients' => $validatedRecipients];
    }

    private function priority(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['normal', 'high', 'technical'], true)) {
            throw new \InvalidArgumentException('Priority must be normal, high or technical');
        }

        return $value;
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

    /**
     * @return list<array{filename: string, contentType: string, content: string, sizeBytes: int, sha256: string, contentId: string|null}>
     */
    private function attachments(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Attachments must be an array');
        }

        $maxCount = $this->env->int('EMAIL_ATTACHMENT_MAX_COUNT', 5);
        $maxBytes = $this->env->int('EMAIL_ATTACHMENT_MAX_TOTAL_BYTES', 5_000_000);
        if (count($value) > $maxCount) {
            throw new \InvalidArgumentException(sprintf('Attachments cannot contain more than %d files', $maxCount));
        }

        $allowedTypes = array_filter(array_map(
            'trim',
            explode(',', $this->env->string('EMAIL_ATTACHMENT_ALLOWED_MIME_TYPES', 'image/png,application/pdf'))
        ));
        $totalBytes = 0;
        $attachments = [];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($value as $index => $attachment) {
            if (!is_array($attachment)) {
                throw new \InvalidArgumentException(sprintf('Attachment at index %d must be an object', $index));
            }

            $filename = $this->requiredString($attachment['filename'] ?? null, 'attachment filename');
            if (mb_strlen($filename) > 255 || basename($filename) !== $filename || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
                throw new \InvalidArgumentException(sprintf('Attachment filename at index %d is invalid', $index));
            }
            $contentId = $this->optionalContentId($attachment['contentId'] ?? null, $index);

            $encoded = $attachment['contentBase64'] ?? null;
            if (!is_string($encoded) || $encoded === '') {
                throw new \InvalidArgumentException(sprintf('Attachment contentBase64 at index %d is required', $index));
            }
            $content = base64_decode($encoded, true);
            if ($content === false) {
                throw new \InvalidArgumentException(sprintf('Attachment contentBase64 at index %d is invalid', $index));
            }

            $contentType = (string) $finfo->buffer($content);
            if (!in_array($contentType, $allowedTypes, true)) {
                throw new \InvalidArgumentException(sprintf('Attachment MIME type %s is not allowed', $contentType));
            }
            if ($contentId !== null && !str_starts_with($contentType, 'image/')) {
                throw new \InvalidArgumentException(sprintf('Inline attachment at index %d must be an image', $index));
            }

            $sizeBytes = strlen($content);
            $totalBytes += $sizeBytes;
            if ($totalBytes > $maxBytes) {
                throw new \InvalidArgumentException(sprintf('Attachments exceed the %d byte limit', $maxBytes));
            }

            $attachments[] = [
                'filename' => $filename,
                'contentType' => $contentType,
                'content' => $content,
                'sizeBytes' => $sizeBytes,
                'sha256' => hash('sha256', $content),
                'contentId' => $contentId,
            ];
        }

        return $attachments;
    }

    private function optionalContentId(mixed $value, int $index): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Attachment contentId at index %d must be a string', $index));
        }

        $contentId = trim($value);
        if (
            $contentId === ''
            || strlen($contentId) > 255
            || str_starts_with(strtolower($contentId), 'cid:')
            || preg_match('/^[A-Za-z0-9._%+-]+(@[A-Za-z0-9.-]+)?$/', $contentId) !== 1
        ) {
            throw new \InvalidArgumentException(sprintf('Attachment contentId at index %d is invalid', $index));
        }

        return $contentId;
    }
}
