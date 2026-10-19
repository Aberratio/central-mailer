<?php

declare(strict_types=1);

namespace CentralMailer\Email;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Local/testing transport: builds the complete MIME message exactly like the SMTP providers
 * but writes it to an `.eml` file instead of sending it. Selected with MAIL_TRANSPORT=log,
 * which MailTransport only allows when APP_ENV is local or testing.
 */
final class LogEmailProvider implements EmailProviderInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly string $fromEmail,
        private readonly EmailBrandConfig $brandConfig = new EmailBrandConfig()
    ) {
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $domain = substr(strrchr($this->fromEmail, '@') ?: '', 1);
        $mail->Hostname = $domain !== '' ? $domain : 'localhost.localdomain';
        $mail->setFrom($this->fromEmail, $this->brandConfig->senderName);
        if ($this->brandConfig->replyToEmail !== null) {
            $mail->addReplyTo(
                $this->brandConfig->replyToEmail,
                $this->brandConfig->replyToName ?? $this->brandConfig->senderName
            );
        }
        $mail->MessageID = sprintf('<%s@%s>', $message->id, $mail->Hostname);
        $mail->addAddress($message->to);
        $mail->Subject = $message->subject;
        $mail->Body = $message->html;
        $mail->AltBody = $message->text ?? trim($mail->html2text($message->html));
        foreach ($message->attachments as $attachment) {
            if ($attachment->inline) {
                if ($attachment->contentId === null) {
                    throw new \RuntimeException('Inline attachment requires contentId');
                }
                $content = file_get_contents($attachment->path);
                if ($content === false) {
                    throw new \RuntimeException('Unable to read inline attachment');
                }
                $mail->addStringEmbeddedImage(
                    $content,
                    $attachment->contentId,
                    $attachment->filename,
                    PHPMailer::ENCODING_BASE64,
                    $attachment->contentType,
                    'inline'
                );
                continue;
            }

            $mail->addAttachment($attachment->path, $attachment->filename, PHPMailer::ENCODING_BASE64, $attachment->contentType);
        }
        foreach ($message->headers as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }

        $mail->preSend();
        $mime = $mail->getSentMIMEMessage();

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create MAIL_LOG_DIR');
        }
        $file = sprintf(
            '%s/%s-%s.eml',
            rtrim($this->directory, '/'),
            gmdate('Ymd\THis') . sprintf('%06d', (int) (fmod(microtime(true), 1) * 1000000)),
            preg_replace('/[^A-Za-z0-9_-]/', '_', $message->id)
        );
        if (file_put_contents($file, $mime) === false) {
            throw new \RuntimeException('Unable to write mail log file');
        }

        return new EmailSendResult($mail->getLastMessageID() ?: null);
    }
}
