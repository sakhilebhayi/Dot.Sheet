<?php

namespace App\Providers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Membership;
use App\Models\Spreadsheet;
use App\Policies\SpreadsheetPolicy;
use App\Services\AccountNotices;
use App\Services\Integrations\ForwardNoticesToNotify;
use App\Support\EssentialCron;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberUpdated;
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
        EssentialCron::listen();

        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        Event::listen(NotificationSent::class, ForwardNoticesToNotify::class);

        // EA-2 (E-07): people are told about changes to their company role and their security settings.
        Event::listen(TeamMemberAdded::class, fn ($e) => app(AccountNotices::class)->teamAdded($e->team, $e->user));
        Event::listen(TeamMemberUpdated::class, fn ($e) => app(AccountNotices::class)->teamRole($e->team, $e->user, (string) (Membership::query()->where('team_id', $e->team->id)->where('user_id', $e->user->id)->value('role') ?: 'member')));
        Event::listen(PasswordUpdatedViaController::class, fn ($e) => app(AccountNotices::class)->security($e->user, 'Your password was changed'));
        Event::listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => app(AccountNotices::class)->security($e->user, 'Two-step sign-in was turned on'));
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($e) => app(AccountNotices::class)->security($e->user, 'Two-step sign-in was turned off'));

        // A Livewire component's later requests go to /livewire/update, not to the page's own route: the entitlement
        // check (R-50) has to follow them there, or an open panel would keep working after access ends.
        Livewire::addPersistentMiddleware([EnsureCompanyEntitled::class]);
    }
}
