<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Client\ClientRepository;
use CentralMailer\Attachment\AttachmentStorage;
use CentralMailer\Database\Connection;
use CentralMailer\Email\EmailProviderInterface;
use CentralMailer\Email\SmtpEmailProvider;
use CentralMailer\Http\ErrorMiddleware;
use CentralMailer\Http\Middleware\ApiKeyAuthMiddleware;
use CentralMailer\Http\Middleware\CorsMiddleware;
use CentralMailer\Http\Routes\EmailRoutes;
use CentralMailer\Http\Routes\OpenApiRoutes;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\EmailQueueService;
use CentralMailer\Validation\EmailRequestValidator;
use DI\Container;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$container = new Container();
$container->set(Env::class, fn () => new Env($_ENV));
$container->set(PDO::class, fn ($c) => Connection::create($c->get(Env::class)));
$container->set(ClientRepository::class, fn ($c) => new ClientRepository($c->get(PDO::class)));
$container->set(LoggerInterface::class, fn ($c) => LoggerFactory::create($c->get(Env::class), $root . '/storage/logs/app.log'));
$container->set(AttachmentStorage::class, fn () => new AttachmentStorage($root . '/storage/attachments'));
$container->set(EmailQueueRepository::class, fn ($c) => new EmailQueueRepository($c->get(PDO::class)));
$container->set(EmailRequestValidator::class, fn ($c) => new EmailRequestValidator($c->get(Env::class)));
$container->set(EmailQueueService::class, fn ($c) => new EmailQueueService(
    $c->get(EmailQueueRepository::class),
    $c->get(EmailRequestValidator::class),
    $c->get(LoggerInterface::class),
    $c->get(AttachmentStorage::class)
));
$container->set(EmailProviderInterface::class, fn ($c) => new SmtpEmailProvider($c->get(Env::class)));

AppFactory::setContainer($container);
$app = AppFactory::create();

$container->get(ClientRepository::class)->syncLegacyClients($container->get(Env::class));
$app->addBodyParsingMiddleware();
$app->add(new ApiKeyAuthMiddleware($container->get(ClientRepository::class)));
$app->add(new CorsMiddleware($container->get(Env::class)));
ErrorMiddleware::create($app, $container->get(Env::class), $container->get(LoggerInterface::class));

OpenApiRoutes::register($app);
EmailRoutes::register($app);

$app->run();
