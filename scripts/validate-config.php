<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Config\ProductionConfigValidator;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

ProductionConfigValidator::validate(new Env($_ENV));
echo "Configuration is valid.\n";
