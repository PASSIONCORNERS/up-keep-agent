<?php

namespace Passioncorners\UpKeepAgent\Server;

use Carbon\CarbonImmutable;
use Passioncorners\UpKeepAgent\AgentState;
use Passioncorners\UpKeepAgent\UpKeepAgent;
use Throwable;

/**
 * CPU, memory and disk use as percentages. Anything that can't be read, such as /proc
 * on a Mac or a CPU sample the scheduler stopped taking, is null rather than a guess.
 */
class ServerReadings
{
    public function __construct(
        private AgentState $state,
        private string $procPath = '/proc',
        private ?string $diskPath = null,
    ) {}

    /**
     * @return array{cpu_percent: float|null, memory_percent: float|null, disk_percent: float|null}
     */
    public function read(CarbonImmutable $now): array
    {
        return [
            'cpu_percent' => $this->cpu($now),
            'memory_percent' => $this->memory(),
            'disk_percent' => $this->disk(),
        ];
    }

    private function cpu(CarbonImmutable $now): ?float
    {
        try {
            $cpu = $this->state->get('cpu');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($cpu['measured_at'] ?? null) || ! is_numeric($cpu['percent'] ?? null)) {
            return null;
        }

        $age = $now->getTimestamp() - CarbonImmutable::parse($cpu['measured_at'])->getTimestamp();

        return $age <= UpKeepAgent::STALE_SECONDS ? (float) $cpu['percent'] : null;
    }

    /**
     * MemAvailable, not MemFree: Linux fills spare memory with cache it gives back on
     * demand, so "free" alone would make every healthy server look nearly full.
     */
    private function memory(): ?float
    {
        $path = $this->procPath.'/meminfo';

        if (! is_readable($path)) {
            return null;
        }

        $info = (string) file_get_contents($path);

        if (! preg_match('/^MemTotal:\s+(\d+)/m', $info, $total)
            || ! preg_match('/^MemAvailable:\s+(\d+)/m', $info, $available)
            || (int) $total[1] === 0) {
            return null;
        }

        return round((1 - (int) $available[1] / (int) $total[1]) * 100, 1);
    }

    private function disk(): ?float
    {
        $path = $this->diskPath ?? base_path();

        if (! is_dir($path)) {
            return null;
        }

        $total = disk_total_space($path);
        $free = disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return round((1 - $free / $total) * 100, 1);
    }
}
