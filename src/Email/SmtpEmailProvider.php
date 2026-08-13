<?php

declare(strict_types=1);

namespace CentralMailer\Email;

use CentralMailer\Config\Env;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpEmailProvider implements EmailProviderInterface
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
        // The mailer instance is reused across sends (SMTPKeepAlive) - without this a
        // transactional email would inherit the previous message's List-Unsubscribe header.
        $mail->clearCustomHeaders();
        $mail->Subject = '';
        $mail->Body = '';
        $mail->AltBody = '';
        $mail->MessageID = $this->messageId($message);
        $mail->addAddress($message->to);
        $mail->Subject = $message->subject;
        $mail->Body = $message->html;
        if ($message->text !== null) {
            $mail->AltBody = $message->text;
        } else {
            // HTML-only mail is a spam signal; derive the text/plain part from the HTML body.
            $altBody = trim($mail->html2text($message->html));
            if ($altBody !== '') {
                $mail->AltBody = $altBody;
            }
        }
        foreach ($message->attachments as $attachment) {
            if ($attachment->inline) {
                $this->addInlineAttachment($mail, $attachment);
                continue;
            }

            $mail->addAttachment($attachment->path, $attachment->filename, PHPMailer::ENCODING_BASE64, $attachment->contentType);
        }
        foreach ($message->headers as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }

        try {
            $mail->send();
        } catch (\Throwable $exception) {
            $smtpError = $mail->getSMTPInstance()->getError();
            $mail->smtpClose();
            $this->mailer = null;
            throw SmtpErrorClassifier::wrap($exception, $smtpError);
        }

        return new EmailSendResult($mail->getLastMessageID() ?: null);
    }

    private function mailer(): PHPMailer
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = $this->env->int('SMTP_DEBUG_LEVEL', 0);
        if ($mail->SMTPDebug > 0) {
            $mail->Debugoutput = static function ($str, $level): void {
                error_log("SMTP DEBUG [$level]: $str");
            };
        }
        $mail->isSMTP();
        $mail->Host = $this->env->string('SMTP_HOST');
        $mail->Port = $this->env->int('SMTP_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = $this->env->string('SMTP_USER');
        $mail->Password = $this->env->string('SMTP_PASSWORD');
        $mail->Timeout = $this->env->int('SMTP_TIMEOUT_SECONDS', 30);
        $mail->SMTPKeepAlive = true;
        $mail->Hostname = $this->messageIdDomain();
        $mail->Helo = $mail->Hostname;

        $secure = strtolower($this->env->string('SMTP_SECURE', 'tls'));
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            throw new \RuntimeException('SMTP_SECURE must be tls or ssl');
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->env->string('SMTP_FROM_EMAIL'), $this->brandConfig->senderName);
        $this->addReplyTo($mail);
        $mail->isHTML(true);
        $this->configureDkim($mail);

        return $this->mailer = $mail;
    }

    private function configureDkim(PHPMailer $mail): void
    {
        if (!$this->env->bool('DKIM_ENABLED', false)) {
            return;
        }

        $keyPath = $this->env->string('DKIM_PRIVATE_KEY_PATH');
        if (!is_file($keyPath)) {
            throw new \RuntimeException('DKIM_PRIVATE_KEY_PATH does not point to a readable private key file');
        }

        $fromEmail = $this->env->string('SMTP_FROM_EMAIL');
        $mail->DKIM_domain = $this->env->string('DKIM_DOMAIN', substr(strrchr($fromEmail, '@') ?: '', 1));
        $mail->DKIM_selector = $this->env->string('DKIM_SELECTOR', 'mail');
        $mail->DKIM_private = $keyPath;
        $mail->DKIM_passphrase = $this->env->nullableString('DKIM_PASSPHRASE') ?? '';
        $mail->DKIM_identity = $fromEmail;
        $mail->DKIM_copyHeaderFields = false;
    }

    private function messageIdDomain(): string
    {
        $configured = $this->env->nullableString('SMTP_MESSAGE_ID_DOMAIN');
        if ($configured !== null) {
            return $configured;
        }

        $fromEmail = $this->env->string('SMTP_FROM_EMAIL');
        $domain = substr(strrchr($fromEmail, '@') ?: '', 1);

        return $domain !== '' ? $domain : 'localhost.localdomain';
    }

    private function messageId(EmailMessage $message): string
    {
        return sprintf('<%s@%s>', $message->id, $this->messageIdDomain());
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

        // The filename is required: Gmail refuses to render a cid: image whose part carries
        // no name/filename and falls back to showing a broken image plus a nameless attachment.
        $mail->addStringEmbeddedImage(
            $content,
            $attachment->contentId,
            $attachment->filename,
            PHPMailer::ENCODING_BASE64,
            $attachment->contentType,
            'inline'
        );
    }
}
