<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Controllers\SpreadsheetController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Models\Spreadsheet;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::match(['get', 'post'], '/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])->name('auth.ecosystem');

Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::get('shared/s/{token}', [SpreadsheetController::class, 'publicShow'])->name('spreadsheets.public');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();
        $spreadsheets = Spreadsheet::where('owner_id', $user->id)
            ->orWhereHas('sharedUsers', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('cells')
            ->with('owner', 'team')
            ->latest()
            ->get();

        return view('dashboard', compact('spreadsheets'));
    })->name('dashboard');

    // Spreadsheet routes
    Route::resource('spreadsheets', SpreadsheetController::class);

    // Import / Export
    Route::post('spreadsheets/{spreadsheet}/import', [SpreadsheetController::class, 'import'])->name('spreadsheets.import');
    Route::get('spreadsheets/{spreadsheet}/import/batches/{batchId}', [SpreadsheetController::class, 'importBatchStatus'])->name('spreadsheets.import.batch-status');
    Route::get('spreadsheets/{spreadsheet}/export/csv', [SpreadsheetController::class, 'exportCsv'])->name('spreadsheets.export.csv');
    Route::get('spreadsheets/{spreadsheet}/export/excel', [SpreadsheetController::class, 'exportExcel'])->name('spreadsheets.export.excel');

    // Invitations
    Route::get('spreadsheets/invitations/{token}/accept', [SpreadsheetController::class, 'acceptInvitation'])
        ->name('spreadsheets.invitations.accept');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/backups', fn () => view('operator.backups'))->name('backups.index');
    });
});

// Public liveness probe for the cron scheduler and queue worker, watched from GitHub every
// 15 minutes by .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

// Guardian observability report for Dot.Brain (dot-guardian/v1): operational
// detail, so behind a bearer token (fails closed with 503 when none is
// configured) rather than public like /up and /up/scheduler.
Route::get('/guardian/health', GuardianHealthController::class)
    ->middleware(AuthenticateGuardian::class)
    ->name('guardian.health');
