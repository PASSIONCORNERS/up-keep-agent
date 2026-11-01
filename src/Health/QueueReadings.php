<?php

namespace Passioncorners\UpKeepAgent\Health;

use Carbon\CarbonImmutable;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\UpKeepAgent;
use Throwable;

/**
 * How long the oldest job that is ready to run has been waiting, per queue the app's
 * workers listen to. A stuck queue is one where that keeps growing.
 *
 * "Ready", not "created": delayed and retried jobs keep their original creation time,
 * which would make a job delayed by an hour look stuck the moment it became due.
 * - Database queues record when each job becomes available, so the wait is exact.
 * - Redis queues don't, so the every-minute sample notes which job is at the front of
 *   each queue; the wait is how long that same job has stayed there.
 *
 * @phpstan-type QueueName array{connection: string, queue: string}
 * @phpstan-type QueueReading array{connection: string, queue: string, size: int|null, oldest_ready_seconds: int|null}
 */
class QueueReadings
{
    public function __construct(private AgentState $state) {}

    /**
     * Horizon's supervisors for this environment when Horizon is configured, otherwise
     * the default connection's queue. Synchronous queues have nothing to wait in.
     *
     * @return list<QueueName>
     */
    public function queues(): array
    {
        $horizon = config('horizon');

        if (is_array($horizon) && (isset($horizon['defaults']) || isset($horizon['environments']))) {
            return $this->horizonQueues($horizon);
        }

        $connection = (string) config('queue.default');

        if (in_array($this->driver($connection), [null, 'sync', 'null'], true)) {
            return [];
        }

        $queue = config("queue.connections.{$connection}.queue");

        return [['connection' => $connection, 'queue' => is_string($queue) ? $queue : 'default']];
    }

    /**
     * Called by the every-minute sample.
     */
    public function observeFronts(CarbonImmutable $now): void
    {
        foreach ($this->queues() as ['connection' => $connection, 'queue' => $queue]) {
            if ($this->driver($connection) === 'redis') {
                $this->observe($connection, $queue, $this->frontUuid($connection, $queue), $now);
            }
        }
    }

    /**
     * Remember which job is at the front, and since when. The clock restarts when the
     * front job changes or when the last look is too old to vouch for the time between.
     */
    public function observe(string $connection, string $queue, ?string $frontUuid, CarbonImmutable $now): void
    {
        $key = $this->frontKey($connection, $queue);
        $previous = $this->state->get($key);

        $keepsSince = $frontUuid !== null
            && ($previous['uuid'] ?? null) === $frontUuid
            && is_string($previous['since'] ?? null)
            && $this->isFresh($previous['observed_at'] ?? null, $now);

        $this->state->put($key, [
            'uuid' => $frontUuid,
            'since' => $keepsSince ? $previous['since'] : $now->toIso8601String(),
            'observed_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * @return list<QueueReading>
     */
    public function read(CarbonImmutable $now): array
    {
        return array_map(fn (array $name): array => [
            ...$name,
            'size' => $this->size($name['connection'], $name['queue']),
            'oldest_ready_seconds' => match ($this->driver($name['connection'])) {
                'database' => $this->databaseWait($name['connection'], $name['queue'], $now),
                'redis' => $this->redisWait($name['connection'], $name['queue'], $now),
                default => null,
            },
        ], $this->queues());
    }

    public function driver(string $connection): ?string
    {
        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * Horizon starts the supervisors listed for the current environment, each with the
     * defaults' options shallow-merged under its own (so its "queue" list replaces the
     * default list rather than merging with it).
     *
     * @param  array<mixed>  $horizon
     * @return list<QueueName>
     */
    private function horizonQueues(array $horizon): array
    {
        $defaults = is_array($horizon['defaults'] ?? null) ? $horizon['defaults'] : [];
        $environments = is_array($horizon['environments'] ?? null) ? $horizon['environments'] : [];
        $supervisors = is_array($environments[app()->environment()] ?? null) ? $environments[app()->environment()] : $defaults;

        $queues = [];

        foreach ($supervisors as $name => $options) {
            $merged = array_merge(
                is_array($defaults[$name] ?? null) ? $defaults[$name] : [],
                is_array($options) ? $options : [],
            );
            $connection = is_string($merged['connection'] ?? null) ? $merged['connection'] : 'redis';

            foreach ((array) ($merged['queue'] ?? ['default']) as $queue) {
                if (is_string($queue)) {
                    $queues["{$connection}|{$queue}"] = ['connection' => $connection, 'queue' => $queue];
                }
            }
        }

        return array_values($queues);
    }

    private function size(string $connection, string $queue): ?int
    {
        try {
            return Queue::connection($connection)->size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseWait(string $connection, string $queue, CarbonImmutable $now): int
    {
        $connectionName = config("queue.connections.{$connection}.connection");
        $table = config("queue.connections.{$connection}.table");

        $oldest = DB::connection(is_string($connectionName) ? $connectionName : null)
            ->table(is_string($table) ? $table : 'jobs')
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now->getTimestamp())
            ->min('available_at');

        return is_numeric($oldest) ? max(0, $now->getTimestamp() - (int) $oldest) : 0;
    }

    private function redisWait(string $connection, string $queue, CarbonImmutable $now): ?int
    {
        $front = $this->state->get($this->frontKey($connection, $queue));

        if ($front === null || ! $this->isFresh($front['observed_at'] ?? null, $now)) {
            return null;
        }

        if (($front['uuid'] ?? null) === null || ! is_string($front['since'] ?? null)) {
            return 0;
        }

        return max(0, $now->getTimestamp() - CarbonImmutable::parse($front['since'])->getTimestamp());
    }

    private function frontUuid(string $connection, string $queue): ?string
    {
        $redisQueue = Queue::connection($connection);

        if (! $redisQueue instanceof RedisQueue) {
            return null;
        }

        $payload = $redisQueue->getConnection()->command('lindex', [$redisQueue->getQueue($queue), 0]);
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) && is_string($decoded['uuid'] ?? null) ? $decoded['uuid'] : null;
    }

    private function isFresh(mixed $observedAt, CarbonImmutable $now): bool
    {
        return is_string($observedAt)
            && $now->getTimestamp() - CarbonImmutable::parse($observedAt)->getTimestamp() <= UpKeepAgent::STALE_SECONDS;
    }

    private function frontKey(string $connection, string $queue): string
    {
        return "queue-front:{$connection}:{$queue}";
    }
}
