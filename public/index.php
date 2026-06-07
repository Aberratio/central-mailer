<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Config\ProductionConfigValidator;
use CentralMailer\Client\ClientRepository;
use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Database\Connection;
use CentralMailer\Email\EmailProviderInterface;
use CentralMailer\Email\SmtpEmailProvider;
use CentralMailer\Http\ErrorMiddleware;
use CentralMailer\Http\Middleware\ApiKeyAuthMiddleware;
use CentralMailer\Http\Middleware\CorsMiddleware;
use CentralMailer\Http\Middleware\EnqueueRateLimitMiddleware;
use CentralMailer\Http\Middleware\RequestSizeLimitMiddleware;
use CentralMailer\Http\Middleware\SecurityHeadersMiddleware;
use CentralMailer\Http\Routes\EmailRoutes;
use CentralMailer\Http\Routes\HealthRoutes;
use CentralMailer\Http\Routes\OpenApiRoutes;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailQueueService;
use CentralMailer\Queue\EmailWorker;
use CentralMailer\Queue\EnqueueRateLimitRepository;
use CentralMailer\Queue\RateLimiter;
use CentralMailer\Queue\RateLimitRepository;
use CentralMailer\Validation\EmailRequestValidator;
use DI\Container;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

$root = dirname(__DIR__);
$activeRoot = $root . '/current';
if (is_file($activeRoot . '/vendor/autoload.php')) {
    $root = $activeRoot;
}

require $root . '/vendor/autoload.php';

if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$container = new Container();
$container->set(Env::class, fn () => new Env($_ENV));
$env = $container->get(Env::class);
ProductionConfigValidator::validate($env);
$container->set(PDO::class, fn ($c) => Connection::create($c->get(Env::class)));
$container->set(ClientRepository::class, fn ($c) => new ClientRepository($c->get(PDO::class)));
$container->set(LoggerInterface::class, function ($c) use ($root) {
    $env = $c->get(Env::class);
    $logDir = $env->string('LOG_DIR', $root . '/storage/logs');

    return LoggerFactory::create($env, rtrim($logDir, '/\\') . '/app.log');
});
$container->set(AttachmentStorage::class, fn () => new AttachmentStorage($root . '/storage/attachments'));
$container->set(EmailQueueRepository::class, fn ($c) => new EmailQueueRepository($c->get(PDO::class)));
$container->set(EnqueueRateLimitRepository::class, fn ($c) => new EnqueueRateLimitRepository($c->get(PDO::class)));
$container->set(RateLimitRepository::class, fn ($c) => new RateLimitRepository($c->get(PDO::class)));
$container->set(RateLimiter::class, fn ($c) => new RateLimiter($c->get(RateLimitRepository::class), $c->get(Env::class)));
$container->set(EmailRequestValidator::class, fn ($c) => new EmailRequestValidator($c->get(Env::class)));
$container->set(EmailQueueService::class, fn ($c) => new EmailQueueService(
    $c->get(EmailQueueRepository::class),
    $c->get(EmailRequestValidator::class),
    $c->get(LoggerInterface::class),
    $c->get(AttachmentStorage::class),
    $c->get(Env::class)
));
$container->set(EmailProviderInterface::class, fn ($c) => new SmtpEmailProvider($c->get(Env::class)));
$container->set(EmailWorker::class, fn ($c) => new EmailWorker(
    $c->get(EmailQueueRepository::class),
    $c->get(EmailProviderInterface::class),
    $c->get(RateLimiter::class),
    $c->get(LoggerInterface::class),
    $c->get(Env::class),
    $c->get(AttachmentStorage::class)
));

AppFactory::setContainer($container);
$app = AppFactory::create();

$container->get(ClientRepository::class)->syncLegacyClients($container->get(Env::class));
$app->addBodyParsingMiddleware();
$app->add(new RequestSizeLimitMiddleware($container->get(Env::class)));
$app->add(new EnqueueRateLimitMiddleware(
    $container->get(EnqueueRateLimitRepository::class),
    $container->get(Env::class)
));
$app->add(new ApiKeyAuthMiddleware($container->get(ClientRepository::class), $container->get(Env::class)));
$app->add(new CorsMiddleware($container->get(Env::class)));
$app->add(new SecurityHeadersMiddleware($container->get(Env::class)));
ErrorMiddleware::create($app, $container->get(Env::class), $container->get(LoggerInterface::class));

OpenApiRoutes::register($app);
HealthRoutes::register($app);
EmailRoutes::register($app);

$app->run();
