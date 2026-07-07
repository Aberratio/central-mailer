<?php

declare(strict_types=1);

use CentralMailer\Config\Env;
use CentralMailer\Database\Connection;
use CentralMailer\Logging\LoggerFactory;
use CentralMailer\Queue\EmailQueueRepository;
use CentralMailer\Queue\WorkerHeartbeatRepository;
use CentralMailer\Support\AlertNotifier;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
$logDir = $env->string('LOG_DIR', $root . '/storage/logs');
$logger = LoggerFactory::create($env, rtrim($logDir, '/\\') . '/monitor.log');
$pdo = Connection::create($env);
$notifier = new AlertNotifier(
    $env,
    $logger,
    new EmailQueueRepository($pdo),
    $pdo,
    $root . '/storage/monitor-state.json'
);

$issues = [];
$now = new DateTimeImmutable();

// 1. Worker heartbeats (same freshness window as GET /health).
$freshSeconds = max(
    10,
    $env->int('EMAIL_WORKER_HEARTBEAT_STALE_SECONDS', 60),
    $env->int('EMAIL_WORKER_SLEEP_SECONDS', 10) * 3,
    $env->int('TECHNICAL_EMAIL_WORKER_SLEEP_SECONDS', 10) * 3
);
$freshSince = $now->modify(sprintf('-%d seconds', $freshSeconds))->format('Y-m-d H:i:s');
$workerCounts = (new WorkerHeartbeatRepository($pdo))->activeCountsSince($freshSince);
$missing = array_keys(array_filter($workerCounts, static fn (int $count): bool => $count === 0));
if ($missing !== []) {
    $issues[] = ['type' => 'workers_missing', 'message' => sprintf(
        'No fresh worker heartbeat for queue(s): %s (window %ds)',
        implode(', ', $missing),
        $freshSeconds
    ), 'context' => $workerCounts];
}

// 2. Newly failed emails since the last expected monitor run.
$failedLookbackMinutes = max(1, $env->int('MONITOR_FAILED_LOOKBACK_MINUTES', 15));
$failedSince = $now->modify(sprintf('-%d minutes', $failedLookbackMinutes))->format('Y-m-d H:i:s');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND updated_at >= :since");
$stmt->execute(['since' => $failedSince]);
$failedCount = (int) $stmt->fetchColumn();
if ($failedCount > 0) {
    $issues[] = ['type' => 'failed_emails', 'message' => sprintf(
        '%d email(s) reached terminal failed status in the last %d minute(s)',
        $failedCount,
        $failedLookbackMinutes
    ), 'context' => ['failedCount' => $failedCount, 'since' => $failedSince]];
}

// 3. Oldest due-but-unsent email (catches dead workers, hung sends and a blocked technical queue).
$latencyAlertSeconds = max(60, $env->int('QUEUE_LATENCY_ALERT_SECONDS', 900));
$dueBefore = $now->modify(sprintf('-%d seconds', $latencyAlertSeconds))->format('Y-m-d H:i:s');
$stmt = $pdo->prepare(
    "SELECT id, source_app, priority, COALESCE(next_attempt_at, created_at) AS due_at
     FROM email_queue
     WHERE status IN ('pending', 'retry') AND COALESCE(next_attempt_at, created_at) <= :due_before
     ORDER BY due_at ASC
     LIMIT 1"
);
$stmt->execute(['due_before' => $dueBefore]);
$stale = $stmt->fetch();
if ($stale !== false) {
    $issues[] = ['type' => 'queue_latency', 'message' => sprintf(
        'Email %s (%s, priority %s) has been due since %s and is still unsent',
        $stale['id'],
        $stale['source_app'],
        $stale['priority'],
        $stale['due_at']
    ), 'context' => $stale];
}

// 4. Quarantined emails needing operator review.
$unknownCount = (int) $pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'unknown'")->fetchColumn();
if ($unknownCount > 0) {
    $issues[] = ['type' => 'unknown_emails', 'message' => sprintf(
        '%d email(s) are quarantined with unknown delivery outcome and need review',
        $unknownCount
    ), 'context' => ['unknownCount' => $unknownCount]];
}

if ($issues === []) {
    echo "OK: no queue issues detected.\n";
    exit(0);
}

foreach ($issues as $issue) {
    echo sprintf("ISSUE [%s]: %s\n", $issue['type'], $issue['message']);
    $notifier->notify($issue['type'], $issue['message'], $issue['context']);
}
exit(1);
