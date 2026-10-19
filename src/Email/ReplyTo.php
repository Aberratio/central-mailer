<?php

declare(strict_types=1);

namespace CentralMailer\Email;

use PHPMailer\PHPMailer\PHPMailer;

/** Applies the Reply-To header: the per-message value wins over the global EmailBrandConfig one. */
final class ReplyTo
{
    public static function apply(PHPMailer $mail, EmailMessage $message, EmailBrandConfig $brandConfig): void
    {
        // PHPMailer instances are reused across sends (SMTPKeepAlive), so never inherit the previous value.
        $mail->clearReplyTos();

        if ($message->replyToEmail !== null) {
            $mail->addReplyTo($message->replyToEmail, $message->replyToName ?? '');

            return;
        }

        if ($brandConfig->replyToEmail !== null) {
            $mail->addReplyTo($brandConfig->replyToEmail, $brandConfig->replyToName ?? $brandConfig->senderName);
        }
    }
}
