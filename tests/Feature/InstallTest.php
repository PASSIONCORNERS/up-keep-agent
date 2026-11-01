<?php

use Illuminate\Support\Facades\Schema;

it('creates its two tables', function () {
    expect(Schema::hasColumns('upkeep_errors', [
        'fingerprint', 'level', 'class', 'message', 'file', 'line', 'method', 'url',
        'frames', 'occurrences', 'first_seen_at', 'last_seen_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('upkeep_agent_state', ['key', 'value', 'updated_at']))->toBeTrue();
});

it('is off until a secret is set', function () {
    expect(config('upkeep-agent.secret'))->toBeNull()
        ->and(config('upkeep-agent.health_timeout'))->toBe(3)
        ->and(config('upkeep-agent.keep_errors_days'))->toBe(30);
});

it('clones the default connection with a short timeout', function () {
    $clone = config('database.connections.upkeep-agent');

    expect($clone['database'])->toBe(config('database.connections.'.config('database.default').'.database'))
        ->and($clone['options'][PDO::ATTR_TIMEOUT])->toBe(3);
});
