<?php

namespace Passioncorners\UpKeepAgent\Errors;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * One reported exception, reduced to what up-keep needs and nothing about the request
 * beyond its method and route pattern.
 *
 * Exceptions group by their class and the first stack frame in the app's own code. Not
 * where they were thrown, because many are thrown inside vendor/ (every QueryException
 * comes from Connection.php), and not by line or message, which change with every edit
 * and carry IDs.
 *
 * @phpstan-type Frame array{file: string, line: int, function: string, in_app: bool}
 */
final readonly class ErrorReport
{
    public const FRAME_LIMIT = 40;

    public const MESSAGE_LIMIT = 1000;

    /**
     * Every request or command passes through these, so they say nothing about where an
     * error came from; counting them as the app's code would merge unrelated vendor
     * failures into one group.
     */
    private const ENTRY_SCRIPTS = ['artisan', 'public/index.php'];

    /**
     * @param  list<Frame>  $frames
     */
    public function __construct(
        public string $fingerprint,
        public string $level,
        public string $class,
        public string $message,
        public string $file,
        public ?int $line,
        public ?string $method,
        public ?string $url,
        public array $frames,
    ) {}

    /**
     * @param  string  $level  One of critical, error or warning (see levelFor()).
     */
    public static function fromThrowable(Throwable $exception, string $level, string $basePath, ?Request $request): self
    {
        $frames = self::frames($exception, self::roots($basePath));
        $key = self::firstInApp($frames) ?? $frames[0];
        $class = self::className($exception::class);

        $route = $request?->route();
        $routed = $request !== null && $route instanceof Route;

        return new self(
            fingerprint: sha1($class.'|'.$key['file'].'|'.$key['function']),
            level: $level,
            class: $class,
            message: Str::limit(self::message($exception), self::MESSAGE_LIMIT - 1, '…'),
            file: $key['file'],
            line: $key['line'],
            method: $routed ? $request->method() : null,
            url: $routed ? $request->getSchemeAndHttpHost().'/'.ltrim($route->uri(), '/') : null,
            frames: array_slice($frames, 0, self::FRAME_LIMIT),
        );
    }

    /**
     * Collapse the eight PSR log levels into the three up-keep shows.
     */
    public static function levelFor(string $psrLevel): string
    {
        return match ($psrLevel) {
            'emergency', 'alert', 'critical' => 'critical',
            'error' => 'error',
            default => 'warning',
        };
    }

    /**
     * Frame 0 is the throw point, inside the function trace[0] names. Each later frame is
     * a call site from the trace, inside the function named by the *next* trace entry.
     * Calls made by PHP itself (array_map and the like) have no call site and are skipped.
     *
     * @param  list<string>  $roots
     * @return non-empty-list<Frame>
     */
    private static function frames(Throwable $exception, array $roots): array
    {
        $trace = $exception->getTrace();
        $frames = [self::frame($exception->getFile(), $exception->getLine(), $trace[0] ?? null, $roots)];

        foreach ($trace as $index => $entry) {
            if (isset($entry['file'], $entry['line'])) {
                $frames[] = self::frame($entry['file'], $entry['line'], $trace[$index + 1] ?? null, $roots);
            }
        }

        return $frames;
    }

    /**
     * @param  array{function: string, class?: class-string, type?: '->'|'::'}|null  $running
     * @param  list<string>  $roots
     * @return Frame
     */
    private static function frame(string $file, int $line, ?array $running, array $roots): array
    {
        foreach ($roots as $root) {
            if (str_starts_with($file, $root.'/')) {
                $relative = substr($file, strlen($root) + 1);

                return [
                    'file' => $relative,
                    'line' => $line,
                    'function' => self::functionName($running),
                    'in_app' => ! str_starts_with($relative, 'vendor/') && ! in_array($relative, self::ENTRY_SCRIPTS, true),
                ];
            }
        }

        return ['file' => $file, 'line' => $line, 'function' => self::functionName($running), 'in_app' => false];
    }

    /**
     * The base path as given and as resolved, so a symlinked deploy directory still
     * recognises the app's files.
     *
     * @return list<string>
     */
    private static function roots(string $basePath): array
    {
        $real = realpath($basePath);

        return array_values(array_unique(array_filter([
            rtrim($basePath, '/'),
            $real === false ? '' : rtrim($real, '/'),
        ])));
    }

    /**
     * @param  list<Frame>  $frames
     * @return Frame|null
     */
    private static function firstInApp(array $frames): ?array
    {
        foreach ($frames as $frame) {
            if ($frame['in_app']) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * PHP 8.4 names closures "{closure:Class::method():12}" and older versions
     * "Namespace\{closure}". The line would split one error into many and the namespace
     * would make PHP versions disagree, so every closure is just "{closure}".
     *
     * @param  array{function: string, class?: class-string, type?: '->'|'::'}|null  $running
     */
    private static function functionName(?array $running): string
    {
        if ($running === null) {
            return '{main}';
        }

        $function = str_contains($running['function'], '{closure') ? '{closure}' : $running['function'];

        return isset($running['class'])
            ? self::className($running['class']).($running['type'] ?? '::').$function
            : $function;
    }

    /**
     * Anonymous classes carry their file path and a counter after "@anonymous".
     */
    private static function className(string $class): string
    {
        return str_contains($class, '@anonymous') ? Str::before($class, '@anonymous').'@anonymous' : $class;
    }

    /**
     * Laravel puts a query's bound values into its message, and exceptions that wrap a
     * query error (a Blade view, a deadlock retry) copy that message into their own. The
     * values are customer data, so each query error's message is swapped for one that
     * keeps the SQL's placeholders, and MySQL's own quoted values are masked too.
     */
    private static function message(Throwable $exception): string
    {
        $message = $exception->getMessage();

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException) {
                $message = str_replace($cause->getMessage(), self::queryMessage($cause), $message);
            }
        }

        return (string) preg_replace(
            ["/Duplicate entry '.*?' for key/is", "/(incorrect \\w+ value): '.*?'/is"],
            ["Duplicate entry '?' for key", "\$1: '?'"],
            $message,
        );
    }

    private static function queryMessage(QueryException $exception): string
    {
        return ($exception->getPrevious()?->getMessage() ?? '')
            .' (Connection: '.$exception->getConnectionName().', SQL: '.$exception->getSql().')';
    }
}
