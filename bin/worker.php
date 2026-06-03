<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Database\Connection;
use CentralMailer\Email\SmtpEmailProvider;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailWorker;
use CentralMailer\Queue\RateLimiter;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
$logger = LoggerFactory::create($env, $root . '/storage/logs/worker.log');
$pdo = Connection::create($env);

$worker = new EmailWorker(
    new EmailQueueRepository($pdo),
    new SmtpEmailProvider($env),
    new RateLimiter(new EmailQueueRepository($pdo), $env),
    $logger,
    $env
);

$logger->info('Email worker started');

while (true) {
    $worker->runOnce();
    sleep($env->int('EMAIL_WORKER_SLEEP_SECONDS', 10));
}
