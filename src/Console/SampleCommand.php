<?php

namespace Passioncorners\UpKeepAgent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Passioncorners\UpKeepAgent\AgentDatabase;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\Health\QueueReadings;
use Passioncorners\UpKeepAgent\Server\CpuSampler;
use Throwable;

/**
 * Runs every minute from the app's own scheduler. Its check-in is how up-keep knows the
 * scheduler is alive, so each step runs on its own: one failing must not hide the others.
 */
class SampleCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'upkeep-agent:sample';

    /**
     * @var string
     */
    protected $description = 'Record the scheduler check-in, sample CPU and queues, and delete old errors for up-keep';

    public function handle(AgentDatabase $database, AgentState $state, CpuSampler $cpu, QueueReadings $queues): int
    {
        $now = CarbonImmutable::now();

        $this->attempt('scheduler check-in', fn () => $state->put('scheduler', ['at' => $now->toIso8601String()]));
        $this->attempt('CPU sample', fn () => $cpu->sample($now));
        $this->attempt('queue sample', fn () => $queues->observeFronts($now));
        $this->attempt('old error clean-up', fn () => $database->connection()
            ->table('upkeep_errors')
            ->where('last_seen_at', '<', $now->subDays((int) config('upkeep-agent.keep_errors_days')))
            ->delete());

        return self::SUCCESS;
    }

    private function attempt(string $step, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->components->warn("up-keep-agent skipped the {$step}: {$exception->getMessage()}");
        }
    }
}
