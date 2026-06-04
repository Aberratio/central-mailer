<?php

declare(strict_types=1);

$phpunit = dirname(__DIR__) . '/vendor/bin/phpunit';

if (!is_file($phpunit) && !is_file($phpunit . '.bat')) {
    fwrite(STDERR, "PHPUnit is not installed. Run composer install without --no-dev first.\n");
    exit(1);
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit);
passthru($command, $exitCode);

exit($exitCode);
