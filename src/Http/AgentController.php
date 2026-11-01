<?php

namespace Passioncorners\UpKeepAgent\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Passioncorners\UpKeepAgent\AgentDatabase;
use Passioncorners\UpKeepAgent\Errors\ErrorReport;
use Passioncorners\UpKeepAgent\Health\HealthReadings;
use Passioncorners\UpKeepAgent\Server\ServerReadings;
use Passioncorners\UpKeepAgent\Server\StackReadings;
use Passioncorners\UpKeepAgent\UpKeepAgent;
use stdClass;
use Throwable;

/**
 * The one page up-keep reads every minute (contract version 1).
 *
 * It answers even when the database is down: that part of the page says so, and the
 * readings that need the database are null.
 *
 * @phpstan-import-type Frame from ErrorReport
 *
 * @phpstan-type ErrorGroup array{fingerprint: string, level: string, class: string, message: string, file: string, line: int|null, method: string|null, url: string|null, frames: list<Frame>, count: int, first_seen_at: string, last_seen_at: string}
 */
class AgentController
{
    public function __invoke(Request $request, AgentDatabase $database, StackReadings $stack, ServerReadings $server, HealthReadings $health): JsonResponse
    {
        $secret = config('upkeep-agent.secret');

        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $token = $request->bearerToken();

        if (! is_string($token) || ! hash_equals($secret, $token)) {
            return new JsonResponse(['message' => 'Unauthorized.'], 401);
        }

        try {
            $since = $this->since($request->query('errors_since'));
        } catch (Throwable) {
            return new JsonResponse(['message' => 'errors_since must be a date and time.'], 422);
        }

        $now = CarbonImmutable::now();

        // Health first: it always tries the database, and when that fails, the readings
        // after it skip the database at once instead of each waiting out a time limit.
        $healthReading = $health->read($now);
        [$errors, $complete] = $this->errors($database, $since);

        return new JsonResponse([
            'contract' => UpKeepAgent::CONTRACT,
            'agent_version' => UpKeepAgent::VERSION,
            'generated_at' => $now->toIso8601String(),
            'stack' => $stack->read(),
            'server' => $server->read($now),
            'health' => $healthReading,
            'errors' => $errors,
            'errors_complete' => $complete,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Stored times are in the app's time zone, so the cursor is converted to it before
     * comparing.
     */
    private function since(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Not a date.');
        }

        return CarbonImmutable::parse($value)->setTimezone($this->timezone());
    }

    /**
     * Oldest first, so a backlog arrives in order: up-keep asks again from the newest
     * time it received. ">=" may resend the group at the boundary, which is harmless
     * because up-keep stores totals, and never skips one that shares its second.
     *
     * @return array{list<ErrorGroup>|null, bool}
     */
    private function errors(AgentDatabase $database, ?CarbonImmutable $since): array
    {
        try {
            $rows = $database->connection()
                ->table('upkeep_errors')
                ->when($since, fn ($query) => $query->where('last_seen_at', '>=', $since))
                ->orderBy('last_seen_at')
                ->orderBy('id')
                ->limit(UpKeepAgent::PAGE_SIZE + 1)
                ->get();
        } catch (Throwable) {
            return [null, false];
        }

        return [
            array_values($rows->take(UpKeepAgent::PAGE_SIZE)->map(fn (stdClass $row): array => $this->errorGroup($row))->all()),
            $rows->count() <= UpKeepAgent::PAGE_SIZE,
        ];
    }

    /**
     * @return ErrorGroup
     */
    private function errorGroup(stdClass $row): array
    {
        return [
            'fingerprint' => (string) $row->fingerprint,
            'level' => (string) $row->level,
            'class' => (string) $row->class,
            'message' => (string) $row->message,
            'file' => (string) $row->file,
            'line' => $row->line === null ? null : (int) $row->line,
            'method' => $row->method === null ? null : (string) $row->method,
            'url' => $row->url === null ? null : (string) $row->url,
            'frames' => $this->frames($row->frames),
            'count' => (int) $row->occurrences,
            'first_seen_at' => $this->iso($row->first_seen_at),
            'last_seen_at' => $this->iso($row->last_seen_at),
        ];
    }

    /**
     * MySQL's JSON column re-sorts object keys, so each frame is rebuilt in the order the
     * recorder wrote it, and the page reads the same on every database.
     *
     * @return list<Frame>
     */
    private function frames(mixed $stored): array
    {
        $decoded = json_decode((string) $stored, true);
        $frames = [];

        foreach (is_array($decoded) ? $decoded : [] as $frame) {
            if (is_array($frame)) {
                $frames[] = [
                    'file' => (string) ($frame['file'] ?? ''),
                    'line' => (int) ($frame['line'] ?? 0),
                    'function' => (string) ($frame['function'] ?? ''),
                    'in_app' => (bool) ($frame['in_app'] ?? false),
                ];
            }
        }

        return $frames;
    }

    private function iso(mixed $stored): string
    {
        return CarbonImmutable::parse((string) $stored, $this->timezone())->toIso8601String();
    }

    private function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) ? $timezone : 'UTC';
    }
}
