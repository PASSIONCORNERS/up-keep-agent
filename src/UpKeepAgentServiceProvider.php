<?php

namespace Passioncorners\UpKeepAgent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Passioncorners\UpKeepAgent\Console\SampleCommand;
use Passioncorners\UpKeepAgent\Errors\ErrorRecorder;
use PDO;

class UpKeepAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/upkeep-agent.php', 'upkeep-agent');

        $this->app->singleton(AgentDatabase::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/agent.php');

        $this->configureConnections();

        Event::listen(MessageLogged::class, [ErrorRecorder::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([SampleCommand::class]);
        }

        // Runs from the app's own `schedule:work` / `schedule:run`, no code change needed.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('upkeep-agent:sample')->everyMinute();
        });
    }

    /**
     * The agent works on its own copies of the app's default database and Redis
     * connections. Its error writes then survive the app's rolled-back transactions,
     * and its health checks give up after a few seconds instead of hanging the page.
     */
    private function configureConnections(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');
        $timeout = (int) $config->get('upkeep-agent.health_timeout');

        $database = $config->get('database.connections.'.$config->get('database.default'));

        if (is_array($database)) {
            $database['options'] = array_replace((array) ($database['options'] ?? []), [PDO::ATTR_TIMEOUT => $timeout]);

            $config->set('database.connections.'.UpKeepAgent::CONNECTION, $database);
        }

        $redis = $config->get('database.redis.default');

        if (is_array($redis)) {
            $config->set('database.redis.'.UpKeepAgent::REDIS_CONNECTION, array_replace($redis, [
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'read_write_timeout' => $timeout,
            ]));
        }
    }
}
