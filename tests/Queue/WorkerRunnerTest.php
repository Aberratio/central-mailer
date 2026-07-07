<?php

declare(strict_types=1);

namespace CentralMailer\Tests\Queue;

use CentralMailer\Config\Env;
use CentralMailer\Queue\WorkerRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerRunnerTest extends TestCase
{
    private string $stopFile;

    protected function setUp(): void
    {
        $this->stopFile = sys_get_temp_dir() . '/worker-runner-test-' . uniqid('', true) . '.stop';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stopFile)) {
            unlink($this->stopFile);
        }
    }

    public function testDoesNotStopWithoutStopSignal(): void
    {
        $runner = new WorkerRunner(new Env([]), new NullLogger(), $this->stopFile);

        self::assertFalse($runner->shouldStop());
    }

    public function testStopsWhenStopFileExists(): void
    {
        $runner = new WorkerRunner(new Env([]), new NullLogger(), $this->stopFile);
        touch($this->stopFile);

        self::assertTrue($runner->shouldStop());
    }

    public function testStopsAfterMaxRuntimeDeadline(): void
    {
        $runner = new WorkerRunner(
            new Env(['EMAIL_WORKER_MAX_RUNTIME_SECONDS' => '5']),
            new NullLogger(),
            $this->stopFile,
            microtime(true) - 10.0
        );

        self::assertTrue($runner->shouldStop());
    }

    public function testRunLoopExitsWhenStopIsRequestedBetweenIterations(): void
    {
        $runner = new WorkerRunner(new Env([]), new NullLogger(), $this->stopFile);
        $calls = 0;
        $stopFile = $this->stopFile;

        $runner->run(function () use (&$calls, $stopFile): int {
            $calls++;
            if ($calls >= 2) {
                touch($stopFile);
            }

            return 1;
        }, 1);

        self::assertSame(2, $calls);
    }
}
