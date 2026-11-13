<?php

namespace App\Providers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Spreadsheet;
use App\Policies\SpreadsheetPolicy;
use App\Services\Integrations\ForwardNoticesToNotify;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Spreadsheet::class => SpreadsheetPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        Event::listen(NotificationSent::class, ForwardNoticesToNotify::class);

        // A Livewire component's later requests go to /livewire/update, not to the page's own route: the entitlement
        // check (R-50) has to follow them there, or an open panel would keep working after access ends.
        Livewire::addPersistentMiddleware([EnsureCompanyEntitled::class]);
    }
}
