<?php

namespace FahmiAzizHakim\LaravelAutoRollback;

use FahmiAzizHakim\LaravelAutoRollback\Http\Middleware\RollBackRequest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;

class AutoRollbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/auto-rollback.php', 'auto-rollback');

        $this->app->singleton(AutoRollback::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/auto-rollback.php' => config_path('auto-rollback.php'),
            ], 'auto-rollback-config');
        }

        $autoRollback = $this->app->make(AutoRollback::class);

        if (! $autoRollback->handles()) {
            return;
        }

        // Both listeners do nothing outside a request the middleware started.
        $this->app['events']->listen(
            ConnectionEstablished::class,
            fn (ConnectionEstablished $event) => $autoRollback->wrap($event->connection)
        );

        $this->app['events']->listen(
            QueryExecuted::class,
            fn (QueryExecuted $event) => $autoRollback->record($event)
        );

        // Global and outermost, so every route -- web and api -- and every
        // other middleware's writes land inside the transaction.
        $this->app->make(HttpKernel::class)->prependMiddleware(RollBackRequest::class);
    }
}
