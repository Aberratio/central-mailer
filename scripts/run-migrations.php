<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Database\Connection;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['baseline', 'dry-run', 'no-lock', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/run-migrations.php [--dry-run] [--baseline] [--no-lock]\n";
    exit(0);
}

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$pdo = Connection::create(new Env($_ENV));
$migrationFiles = glob($root . '/database/migrations/*.sql') ?: [];
sort($migrationFiles, SORT_STRING);
$isDryRun = isset($options['dry-run']);
$lockName = 'central_mailer_schema_migrations';

if (!isset($options['no-lock'])) {
    $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 30)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        fwrite(STDERR, "Could not acquire migration lock: {$lockName}\n");
        exit(3);
    }

    register_shutdown_function(static function () use ($pdo, $lockName): void {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    });
}

if (!$isDryRun) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

$tableExists = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'schema_migrations'"
)->fetchColumn() > 0;

$applied = [];
if ($tableExists) {
    foreach ($pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $applied[(string) $row['migration']] = (string) $row['checksum'];
    }
}

$pending = [];
foreach ($migrationFiles as $file) {
    $name = basename($file);
    $checksum = hash_file('sha256', $file);
    if ($checksum === false) {
        throw new RuntimeException("Could not checksum migration: {$name}");
    }

    if (isset($applied[$name])) {
        if (!hash_equals($applied[$name], $checksum)) {
            fwrite(STDERR, "Migration checksum changed after applying: {$name}\n");
            exit(2);
        }
        continue;
    }

    $pending[] = ['name' => $name, 'path' => $file, 'checksum' => $checksum];
}

if ($pending === []) {
    echo "No pending migrations.\n";
    exit(0);
}

if ($isDryRun) {
    echo "Pending migrations:\n";
    foreach ($pending as $migration) {
        echo "- {$migration['name']}\n";
    }
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)'
);

foreach ($pending as $migration) {
    if (isset($options['baseline'])) {
        $insert->execute(['migration' => $migration['name'], 'checksum' => $migration['checksum']]);
        echo "Baselined migration: {$migration['name']}\n";
        continue;
    }

    echo "Running migration: {$migration['name']}\n";
    $sql = file_get_contents($migration['path']);
    if ($sql === false) {
        throw new RuntimeException("Could not read migration: {$migration['name']}");
    }

    $pdo->exec($sql);
    $insert->execute(['migration' => $migration['name'], 'checksum' => $migration['checksum']]);
    echo "Applied migration: {$migration['name']}\n";
}
