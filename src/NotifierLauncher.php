<?php

namespace Doppar\Notifier;

use Phaseolies\Launchers\GhostableLauncher;
use Phaseolies\Launchers\ServiceLauncher;
use Doppar\Notifier\NotificationManager;
use Doppar\Notifier\Console\Commands\MakeNotificationCommand;

class NotifierLauncher extends ServiceLauncher implements GhostableLauncher
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(NotificationManager::class, function ($app) {
            return new NotificationManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function launch(): void
    {
        $this->loadMigrations(__DIR__ . '/database/migrations');

        $this->publishes([
            __DIR__ . '/database/migrations' => schema_path('migrations'),
        ], 'migrations');

        $this->commands([
            MakeNotificationCommand::class
        ]);
    }

    /**
     * Get the services that should ghost-load this provider.
     *
     * @return array<int, string>
     */
    public function ghosts(): array
    {
        return [
            NotificationManager::class,
        ];
    }
}
