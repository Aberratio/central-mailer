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
        $mail->isSMTP();
        $mail->Host = $this->env->string('SMTP_HOST');
        $mail->Port = $this->env->int('SMTP_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = $this->env->string('SMTP_USER');
        $mail->Password = $this->env->string('SMTP_PASSWORD');

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
}
