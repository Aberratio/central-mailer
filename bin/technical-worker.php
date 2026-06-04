<?php

declare(strict_types=1);

use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Client\ClientRepository;
use CentralMailer\Config\Env;
use CentralMailer\Config\ProductionConfigValidator;
use CentralMailer\Database\Connection;
use CentralMailer\Email\GmailSmtpEmailProvider;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailWorker;
use CentralMailer\Queue\RateLimiter;
use CentralMailer\Queue\RateLimitRepository;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
ProductionConfigValidator::validate($env);
$logDir = $env->string('LOG_DIR', $root . '/storage/logs');
$logger = LoggerFactory::create($env, rtrim($logDir, '/\\') . '/technical-worker.log');
$pdo = Connection::create($env);
$clients = new ClientRepository($pdo);
$clients->syncLegacyClients($env);

$worker = new EmailWorker(
    new EmailQueueRepository($pdo),
    new GmailSmtpEmailProvider($env),
    new RateLimiter(new RateLimitRepository($pdo), $env),
    $logger,
    $env,
    new AttachmentStorage($root . '/storage/attachments'),
    'technical'
);

$logger->info('Technical email worker started');

while (true) {
    $worker->runOnce();
    sleep($env->int('TECHNICAL_EMAIL_WORKER_SLEEP_SECONDS', 10));
}
