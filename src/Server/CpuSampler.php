<?php

namespace Passioncorners\UpKeepAgent\Server;

use Carbon\CarbonImmutable;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\UpKeepAgent;

/**
 * CPU use is a rate, so one reading of /proc/stat says nothing: each minute's sample is
 * compared with the previous one. The result is how busy the CPU was over that minute.
 */
class CpuSampler
{
    public function __construct(private AgentState $state, private string $procPath = '/proc') {}

    public function sample(CarbonImmutable $now): void
    {
        $times = $this->times();

        if ($times === null) {
            return;
        }

        [$busy, $total] = $times;
        $previous = $this->state->get('cpu');

        $this->state->put('cpu', [
            'percent' => $this->percentSince($previous, $busy, $total, $now),
            'measured_at' => $now->toIso8601String(),
            'busy' => $busy,
            'total' => $total,
        ]);
    }

    /**
     * Null after a gap: a reading spanning an hour the scheduler missed isn't "the last
     * minute".
     *
     * @param  array<string, mixed>|null  $previous
     */
    private function percentSince(?array $previous, int $busy, int $total, CarbonImmutable $now): ?float
    {
        if (! is_string($previous['measured_at'] ?? null) || ! is_int($previous['busy'] ?? null) || ! is_int($previous['total'] ?? null)) {
            return null;
        }

        $gap = $now->getTimestamp() - CarbonImmutable::parse($previous['measured_at'])->getTimestamp();

        if ($gap <= 0 || $gap > UpKeepAgent::STALE_SECONDS || $total <= $previous['total']) {
            return null;
        }

        return round(($busy - $previous['busy']) / ($total - $previous['total']) * 100, 1);
    }

    /**
     * Busy and total jiffies since boot, from the first "cpu" line. Only the first eight
     * fields count: guest time is already included in user and nice.
     *
     * @return array{int, int}|null
     */
    private function times(): ?array
    {
        $path = $this->procPath.'/stat';

        if (! is_readable($path) || ! preg_match('/^cpu\s+([\d\s]+)$/m', (string) file_get_contents($path), $matches)) {
            return null;
        }

        $fields = array_map('intval', array_slice(preg_split('/\s+/', trim($matches[1])) ?: [], 0, 8));

        if (count($fields) < 5) {
            return null;
        }

        $total = array_sum($fields);
        $idle = $fields[3] + $fields[4];

        return [$total - $idle, $total];
    }
}
