<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Database\Connection;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
$pdo = Connection::create($env);
$days = max(1, $env->int('EMAIL_DATA_RETENTION_DAYS', 90));
$cutoff = (new DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:s');
$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM email_queue q
         WHERE q.status IN ("sent", "failed") AND q.updated_at < :cutoff
           AND NOT EXISTS (
             SELECT 1 FROM email_attachments a WHERE a.email_id = q.id AND a.deleted_at IS NULL
           )'
    );
    $stmt->execute(['cutoff' => $cutoff]);
    echo sprintf("Terminal emails eligible for deletion: %d\n", (int) $stmt->fetchColumn());
    exit(0);
}

$pdo->beginTransaction();
try {
    $deleteEvents = $pdo->prepare(
        'DELETE e FROM email_events e
         INNER JOIN email_queue q ON q.id = e.email_id
         WHERE q.status IN ("sent", "failed") AND q.updated_at < :cutoff
           AND NOT EXISTS (
             SELECT 1 FROM email_attachments a WHERE a.email_id = q.id AND a.deleted_at IS NULL
           )'
    );
    $deleteEvents->execute(['cutoff' => $cutoff]);

    $deleteAttachments = $pdo->prepare(
        'DELETE a FROM email_attachments a
         INNER JOIN email_queue q ON q.id = a.email_id
         WHERE q.status IN ("sent", "failed") AND q.updated_at < :cutoff
           AND a.deleted_at IS NOT NULL'
    );
    $deleteAttachments->execute(['cutoff' => $cutoff]);

    $deleteQueue = $pdo->prepare(
        'DELETE FROM email_queue
         WHERE status IN ("sent", "failed") AND updated_at < :cutoff
           AND NOT EXISTS (
             SELECT 1 FROM email_attachments a WHERE a.email_id = email_queue.id AND a.deleted_at IS NULL
           )'
    );
    $deleteQueue->execute(['cutoff' => $cutoff]);

    $pdo->exec('DELETE b FROM email_batches b LEFT JOIN email_queue q ON q.batch_id = b.id WHERE q.id IS NULL');
    $pdo->exec('DELETE m FROM email_messages m LEFT JOIN email_queue q ON q.message_id = m.id WHERE q.id IS NULL');
    $pdo->commit();

    echo sprintf("Deleted %d terminal emails older than %d days.\n", $deleteQueue->rowCount(), $days);
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}
