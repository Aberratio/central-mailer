<?php

declare(strict_types=1);

namespace CentralMailer\Logging;

use CentralMailer\Config\Env;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    public static function create(Env $env, string $path): Logger
    {
        $level = Level::fromName(strtoupper($env->string('LOG_LEVEL', 'info')));
        $logger = new Logger('central-mailer');
        $logger->pushHandler(new StreamHandler($path, $level));

        return $logger;
    }
}
