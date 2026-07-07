<?php

declare(strict_types=1);

namespace CentralMailer\Queue;

use CentralMailer\Config\Env;
use Psr\Log\LoggerInterface;

final class WorkerRunner
{
    private bool $stopRequested = false;
    private ?string $stopReason = null;
    private readonly string $stopFilePath;
    private readonly int $maxRuntimeSeconds;
    private readonly float $startedAt;

    public function __construct(
        private readonly Env $env,
        private readonly LoggerInterface $logger,
        string $defaultStopFilePath,
        ?float $startedAt = null
    ) {
        $this->stopFilePath = $this->env->string('WORKER_STOP_FILE', $defaultStopFilePath);
        $this->maxRuntimeSeconds = $this->env->int('EMAIL_WORKER_MAX_RUNTIME_SECONDS', 0);
        $this->startedAt = $startedAt ?? microtime(true);
        $this->registerSignalHandlers();
    }

    /** @param callable(): int $runOnce */
    public function run(callable $runOnce, int $idleSleepSeconds): void
    {
        while (!$this->shouldStop()) {
            $processed = $runOnce();
            if ($processed === 0) {
                $this->idle($idleSleepSeconds);
            }
        }

        $this->logger->info('Worker stopping', ['reason' => $this->stopReason]);
    }

    public function shouldStop(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        if ($this->stopRequested) {
            return true;
        }
        if ($this->stopFilePath !== '' && is_file($this->stopFilePath)) {
            return $this->requestStop('stop-file');
        }
        if ($this->maxRuntimeSeconds > 0 && (microtime(true) - $this->startedAt) >= $this->maxRuntimeSeconds) {
            return $this->requestStop('deadline');
        }

        return false;
    }

    private function idle(int $seconds): void
    {
        $deadline = microtime(true) + max(1, $seconds);
        while (!$this->shouldStop() && microtime(true) < $deadline) {
            sleep(1);
        }
    }

    private function requestStop(string $reason): bool
    {
        $this->stopRequested = true;
        $this->stopReason ??= $reason;

        return true;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->requestStop('signal');
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
