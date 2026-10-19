<?php

declare(strict_types=1);

namespace CentralMailer\Email;

use CentralMailer\Config\Env;

/** Selects the outgoing mail transport (MAIL_TRANSPORT: smtp (default) | log). */
final class MailTransport
{
    public const SMTP = 'smtp';
    public const LOG = 'log';

    /** @var list<string> */
    private const LOG_ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public static function name(Env $env): string
    {
        $name = strtolower($env->string('MAIL_TRANSPORT', self::SMTP));
        if (!in_array($name, [self::SMTP, self::LOG], true)) {
            throw new \RuntimeException('MAIL_TRANSPORT must be smtp or log');
        }

        return $name;
    }

    /** Throws when MAIL_TRANSPORT is invalid or is `log` outside APP_ENV local/testing. */
    public static function assertValid(Env $env): void
    {
        if (self::name($env) === self::LOG
            && !in_array(strtolower($env->string('APP_ENV', 'local')), self::LOG_ALLOWED_ENVIRONMENTS, true)
        ) {
            throw new \RuntimeException('MAIL_TRANSPORT=log is allowed only when APP_ENV is local or testing');
        }
    }

    /** @param 'standard'|'technical' $queue */
    public static function createProvider(Env $env, string $queue, string $projectRoot): EmailProviderInterface
    {
        self::assertValid($env);

        if (self::name($env) === self::LOG) {
            $fromKey = $queue === 'technical' ? 'GMAIL_FROM_EMAIL' : 'SMTP_FROM_EMAIL';

            return new LogEmailProvider(
                $env->string('MAIL_LOG_DIR', rtrim($projectRoot, '/') .'/storage/mail'),
                $env->string($fromKey, 'noreply@example.test')
            );
        }

        return $queue === 'technical' ? new GmailSmtpEmailProvider($env) : new SmtpEmailProvider($env);
    }
}
