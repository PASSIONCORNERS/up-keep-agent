<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\Health\HealthReadings;
use Passioncorners\UpKeepAgent\Health\QueueReadings;
use Passioncorners\UpKeepAgent\Server\ServerReadings;
use Passioncorners\UpKeepAgent\Server\StackReadings;
use Passioncorners\UpKeepAgent\UpKeepAgent;

function redisAvailable(): bool
{
    try {
        Redis::connection(UpKeepAgent::REDIS_CONNECTION)->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('reads memory and disk, and CPU only while fresh', function () {
    $this->freezeTime();
    $readings = new ServerReadings(app(AgentState::class), fakeProc(), sys_get_temp_dir());

    app(AgentState::class)->put('cpu', ['percent' => 37.5, 'measured_at' => now()->subMinute()->toIso8601String()]);
    $fresh = $readings->read(now()->toImmutable());

    app(AgentState::class)->put('cpu', ['percent' => 37.5, 'measured_at' => now()->subMinutes(4)->toIso8601String()]);

    expect($fresh['cpu_percent'])->toBe(37.5)
        ->and($fresh['memory_percent'])->toBe(75.0)
        ->and($fresh['disk_percent'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(100.0)
        ->and($readings->read(now()->toImmutable())['cpu_percent'])->toBeNull();
});

it('reports nothing it cannot read', function () {
    expect((new ServerReadings(app(AgentState::class), '/nonexistent', '/nonexistent'))->read(now()->toImmutable()))
        ->toBe(['cpu_percent' => null, 'memory_percent' => null, 'disk_percent' => null]);
});

it('reads the stack', function () {
    $osRelease = tempnam(sys_get_temp_dir(), 'os-release-');
    file_put_contents($osRelease, "NAME=\"Ubuntu\"\nPRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\nID=ubuntu\n");

    expect((new StackReadings($osRelease))->read())->toBe([
        'php' => PHP_VERSION,
        'laravel' => app()->version(),
        'os' => 'Ubuntu 24.04.1 LTS',
    ])->and((new StackReadings('/nonexistent'))->read()['os'])->toBeNull();
});

it('reports a reachable database and skips Redis when unused', function () {
    $health = app(HealthReadings::class)->read(now()->toImmutable());

    expect($health['database'])->toBe(['ok' => true, 'message' => null])
        ->and($health['redis'])->toBeNull();
});

it('checks Redis when the cache uses it', function () {
    config(['cache.default' => 'redis']);

    expect(app(HealthReadings::class)->read(now()->toImmutable())['redis'])->toHaveKeys(['ok', 'message']);
});

it('reports an unreachable database with a short reason', function () {
    config(['database.connections.upkeep-agent' => ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']]);
    DB::purge(UpKeepAgent::CONNECTION);

    $health = app(HealthReadings::class)->read(now()->toImmutable());

    expect($health['database']['ok'])->toBeFalse()
        ->and($health['database']['message'])->toBeString()->not->toBeEmpty()
        ->and(mb_strlen((string) $health['database']['message']))->toBeLessThanOrEqual(200)
        ->and($health['scheduler'])->toBeNull();
});

it('lists no queues for the sync driver', function () {
    config(['queue.default' => 'sync']);

    expect(app(QueueReadings::class)->queues())->toBe([]);
});

it('lists Horizon queues for this environment', function () {
    config([
        'horizon.defaults' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1]],
        'horizon.environments.testing' => ['supervisor-1' => ['queue' => ['default', 'mail']]],
    ]);

    expect(app(QueueReadings::class)->queues())->toBe([
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);
});

it('measures how long a database-queue job has been ready, ignoring delayed and reserved ones', function () {
    $this->freezeTime();
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config(['queue.default' => 'database', 'queue.connections.database' => [
        'driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
    ]]);

    $job = fn (array $attributes) => DB::table('jobs')->insert(array_merge([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->getTimestamp(), 'created_at' => now()->subDay()->getTimestamp(),
    ], $attributes));

    $job(['available_at' => now()->subMinutes(15)->getTimestamp()]);
    $job(['available_at' => now()->addHour()->getTimestamp()]);
    $job(['available_at' => now()->subMinutes(30)->getTimestamp(), 'reserved_at' => now()->getTimestamp()]);

    expect(app(HealthReadings::class)->read(now()->toImmutable())['queues'])->toBe([[
        'connection' => 'database', 'queue' => 'default', 'size' => 3, 'oldest_ready_seconds' => 900,
    ]]);
});

it('times the job at the front of a Redis queue', function () {
    config(['queue.default' => 'redis']);
    $queues = app(QueueReadings::class);
    $start = now()->toImmutable();
    $waited = fn (CarbonImmutable $at) => $queues->read($at)[0]['oldest_ready_seconds'];

    foreach (range(0, 11) as $minute) {
        $queues->observe('redis', 'default', 'job-a', $start->addMinutes($minute));
    }
    expect($waited($start->addMinutes(11)))->toBe(660);

    $queues->observe('redis', 'default', 'job-b', $start->addMinutes(12));
    expect($waited($start->addMinutes(12)))->toBe(0);

    $queues->observe('redis', 'default', 'job-b', $start->addMinutes(20));
    expect($waited($start->addMinutes(20)))->toBe(0)
        ->and($waited($start->addMinutes(25)))->toBeNull();

    $queues->observe('redis', 'default', null, $start->addMinutes(26));
    expect($waited($start->addMinutes(26)))->toBe(0);
});

it('reports the scheduler check-in', function () {
    app(AgentState::class)->put('scheduler', ['at' => '2026-11-01T12:00:00+00:00']);

    expect(app(HealthReadings::class)->read(now()->toImmutable())['scheduler'])
        ->toBe(['last_run_at' => '2026-11-01T12:00:00+00:00']);
});

it('reports a scheduler that never ran', function () {
    expect(app(HealthReadings::class)->read(now()->toImmutable())['scheduler'])->toBe(['last_run_at' => null]);
});

it('reads the real front of a Redis queue', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'upkeep-agent-test']);
    $redis = Queue::connection('redis');
    $redis->clear('upkeep-agent-test');
    $redis->pushRaw(json_encode(['uuid' => 'front-uuid', 'displayName' => 'Test', 'job' => 'Test', 'data' => []]), 'upkeep-agent-test');

    app(QueueReadings::class)->observeFronts(now()->toImmutable());
    $redis->clear('upkeep-agent-test');

    expect(app(AgentState::class)->get('queue-front:redis:upkeep-agent-test')['uuid'])->toBe('front-uuid');
})->skip(fn () => ! redisAvailable(), 'Redis is not available');
