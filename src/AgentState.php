<?php

namespace Passioncorners\UpKeepAgent;

use Illuminate\Database\Query\Builder;

/**
 * Small values the agent keeps between runs: the scheduler check-in, the last CPU
 * reading, the front of each Redis queue. A table rather than the cache, so a cache
 * clear can't fake "the scheduler stopped".
 *
 * Reads and writes may throw (the database may be down); callers decide what that means.
 */
class AgentState
{
    public function __construct(private AgentDatabase $database) {}

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $value = $this->table()->where('key', $key)->value('value');

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value): void
    {
        $this->table()->upsert([[
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'updated_at' => now(),
        ]], ['key'], ['value', 'updated_at']);
    }

    private function table(): Builder
    {
        return $this->database->connection()->table('upkeep_agent_state');
    }
}
