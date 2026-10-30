<?php

use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Guardian\ErrorCounter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);

        // Last in the global stack so it sees every response, including 404s and error pages.
        $middleware->append(SecurityHeaders::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('ops:backup')
            ->daily()
            ->onFailure(function (): void {
                Log::error('ops:backup scheduled run failed');
            });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Feeds the guardian's hourly error counter (ErrorRateCheck). The
        // counter swallows its own failures, so this cannot break reporting.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorCounter::class)->record($e);
        });
    })->create();
