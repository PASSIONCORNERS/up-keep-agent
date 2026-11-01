<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\Server\CpuSampler;

it('runs every minute from the app scheduler', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'upkeep-agent:sample'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('checks in for the scheduler', function () {
    $this->freezeTime();

    $this->artisan('upkeep-agent:sample')->assertSuccessful();

    expect(app(AgentState::class)->get('scheduler'))->toBe(['at' => now()->toIso8601String()]);
});

it('tries a database that is down only once per run', function () {
    $attempts = failAgentConnections();

    $this->artisan('upkeep-agent:sample')->assertSuccessful();

    expect($attempts->count)->toBe(1);
});

it('measures CPU between two samples', function () {
    $proc = fakeProc(stat: "cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 100 0 100 700 100 0 0 0 0 0\n");
    $sampler = new CpuSampler(app(AgentState::class), $proc);

    $sampler->sample(now()->toImmutable());
    expect(app(AgentState::class)->get('cpu')['percent'])->toBeNull();

    // 150 more jiffies in total, 75 of them busy.
    file_put_contents("{$proc}/stat", "cpu  150 0 125 750 125 0 0 0 0 0\n");
    $sampler->sample(now()->addMinute()->toImmutable());

    expect(app(AgentState::class)->get('cpu')['percent'])->toBe(50.0);
});

it('starts over after a long gap', function () {
    $proc = fakeProc(stat: "cpu  100 0 100 700 100 0 0 0 0 0\n");
    $sampler = new CpuSampler(app(AgentState::class), $proc);

    $sampler->sample(now()->toImmutable());
    file_put_contents("{$proc}/stat", "cpu  150 0 125 750 125 0 0 0 0 0\n");
    $sampler->sample(now()->addMinutes(5)->toImmutable());

    expect(app(AgentState::class)->get('cpu')['percent'])->toBeNull();
});

it('does nothing without /proc', function () {
    (new CpuSampler(app(AgentState::class), '/nonexistent'))->sample(now()->toImmutable());

    expect(app(AgentState::class)->get('cpu'))->toBeNull();
});

it('deletes errors not seen for 30 days', function () {
    insertError(['fingerprint' => 'old', 'last_seen_at' => now()->subDays(31)]);
    insertError(['fingerprint' => 'recent', 'last_seen_at' => now()->subDays(29)]);

    $this->artisan('upkeep-agent:sample')->assertSuccessful();

    expect(DB::table('upkeep_errors')->pluck('fingerprint')->all())->toBe(['recent']);
});

it('keeps going when one step fails', function () {
    insertError(['fingerprint' => 'old', 'last_seen_at' => now()->subDays(31)]);
    Schema::drop('upkeep_agent_state');

    $this->artisan('upkeep-agent:sample')->assertSuccessful();

    expect(DB::table('upkeep_errors')->count())->toBe(0);
});
