<?php

use Fixtures\App\Thrower;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\View\ViewException;
use Passioncorners\UpKeepAgent\Errors\ErrorReport;

function fixturesPath(): string
{
    return dirname(__DIR__).'/Fixtures';
}

function caught(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new LogicException('Nothing was thrown.');
}

it('fingerprints by the first frame in the app, not where vendor code threw', function () {
    $report = ErrorReport::fromThrowable(caught(fn () => (new Thrower)->run(0)), 'error', fixturesPath(), null);

    expect($report->file)->toBe('app/Thrower.php')
        ->and($report->line)->toBe($report->frames[1]['line'])
        ->and($report->fingerprint)->toBe(sha1('RuntimeException|app/Thrower.php|Fixtures\App\Thrower->run'))
        ->and($report->frames[0])->toMatchArray([
            'file' => 'vendor/acme/Library.php',
            'function' => 'Fixtures\Vendor\Library->explode',
            'in_app' => false,
        ])
        ->and($report->frames[1])->toMatchArray(['file' => 'app/Thrower.php', 'in_app' => true]);
});

it('keeps the same fingerprint when the throw moves to another line', function () {
    $fingerprints = collect([1, 2])->map(fn (int $site) => ErrorReport::fromThrowable(
        caught(fn () => (new Thrower)->run($site)), 'error', fixturesPath(), null,
    )->fingerprint);

    expect($fingerprints->unique())->toHaveCount(1);
});

it('falls back to where it was thrown when no frame is the app\'s own', function () {
    $exception = caught(fn () => (new Thrower)->run(1));
    $report = ErrorReport::fromThrowable($exception, 'error', '/somewhere/else', null);

    expect($report->file)->toBe($exception->getFile())
        ->and($report->line)->toBe($exception->getLine())
        ->and(collect($report->frames)->where('in_app', true))->toBeEmpty();
});

it('drops SQL values from query exception messages', function () {
    $exception = new QueryException('mysql', 'insert into users (email) values (?)', ['jane@example.com'],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane@example.com' for key 'users_email_unique'"));

    $report = ErrorReport::fromThrowable($exception, 'error', '/var/www/app', null);

    expect($report->message)->not->toContain('jane@example.com')
        ->toContain("Duplicate entry '?' for key 'users_email_unique'")
        ->toContain('Connection: mysql, SQL: insert into users (email) values (?)');
});

it('drops the value from incorrect value errors', function () {
    $exception = new QueryException('mysql', 'update orders set total = ?', ['abc-secret'],
        new PDOException("SQLSTATE[HY000]: General error: 1366 Incorrect decimal value: 'abc-secret' for column 'total' at row 1"));

    expect(ErrorReport::fromThrowable($exception, 'error', '/var/www/app', null)->message)
        ->not->toContain('abc-secret')
        ->toContain("Incorrect decimal value: '?' for column 'total'");
});

it('drops the value from truncated incorrect value errors', function () {
    $exception = new QueryException('mysql', 'update users set flag = 1 where phone = ?', [5],
        new PDOException("SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'jane@example.com'"));

    expect(ErrorReport::fromThrowable($exception, 'error', '/var/www/app', null)->message)
        ->not->toContain('jane@example.com')
        ->toContain("Truncated incorrect DOUBLE value: '?'");
});

it('drops SQL values from query errors that another exception wraps', function (string $wrapper) {
    $query = new QueryException('mysql', 'select * from users where email = ?', ['jane@example.com'],
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));

    $wrapped = match ($wrapper) {
        'a Blade view' => new ViewException($query->getMessage().' (View: /var/www/app/resources/views/mail.blade.php)', 0, 1, __FILE__, __LINE__, $query),
        'a deadlock' => new DeadlockException($query->getMessage(), 0, $query),
    };

    expect(ErrorReport::fromThrowable($wrapped, 'error', '/var/www/app', null)->message)
        ->not->toContain('jane@example.com')
        ->toContain('SQL: select * from users where email = ?');
})->with(['a Blade view', 'a deadlock']);

it('does not count the entry scripts as the app\'s own code', function (string $entry) {
    $report = ErrorReport::fromThrowable(caught(fn () => require fixturesPath()."/{$entry}"), 'error', fixturesPath(), null);

    expect($report->file)->toBe('vendor/acme/Library.php')
        ->and(collect($report->frames)->firstWhere('file', $entry)['in_app'] ?? null)->toBeFalse();
})->with(['public/index.php', 'artisan']);

it('names closures without the line PHP 8.4 adds', function () {
    $report = ErrorReport::fromThrowable(caught(fn () => (new Thrower)->inClosure()), 'error', fixturesPath(), null);

    expect($report->frames[0]['function'])->toBe('Fixtures\App\Thrower->{closure}');
});

it('uses the route pattern, never the real path or query', function () {
    $request = Request::create('/orders/5?token=abc', 'POST');
    $request->setRouteResolver(fn () => (new Route('POST', 'orders/{order}', []))->bind($request));

    $report = ErrorReport::fromThrowable(new RuntimeException('x'), 'error', '/var/www/app', $request);

    expect($report->url)->toBe('http://localhost/orders/{order}')
        ->and($report->method)->toBe('POST');
});

it('has no method or url outside a routed request', function () {
    $report = ErrorReport::fromThrowable(new RuntimeException('x'), 'error', '/var/www/app', Request::create('/orders/5'));

    expect($report->method)->toBeNull()->and($report->url)->toBeNull();
});

it('maps log levels', function () {
    expect(array_map(ErrorReport::levelFor(...), ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'debug']))
        ->toBe(['critical', 'critical', 'critical', 'error', 'warning', 'warning', 'warning']);
});

it('keeps at most 40 frames and 1000 characters of message', function () {
    $report = ErrorReport::fromThrowable(caught(fn () => (new Thrower)->deep(60)), 'error', fixturesPath(), null);

    expect($report->frames)->toHaveCount(40)
        ->and(mb_strlen($report->message))->toBeLessThanOrEqual(1000);
});
