<?php

declare(strict_types=1);

namespace CentralMailer\Email;

use CentralMailer\Config\Env;
use PHPMailer\PHPMailer\PHPMailer;

final class GmailSmtpEmailProvider implements EmailProviderInterface
{
    private ?PHPMailer $mailer = null;

    public function __construct(
        private readonly Env $env,
        private readonly EmailBrandConfig $brandConfig = new EmailBrandConfig()
    ) {
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        $mail = $this->mailer();
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->Subject = '';
        $mail->Body = '';
        $mail->AltBody = '';
        $mail->MessageID = sprintf('<%s@%s>', $message->id, $this->messageIdDomain());
        $mail->addAddress($message->to);
        $mail->Subject = $message->subject;
        $mail->Body = $message->html;
        if ($message->text !== null) {
            $mail->AltBody = $message->text;
        }
        foreach ($message->attachments as $attachment) {
            if ($attachment->inline) {
                $this->addInlineAttachment($mail, $attachment);
                continue;
            }

            $mail->addAttachment($attachment->path, $attachment->filename, PHPMailer::ENCODING_BASE64, $attachment->contentType);
        }

        try {
            $mail->send();
        } catch (\Throwable $exception) {
            $mail->smtpClose();
            $this->mailer = null;
            throw $exception;
        }

        return new EmailSendResult($mail->getLastMessageID() ?: null);
    }

    private function mailer(): PHPMailer
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->Port = $this->env->int('GMAIL_SMTP_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = $this->env->string('GMAIL_SMTP_USER');
        $mail->Password = $this->env->string('GMAIL_SMTP_APP_PASSWORD');
        $mail->Timeout = $this->env->int('GMAIL_SMTP_TIMEOUT_SECONDS', 30);
        $mail->SMTPKeepAlive = true;
        $mail->Hostname = $this->messageIdDomain();
        $mail->Helo = $mail->Hostname;

        $secure = strtolower($this->env->string('GMAIL_SMTP_SECURE', 'tls'));
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            throw new \RuntimeException('GMAIL_SMTP_SECURE must be tls or ssl');
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->env->string('GMAIL_FROM_EMAIL'), $this->brandConfig->senderName);
        $this->addReplyTo($mail);
        $mail->isHTML(true);

        return $this->mailer = $mail;
    }

    private function messageIdDomain(): string
    {
        $configured = $this->env->nullableString('GMAIL_MESSAGE_ID_DOMAIN');
        if ($configured !== null) {
            return $configured;
        }

        $fromEmail = $this->env->string('GMAIL_FROM_EMAIL');
        $domain = substr(strrchr($fromEmail, '@') ?: '', 1);

        return $domain !== '' ? $domain : 'localhost.localdomain';
    }

    private function addReplyTo(PHPMailer $mail): void
    {
        if ($this->brandConfig->replyToEmail !== null) {
            $mail->addReplyTo(
                $this->brandConfig->replyToEmail,
                $this->brandConfig->replyToName ?? $this->brandConfig->senderName
            );
        }
    }

    private function addInlineAttachment(PHPMailer $mail, EmailAttachment $attachment): void
    {
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
            '',
            PHPMailer::ENCODING_BASE64,
            $attachment->contentType,
            'inline'
        );
    }
}
