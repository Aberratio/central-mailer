<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Database\Connection;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['baseline', 'baseline-through:', 'adopt-existing', 'dry-run', 'no-lock', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/run-migrations.php [--dry-run] [--baseline] [--baseline-through=FILE] [--adopt-existing] [--no-lock]\n";
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
$baselineThrough = isset($options['baseline-through']) ? (string) $options['baseline-through'] : null;
$adoptExisting = isset($options['adopt-existing']);
$lockName = 'central_mailer_schema_migrations';

if ($baselineThrough !== null && !isset($options['baseline'])) {
    fwrite(STDERR, "--baseline-through requires --baseline.\n");
    exit(4);
}
if ($adoptExisting && isset($options['baseline'])) {
    fwrite(STDERR, "--adopt-existing cannot be combined with --baseline.\n");
    exit(4);
}
if ($baselineThrough !== null && !in_array($baselineThrough, array_map('basename', $migrationFiles), true)) {
    fwrite(STDERR, "Unknown baseline migration: {$baselineThrough}\n");
    exit(4);
}

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
        $suffix = $adoptExisting && migrationLooksApplied($pdo, $migration['name']) ? ' (already present in schema)' : '';
        echo "- {$migration['name']}{$suffix}\n";
    }
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)'
);

foreach ($pending as $migration) {
    if ($adoptExisting && migrationLooksApplied($pdo, $migration['name'])) {
        $insert->execute(['migration' => $migration['name'], 'checksum' => $migration['checksum']]);
        echo "Adopted existing migration: {$migration['name']}\n";
        continue;
    }

    if (isset($options['baseline'])) {
        if ($baselineThrough !== null && strcmp($migration['name'], $baselineThrough) > 0) {
            echo "Left pending after baseline: {$migration['name']}\n";
            continue;
        }

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

function migrationLooksApplied(PDO $pdo, string $migration): bool
{
    return match ($migration) {
        '001_create_email_queue.sql' => tableExists($pdo, 'email_queue'),
        '002_add_delivery_safety.sql' => columnsExist($pdo, 'email_queue', [
            'idempotency_key',
            'request_hash',
            'lease_id',
            'lease_expires_at',
        ]) && tableExists($pdo, 'email_rate_limit_lock')
            && tableExists($pdo, 'email_rate_limit_reservations'),
        '003_add_clients_batches_attachments_events.sql' => tableExists($pdo, 'email_clients')
            && tableExists($pdo, 'email_messages')
            && tableExists($pdo, 'email_batches')
            && tableExists($pdo, 'email_attachments')
            && tableExists($pdo, 'email_events')
            && columnsExist($pdo, 'email_queue', ['message_id', 'batch_id'])
            && columnExists($pdo, 'email_rate_limit_reservations', 'source_app'),
        '004_add_technical_priority.sql' => columnTypeContains($pdo, 'email_queue', 'priority', 'technical'),
        '005_add_enqueue_rate_limit.sql' => tableExists($pdo, 'email_enqueue_rate_limit_lock')
            && tableExists($pdo, 'email_enqueue_rate_limit_reservations'),
        '006_add_inline_attachment_content_id.sql' => columnExists($pdo, 'email_attachments', 'content_id'),
        '007_add_worker_heartbeats_observability.sql' => tableExists($pdo, 'email_worker_heartbeats')
            && indexExists($pdo, 'email_queue', 'idx_email_queue_claim_pending_due'),
        '008_add_unknown_status.sql' => columnTypeContains($pdo, 'email_queue', 'status', 'unknown'),
        '009_add_category_and_suppressions.sql' => columnExists($pdo, 'email_queue', 'category')
            && tableExists($pdo, 'email_suppressions'),
        default => false,
    };
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

/** @param list<string> $columns */
function columnsExist(PDO $pdo, string $table, array $columns): bool
{
    foreach ($columns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            return false;
        }
    }

    return true;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function columnTypeContains(PDO $pdo, string $table, string $column, string $needle): bool
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    $columnType = $stmt->fetchColumn();

    return is_string($columnType) && str_contains($columnType, $needle);
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $stmt->execute(['table_name' => $table, 'index_name' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}
