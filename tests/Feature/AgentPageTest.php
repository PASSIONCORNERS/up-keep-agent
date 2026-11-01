<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Passioncorners\UpKeepAgent\UpKeepAgent;

beforeEach(fn () => config(['upkeep-agent.secret' => 'right-secret']));

afterEach(fn () => date_default_timezone_set('UTC'));

/**
 * @param  array<string, string>  $query
 */
function readAgent(array $query = []): TestResponse
{
    return test()->withToken('right-secret')->getJson('/upkeep-agent?'.http_build_query($query));
}

it('is hidden until a secret is set', function () {
    config(['upkeep-agent.secret' => null]);

    $this->withToken('anything')->getJson('/upkeep-agent')->assertNotFound();
});

it('refuses a wrong or missing secret', function () {
    $this->getJson('/upkeep-agent')->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
    $this->withToken('wrong-secret')->getJson('/upkeep-agent')->assertUnauthorized();
});

it('serves contract 1 without a session cookie', function () {
    readAgent()
        ->assertOk()
        ->assertJsonPath('contract', 1)
        ->assertJsonPath('agent_version', UpKeepAgent::VERSION)
        ->assertJsonPath('health.database', ['ok' => true, 'message' => null])
        ->assertJsonStructure([
            'generated_at',
            'stack' => ['php', 'laravel', 'os'],
            'server' => ['cpu_percent', 'memory_percent', 'disk_percent'],
            'health' => ['database' => ['ok', 'message'], 'redis', 'queues', 'scheduler'],
            'errors',
            'errors_complete',
        ])
        ->assertCookieMissing((string) config('session.cookie'));
});

it('describes each error group', function () {
    insertError([
        'fingerprint' => 'abc', 'level' => 'critical', 'class' => 'App\Exceptions\Broken', 'message' => 'It broke',
        'file' => 'app/Http/Controllers/OrderController.php', 'line' => 42, 'method' => 'POST',
        'url' => 'https://example.com/orders', 'occurrences' => 17,
        'frames' => json_encode([['file' => 'app/Http/Controllers/OrderController.php', 'line' => 42, 'function' => 'App\Http\Controllers\OrderController->store', 'in_app' => true]]),
        'first_seen_at' => '2026-11-01 10:00:00', 'last_seen_at' => '2026-11-01 11:00:00',
    ]);

    readAgent()->assertJsonPath('errors_complete', true)->assertJsonPath('errors.0', [
        'fingerprint' => 'abc',
        'level' => 'critical',
        'class' => 'App\Exceptions\Broken',
        'message' => 'It broke',
        'file' => 'app/Http/Controllers/OrderController.php',
        'line' => 42,
        'method' => 'POST',
        'url' => 'https://example.com/orders',
        'frames' => [['file' => 'app/Http/Controllers/OrderController.php', 'line' => 42, 'function' => 'App\Http\Controllers\OrderController->store', 'in_app' => true]],
        'count' => 17,
        'first_seen_at' => '2026-11-01T10:00:00+00:00',
        'last_seen_at' => '2026-11-01T11:00:00+00:00',
    ]);
});

it('returns frames in the same shape whatever order the database keeps their keys in', function () {
    // MySQL's JSON column sorts object keys by length, so it returns in_app before function.
    insertError(['frames' => '[{"file": "app/Thrower.php", "line": 12, "in_app": true, "function": "App\\\\Thrower->run"}]']);

    readAgent()->assertJsonPath('errors.0.frames', [
        ['file' => 'app/Thrower.php', 'line' => 12, 'function' => 'App\Thrower->run', 'in_app' => true],
    ]);
});

it('returns errors seen since a time, oldest first, in pages', function () {
    $start = now()->subDay()->startOfMinute();

    foreach (range(0, 200) as $index) {
        insertError(['fingerprint' => "e-{$index}", 'last_seen_at' => $start->copy()->addSeconds($index)]);
    }

    $first = readAgent()->assertJsonPath('errors_complete', false);
    $page = collect($first->json('errors'));

    expect($page)->toHaveCount(200)
        ->and($page->first()['fingerprint'])->toBe('e-0')
        ->and($page->last()['fingerprint'])->toBe('e-199');

    $rest = collect(readAgent(['errors_since' => $page->last()['last_seen_at']])
        ->assertJsonPath('errors_complete', true)
        ->json('errors'))->pluck('fingerprint');

    expect($rest->all())->toBe(['e-199', 'e-200']);
});

it('compares errors_since across time zones', function () {
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    insertError(['fingerprint' => 'noon-in-new-york', 'last_seen_at' => '2026-07-01 12:00:00']);   // 16:00 UTC

    expect(readAgent(['errors_since' => '2026-07-01T16:30:00Z'])->json('errors'))->toBe([])
        ->and(readAgent(['errors_since' => '2026-07-01T15:30:00Z'])->json('errors.0.last_seen_at'))->toBe('2026-07-01T12:00:00-04:00');
});

it('rejects an unreadable errors_since', function () {
    readAgent(['errors_since' => 'nonsense'])->assertUnprocessable();
});

it('still answers when the database is down', function () {
    config(['database.connections.upkeep-agent' => ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']]);
    DB::purge(UpKeepAgent::CONNECTION);

    readAgent()
        ->assertOk()
        ->assertJsonPath('health.database.ok', false)
        ->assertJsonPath('health.scheduler', null)
        ->assertJsonPath('errors', null)
        ->assertJsonPath('server.cpu_percent', null);
});

it('tries a database that is down only once per read', function () {
    $attempts = failAgentConnections();

    readAgent()
        ->assertOk()
        ->assertJsonPath('health.database.ok', false)
        ->assertJsonPath('errors', null)
        ->assertJsonPath('server.cpu_percent', null);

    expect($attempts->count)->toBe(1);
});

it('gives up on a database server that accepts the connection but never answers', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) Str::afterLast((string) stream_socket_get_name($server, false), ':');

    config([
        'upkeep-agent.health_timeout' => 1,
        'database.connections.upkeep-agent' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'app',
            'username' => 'app', 'password' => '', 'options' => [PDO::ATTR_TIMEOUT => 1],
        ],
    ]);
    DB::purge(UpKeepAgent::CONNECTION);

    $started = microtime(true);

    readAgent()->assertOk()->assertJsonPath('health.database.ok', false);

    expect(microtime(true) - $started)->toBeLessThan(5.0);

    fclose($server);
})->skip(! extension_loaded('pdo_mysql'), 'Needs pdo_mysql.');

it('wins over a host app catch-all route', function () {
    Route::get('{any}', fn () => 'app page')->where('any', '.*');

    readAgent()->assertOk()->assertJsonPath('contract', 1);
});
