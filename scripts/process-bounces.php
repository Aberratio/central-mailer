<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Database\Connection;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Suppression\BounceMailboxProcessor;
use CentralMailer\Suppression\SuppressionRepository;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);

if (!function_exists('imap_open')) {
    echo "SKIP: PHP ext-imap is not available. Enable it in the hosting panel or skip async bounce processing.\n";
    exit(0);
}

$host = $env->nullableString('BOUNCE_IMAP_HOST');
$user = $env->nullableString('BOUNCE_IMAP_USER');
$password = $env->nullableString('BOUNCE_IMAP_PASSWORD');
if ($host === null || $user === null || $password === null) {
    echo "SKIP: BOUNCE_IMAP_HOST/USER/PASSWORD are not configured.\n";
    exit(0);
}

$logDir = $env->string('LOG_DIR', $root . '/storage/logs');
$logger = LoggerFactory::create($env, rtrim($logDir, '/\\') . '/bounces.log');
$pdo = Connection::create($env);
$processor = new BounceMailboxProcessor(
    new SuppressionRepository($pdo),
    new EmailQueueRepository($pdo),
    $logger,
    $env->nullableString('SMTP_MESSAGE_ID_DOMAIN')
);

$port = $env->int('BOUNCE_IMAP_PORT', 993);
$flags = $env->string('BOUNCE_IMAP_FLAGS', '/imap/ssl');
$mailboxName = $env->string('BOUNCE_IMAP_MAILBOX', 'INBOX');
$processedFolder = $env->nullableString('BOUNCE_IMAP_PROCESSED_FOLDER');
$mailboxPath = sprintf('{%s:%d%s}%s', $host, $port, $flags, $mailboxName);

$mailbox = imap_open($mailboxPath, $user, $password);
if ($mailbox === false) {
    fwrite(STDERR, sprintf("ERROR: unable to open mailbox %s: %s\n", $mailboxPath, imap_last_error() ?: 'unknown error'));
    exit(1);
}

$unseen = imap_search($mailbox, 'UNSEEN') ?: [];
$hardBounces = 0;
foreach ($unseen as $messageNumber) {
    $raw = imap_fetchheader($mailbox, $messageNumber) . imap_body($mailbox, $messageNumber, FT_PEEK);
    try {
        if ($processor->process($raw)) {
            $hardBounces++;
        }
    } catch (\Throwable $exception) {
        $logger->warning('Unable to process bounce message', [
            'messageNumber' => $messageNumber,
            'error' => $exception->getMessage(),
        ]);
        continue;
    }
    imap_setflag_full($mailbox, (string) $messageNumber, '\\Seen');
    if ($processedFolder !== null) {
        imap_mail_move($mailbox, (string) $messageNumber, $processedFolder);
    }
}
if ($processedFolder !== null) {
    imap_expunge($mailbox);
}
imap_close($mailbox);

echo sprintf("Processed %d message(s), %d hard bounce(s) suppressed.\n", count($unseen), $hardBounces);
exit(0);
