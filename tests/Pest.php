<?php

use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Support\Facades\DB;
use Passioncorners\UpKeepAgent\Tests\TestCase;
use Passioncorners\UpKeepAgent\UpKeepAgent;

pest()->extend(TestCase::class)->in('Feature');

/**
 * A stand-in for /proc with the given stat and meminfo contents.
 */
function fakeProc(
    string $stat = 'cpu  0 0 0 0 0 0 0 0 0 0',
    string $meminfo = "MemTotal:        1000 kB\nMemFree:          100 kB\nMemAvailable:     250 kB\n",
): string {
    $directory = sys_get_temp_dir().'/upkeep-agent-proc-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents("{$directory}/stat", $stat);
    file_put_contents("{$directory}/meminfo", $meminfo);

    return $directory;
}

/**
 * Insert an error group directly, bypassing the recorder.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertError(array $overrides = []): void
{
    $now = now();

    DB::table('upkeep_errors')->insert(array_merge([
        'fingerprint' => sha1(random_bytes(8)),
        'level' => 'error',
        'class' => RuntimeException::class,
        'message' => 'boom',
        'file' => 'app/Thrower.php',
        'line' => 12,
        'method' => null,
        'url' => null,
        'frames' => '[]',
        'occurrences' => 1,
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

/**
 * Make every connection attempt on the agent's own connection fail the way an unreachable
 * MySQL server does on Linux ("Connection timed out", which Laravel retries), and count
 * the attempts. The app's own connection keeps working.
 */
function failAgentConnections(): stdClass
{
    $attempts = new stdClass;
    $attempts->count = 0;
    $driver = (string) config('database.connections.'.UpKeepAgent::CONNECTION.'.driver');

    app()->bind("db.connector.{$driver}", fn () => new class($attempts, $driver) implements ConnectorInterface
    {
        public function __construct(private stdClass $attempts, private string $driver) {}

        public function connect(array $config)
        {
            if (($config['name'] ?? null) === UpKeepAgent::CONNECTION) {
                $this->attempts->count++;

                throw new PDOException('SQLSTATE[HY000] [2002] Connection timed out');
            }

            return ($this->driver === 'mysql' ? new MySqlConnector : new SQLiteConnector)->connect($config);
        }
    });

    DB::purge(UpKeepAgent::CONNECTION);

    return $attempts;
}
