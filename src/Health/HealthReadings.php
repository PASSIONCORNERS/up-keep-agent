<?php

namespace Passioncorners\UpKeepAgent\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Passioncorners\UpKeepAgent\AgentDatabase;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\UpKeepAgent;
use Throwable;

/**
 * Whether the services the app depends on answer. Each check uses the agent's own
 * connections, which give up after a few seconds, so a hung service makes the page say
 * "unreachable" instead of timing out.
 *
 * Readings that depend on a failed service are null (unknown), never a made-up value:
 * with the database down, the scheduler's check-in can't be read, which is not the same
 * as the scheduler having stopped.
 *
 * @phpstan-import-type QueueReading from QueueReadings
 *
 * @phpstan-type Check array{ok: bool, message: string|null}
 */
class HealthReadings
{
    public function __construct(private AgentDatabase $database, private AgentState $state, private QueueReadings $queues) {}

    /**
     * @return array{database: Check, redis: Check|null, queues: list<QueueReading>|null, scheduler: array{last_run_at: string|null}|null}
     */
    public function read(CarbonImmutable $now): array
    {
        $failure = $this->database->probe();
        $database = $failure === null
            ? $this->check(fn () => $this->database->connection()->select('select 1'))
            : $this->failed($failure);
        $redis = $this->usesRedis()
            ? $this->check(fn () => Redis::connection(UpKeepAgent::REDIS_CONNECTION)->command('ping'))
            : null;

        return [
            'database' => $database,
            'redis' => $redis,
            'queues' => $this->queueReadings($now, $database['ok'], $redis === null || $redis['ok']),
            'scheduler' => $database['ok'] ? $this->scheduler() : null,
        ];
    }

    public function usesRedis(): bool
    {
        $queue = config('queue.default');
        $cache = config('cache.default');

        return config("queue.connections.{$queue}.driver") === 'redis'
            || config("cache.stores.{$cache}.driver") === 'redis'
            || config('session.driver') === 'redis'
            || config('horizon') !== null;
    }

    /**
     * @return list<QueueReading>|null
     */
    private function queueReadings(CarbonImmutable $now, bool $databaseOk, bool $redisOk): ?array
    {
        $needsRedis = collect($this->queues->queues())
            ->contains(fn (array $name): bool => $this->queues->driver($name['connection']) === 'redis');

        if (! $databaseOk || ($needsRedis && ! $redisOk)) {
            return null;
        }

        try {
            return $this->queues->read($now);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{last_run_at: string|null}|null
     */
    private function scheduler(): ?array
    {
        try {
            $checkIn = $this->state->get('scheduler');
        } catch (Throwable) {
            return null;
        }

        return ['last_run_at' => is_string($checkIn['at'] ?? null) ? $checkIn['at'] : null];
    }

    /**
     * @return Check
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true, 'message' => null];
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * @return Check
     */
    private function failed(Throwable $exception): array
    {
        return ['ok' => false, 'message' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 199, '…')];
    }
}
