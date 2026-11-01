<?php

namespace Passioncorners\UpKeepAgent\Errors;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Passioncorners\UpKeepAgent\AgentDatabase;
use Throwable;

/**
 * Records every exception the app reports, by listening for the log entry Laravel writes
 * for it (as Pulse and Telescope do). Whatever the app doesn't report, such as 404s and
 * validation errors, stays out.
 *
 * The write goes through the agent's own connection, so it survives the app's
 * transaction rolling back. Recording never throws into the app and never records its
 * own failures.
 */
final class ErrorRecorder
{
    /**
     * True while a report is being written. Reset in `finally`, so a failed write never
     * leaves a long-lived Octane worker unable to record again.
     */
    private static bool $recording = false;

    public function __construct(private AgentDatabase $database) {}

    public function handle(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        if (! $exception instanceof Throwable || self::$recording) {
            return;
        }

        self::$recording = true;

        try {
            $request = app()->bound('request') ? app('request') : null;

            $this->record(ErrorReport::fromThrowable(
                $exception,
                ErrorReport::levelFor($event->level),
                base_path(),
                $request instanceof Request ? $request : null,
            ), now());
        } catch (Throwable) {
            // Losing one report is better than breaking the app or reporting ourselves.
        } finally {
            self::$recording = false;
        }
    }

    /**
     * One statement per occurrence: a new group starts at 1, a known one counts up in
     * the database itself, so workers reporting at the same moment never lose a count.
     */
    public function record(ErrorReport $report, DateTimeInterface $at): void
    {
        $this->database->connection()->table('upkeep_errors')->upsert([[
            'fingerprint' => $report->fingerprint,
            'level' => $report->level,
            'class' => $report->class,
            'message' => $report->message,
            'file' => $report->file,
            'line' => $report->line,
            'method' => $report->method,
            'url' => $report->url,
            'frames' => json_encode($report->frames, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurrences' => 1,
            'first_seen_at' => $at,
            'last_seen_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]], ['fingerprint'], [
            'level', 'message', 'file', 'line', 'method', 'url', 'frames', 'last_seen_at', 'updated_at',
            'occurrences' => DB::raw('occurrences + 1'),
        ]);
    }
}
