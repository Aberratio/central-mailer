<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Config\ProductionConfigValidator;
use CentralMailer\Client\ClientRepository;
use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Database\Connection;
use CentralMailer\Email\SmtpEmailProvider;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailWorker;
use CentralMailer\Queue\RateLimiter;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
ProductionConfigValidator::validate($env);
$logDir = $env->string('LOG_DIR', $root . '/storage/logs');
$logger = LoggerFactory::create($env, rtrim($logDir, '/\\') . '/worker.log');
$pdo = Connection::create($env);
$clients = new ClientRepository($pdo);
$clients->syncLegacyClients($env);

$worker = new EmailWorker(
    new EmailQueueRepository($pdo),
    new SmtpEmailProvider($env),
    new RateLimiter(new RateLimitRepository($pdo), $env),
    $logger,
    $env,
    new AttachmentStorage($root . '/storage/attachments'),
    'standard',
    null,
    new WorkerHeartbeatRepository($pdo)
);

$logger->info('Email worker started');

while (true) {
    $processed = $worker->runOnce();
    if ($processed === 0) {
        sleep($env->int('EMAIL_WORKER_SLEEP_SECONDS', 10));
    }
}
