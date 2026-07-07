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
use CentralMailer\Queue\WorkerRunner;
use CentralMailer\Suppression\SuppressionRepository;
use CentralMailer\Support\AlertNotifier;
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

$runner = new WorkerRunner($env, $logger, $root . '/storage/worker.stop');
$repository = new EmailQueueRepository($pdo);

$worker = new EmailWorker(
    $repository,
    new SmtpEmailProvider($env),
    new RateLimiter(new RateLimitRepository($pdo), $env),
    $logger,
    $env,
    new AttachmentStorage($root . '/storage/attachments'),
    'standard',
    null,
    new WorkerHeartbeatRepository($pdo),
    null,
    fn (): bool => $runner->shouldStop(),
    new AlertNotifier($env, $logger, $repository, $pdo, $root . '/storage/monitor-state.json'),
    new SuppressionRepository($pdo)
);

$logger->info('Email worker started');

$runner->run(
    fn (): int => $worker->runOnce(),
    $env->int('EMAIL_WORKER_SLEEP_SECONDS', 10)
);
