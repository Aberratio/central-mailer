<?php

declare(strict_types=1);

namespace CentralMailer\Email;

final class EmailBranding
{
    public function __construct(private readonly EmailBrandConfig $config = new EmailBrandConfig())
    {
    }

    public function apply(EmailMessage $message): EmailMessage
    {
        $header = $this->htmlHeader();
        $footer = $this->htmlFooter();
        $html = $this->decorateHtml($message->html, $header, $footer);
        $text = $this->decorateText($message->text);

        if ($html === $message->html && $text === $message->text) {
            return $message;
        }

        return new EmailMessage(
            $message->id,
            $message->to,
            $message->subject,
            $html,
            $text,
            $message->attachments
        );
    }

    private function htmlHeader(): string
    {
        $logo = $this->config->logoUrl === null
            ? ''
            : sprintf(
                '<img src="%s" alt="%s" style="display:block;max-width:180px;max-height:64px;border:0;">',
                $this->escape($this->config->logoUrl),
                $this->escape($this->config->brandName)
            );
        $name = sprintf(
            '<span style="font-family:Arial,sans-serif;font-size:20px;font-weight:700;color:#111827;">%s</span>',
            $this->escape($this->config->brandName)
        );

        return sprintf(
            '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px 0;"><tr><td style="vertical-align:middle;">%s</td><td style="vertical-align:middle;text-align:right;">%s</td></tr></table>',
            $logo,
            $name
        );
    }

    private function htmlFooter(): string
    {
        return sprintf(
            '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">%s<div style="margin-top:8px;">%s</div></div>',
            $this->config->footerHtml,
            $this->escape($this->config->brandName)
        );
    }

    private function decorateHtml(string $html, string $header, string $footer): string
    {
        if ($header === '' && $footer === '') {
            return $html;
        }

        if (preg_match('/<body\b[^>]*>/i', $html) === 1) {
            $html = preg_replace_callback(
                '/<body\b[^>]*>/i',
                static fn (array $matches): string => $matches[0] . $header,
                $html,
                1
            ) ?? $html;
        } else {
            $html = $header . $html;
        }

        if ($footer === '') {
            return $html;
        }

        if (preg_match('/<\/body\s*>/i', $html) === 1) {
            return preg_replace_callback(
                '/<\/body\s*>/i',
                static fn (): string => $footer . '</body>',
                $html,
                1
            ) ?? $html;
        }

        return $html . $footer;
    }

    private function decorateText(?string $text): ?string
    {
        if ($text === null) {
            return $text;
        }

        return rtrim($text) . "\n\n--\n" . $this->config->footerText . "\n" . $this->config->brandName;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
