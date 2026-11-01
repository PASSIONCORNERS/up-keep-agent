<?php

namespace Passioncorners\UpKeepAgent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The agent's own database connection, and whether it answers.
 *
 * With the database down, Laravel retries the connection and then the query, so every
 * agent query could wait out its time limit up to four times. Instead the first failure
 * is remembered: for 30 seconds this process skips the agent's database work at once,
 * so a failing request, a page read or a sample run pays for one attempt at most. The
 * page's health check always tries again (probe()).
 *
 * A singleton, so the 30 seconds also span requests in a long-lived Octane or queue
 * worker. It holds nothing about any request.
 */
class AgentDatabase
{
    public const RETRY_AFTER_SECONDS = 30;

    private ?int $failedAt = null;

    /**
     * @throws Throwable when the database can't be reached, now or in the last 30 seconds.
     */
    public function connection(): Connection
    {
        if ($this->failedAt !== null && CarbonImmutable::now()->getTimestamp() - $this->failedAt < self::RETRY_AFTER_SECONDS) {
            throw new RuntimeException('The database could not be reached moments ago.');
        }

        $failure = $this->probe();

        if ($failure !== null) {
            throw $failure;
        }

        return DB::connection(UpKeepAgent::CONNECTION);
    }

    /**
     * Connect now, or confirm the connection is open, whatever happened before.
     *
     * PDO::ATTR_TIMEOUT only limits reaching the server. A MySQL server that accepts the
     * connection and then never answers would hold PHP for a day (mysqlnd's read
     * timeout), so that limit is lowered too while this connection opens.
     */
    public function probe(): ?Throwable
    {
        $readTimeout = ini_get('mysqlnd.net_read_timeout');

        if ($readTimeout !== false) {
            ini_set('mysqlnd.net_read_timeout', (string) max(1, (int) config('upkeep-agent.health_timeout')));
        }

        try {
            DB::connection(UpKeepAgent::CONNECTION)->getPdo();
            $this->failedAt = null;

            return null;
        } catch (Throwable $exception) {
            $this->failedAt = CarbonImmutable::now()->getTimestamp();

            return $exception;
        } finally {
            if ($readTimeout !== false) {
                ini_set('mysqlnd.net_read_timeout', $readTimeout);
            }
        }
    }
}
