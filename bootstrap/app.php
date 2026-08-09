<?php

use App\Http\Middleware\EnsurePlatformOperator;
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
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('ops:backup')
            ->daily()
            ->onFailure(function (): void {
                Log::error('ops:backup scheduled run failed');
            });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
