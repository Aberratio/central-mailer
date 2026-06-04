<?php

declare(strict_types=1);

namespace CentralMailer\Database;

use CentralMailer\Config\Env;
use PDO;

final class Connection
{
    public static function create(Env $env): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $env->string('DB_HOST', '127.0.0.1'),
            $env->int('DB_PORT', 3306),
            $env->string('DB_DATABASE')
        );

        return new PDO($dsn, $env->string('DB_USERNAME'), $env->string('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
