<?php

namespace Passioncorners\UpKeepAgent\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Orchestra\Testbench\TestCase as Orchestra;
use Passioncorners\UpKeepAgent\UpKeepAgentServiceProvider;

/**
 * Every test gets a fresh SQLite *file*, not an in-memory database: the agent writes on
 * its own cloned connection, and a second connection to ":memory:" would see an empty
 * database. Set UPKEEP_TEST_DB=mysql (with the DB_* variables) to run against MySQL.
 */
abstract class TestCase extends Orchestra
{
    use DatabaseMigrations;

    protected string $databasePath = '';

    protected function setUp(): void
    {
        $this->databasePath = (string) tempnam(sys_get_temp_dir(), 'upkeep-agent-');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [UpKeepAgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.default', 'null');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');

        $app['config']->set('database.connections.testing', getenv('UPKEEP_TEST_DB') === 'mysql'
            ? [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'options' => [],
            ]
            : [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
    }
}
