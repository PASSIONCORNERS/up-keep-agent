<?php

use Fixtures\App\Thrower;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Passioncorners\UpKeepAgent\UpKeepAgent;

it('records a reported exception once and counts repeats', function () {
    $this->freezeTime();

    foreach (range(1, 3) as $attempt) {
        try {
            (new Thrower)->run(1);
        } catch (RuntimeException $exception) {
            report($exception);
        }

        $this->travel(1)->minute();
    }

    $row = DB::table('upkeep_errors')->sole();

    expect((int) $row->occurrences)->toBe(3)
        ->and($row->level)->toBe('error')
        ->and($row->class)->toBe(RuntimeException::class)
        ->and(Carbon::parse($row->last_seen_at)->gt(Carbon::parse($row->first_seen_at)))->toBeTrue();
});

it('stops trying a database that is down for 30 seconds', function () {
    $this->freezeTime();
    $attempts = failAgentConnections();

    report(new RuntimeException('first'));
    report(new RuntimeException('second'));

    expect($attempts->count)->toBe(1);

    $this->travel(31)->seconds();
    report(new RuntimeException('third'));

    expect($attempts->count)->toBe(2);
});

it('records the level the app logged at', function () {
    Log::critical('down', ['exception' => new RuntimeException('down')]);

    expect(DB::table('upkeep_errors')->value('level'))->toBe('critical');
});

it('ignores log messages without an exception', function () {
    Log::error('just text');

    expect(DB::table('upkeep_errors')->count())->toBe(0);
});

it('records the route, not the path, and nothing from the request itself', function () {
    Route::post('orders/{order}', fn () => throw new RuntimeException('boom'));

    $this->withHeader('X-Private', 'header-zq7')
        ->withCookie('private', 'cookie-zq7')
        ->post('/orders/5?token=query-zq7', ['card' => 'body-zq7'])
        ->assertServerError();

    $row = (array) DB::table('upkeep_errors')->sole();

    expect($row['url'])->toBe('http://localhost/orders/{order}')
        ->and($row['method'])->toBe('POST')
        ->and(json_encode($row))->not->toContain('zq7');
});

it('keeps errors reported inside a transaction that rolls back', function () {
    DB::beginTransaction();
    report(new RuntimeException('inside'));
    DB::rollBack();

    expect(DB::table('upkeep_errors')->count())->toBe(1);
});

it('never throws when it cannot record, and records again afterwards', function () {
    Schema::rename('upkeep_errors', 'upkeep_errors_away');

    expect(fn () => report(new RuntimeException('lost')))->not->toThrow(Throwable::class);

    Schema::rename('upkeep_errors_away', 'upkeep_errors');
    report(new RuntimeException('kept'));

    expect(DB::table('upkeep_errors')->pluck('message')->all())->toBe(['kept']);
});

it('does not record errors raised while it is recording', function () {
    DB::connection(UpKeepAgent::CONNECTION)->listen(
        fn () => Log::error('nested', ['exception' => new LogicException('nested')]),
    );

    report(new RuntimeException('outer'));

    expect(DB::table('upkeep_errors')->pluck('class')->all())->toBe([RuntimeException::class]);
});
