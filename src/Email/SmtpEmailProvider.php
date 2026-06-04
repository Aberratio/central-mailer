<?php

declare(strict_types=1);

namespace CentralMailer\Email;

use CentralMailer\Config\Env;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpEmailProvider implements EmailProviderInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = static function ($str, $level): void {
            error_log("SMTP DEBUG [$level]: $str");
        };
        $mail->isSMTP();
        $mail->Host = $this->env->string('SMTP_HOST');
        $mail->Port = $this->env->int('SMTP_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = $this->env->string('SMTP_USER');
        $mail->Password = $this->env->string('SMTP_PASSWORD');
        $mail->Timeout = $this->env->int('SMTP_TIMEOUT_SECONDS', 30);
        $mail->Hostname = $this->messageIdDomain();
        $mail->Helo = $mail->Hostname;

        $secure = strtolower($this->env->string('SMTP_SECURE', 'tls'));
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->env->string('SMTP_FROM_EMAIL'), $this->env->string('SMTP_FROM_NAME', ''));
        $mail->addAddress($message->to);
        $mail->Subject = $message->subject;
        $mail->isHTML(true);
        $mail->Body = $message->html;
        if ($message->text !== null) {
            $mail->AltBody = $message->text;
        }

        $mail->send();

        return new EmailSendResult($mail->getLastMessageID() ?: null);
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
}
