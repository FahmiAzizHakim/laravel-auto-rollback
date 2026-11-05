<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Http\Middleware;

use Closure;
use FahmiAzizHakim\LaravelAutoRollback\AutoRollback;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RollBackRequest
{
    public function __construct(
        protected Application $app,
        protected AutoRollback $autoRollback,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Checked again here, not only at boot, so a change of mode or
        // environment after the app booted is honoured.
        if ($this->autoRollback->handles()) {
            $this->autoRollback->start($request);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Pushed to the end of the app's terminating callbacks rather than run
        // here, so writes from dispatch()->afterResponse() and other
        // terminating callbacks are still inside the transaction.
        $this->app->terminating(fn () => $this->autoRollback->finish($request, $response));
    }
}
