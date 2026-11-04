<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\GridChartController;
use App\Http\Controllers\GridCommentController;
use App\Http\Controllers\GridController;
use App\Http\Controllers\GridCopyController;
use App\Http\Controllers\GridDuplicateController;
use App\Http\Controllers\GridFormulaController;
use App\Http\Controllers\GridMacroController;
use App\Http\Controllers\GridNameController;
use App\Http\Controllers\GridPivotController;
use App\Http\Controllers\GridProtectionController;
use App\Http\Controllers\GridShareController;
use App\Http\Controllers\GridTabController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Controllers\SpreadsheetController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Http\Middleware\RefuseProtectedSheet;
use App\Models\Spreadsheet;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])->name('auth.ecosystem');

// Browsers POST Content-Security-Policy violation reports here (the policy is report-only, see SecurityHeaders). The browser sends
// no cookie, CSRF token or login, so the route drops the web group's session and CSRF middleware: a report neither starts a
// session nor needs one. Throttled per IP; the controller keeps only the violated directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:60,1,csp-report')
    ->name('csp.report');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

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
    Route::get('/dashboard', [SpreadsheetController::class, 'index'])->name('dashboard');

    // Spreadsheet routes
    Route::post('spreadsheets/from-template', [SpreadsheetController::class, 'fromTemplate'])->middleware('throttle:20,1')->name('spreadsheets.from-template');
    Route::post('spreadsheets/from-idea', [SpreadsheetController::class, 'fromIdea'])->middleware('throttle:10,1')->name('spreadsheets.from-idea');
    Route::post('spreadsheets/from-file', [SpreadsheetController::class, 'fromFile'])->middleware('throttle:20,1')->name('spreadsheets.from-file');
    Route::resource('spreadsheets', SpreadsheetController::class)->except('show');
    // A sheet's address opens the new editor. The classic one stays beside it until the new one has its features.
    Route::get('spreadsheets/{spreadsheet}', [GridController::class, 'show'])->name('spreadsheets.show');
    Route::post('spreadsheets/{spreadsheet}/duplicate', [GridDuplicateController::class, 'workbook'])->middleware('throttle:20,1')->name('spreadsheets.duplicate');
    Route::get('spreadsheets/{spreadsheet}/classic', [SpreadsheetController::class, 'classic'])->name('spreadsheets.classic');
    Route::get('spreadsheets/{spreadsheet}/print', [SpreadsheetController::class, 'printOf'])->name('spreadsheets.print');

    // Import / Export
    // The browser-drawn editor and the API it works through.
    Route::redirect('spreadsheets/{spreadsheet}/grid', '/spreadsheets/{spreadsheet}');
    Route::post('spreadsheets/{spreadsheet}/tabs', [GridController::class, 'addSheet'])->name('grid.add');
    Route::post('spreadsheets/{spreadsheet}/tabs/order', [GridTabController::class, 'order'])->name('grid.tabs.order');
    Route::prefix('spreadsheets/{spreadsheet}/macros')->name('grid.macros.')->middleware('throttle:120,1')->group(function () {
        Route::get('/', [GridMacroController::class, 'index'])->name('index');
        Route::post('/', [GridMacroController::class, 'store'])->name('store');
        Route::delete('{macro}', [GridMacroController::class, 'destroy'])->name('destroy');
    });
    Route::prefix('spreadsheets/{spreadsheet}/sharing')->name('grid.sharing.')->middleware('throttle:120,1')->group(function () {
        Route::get('/', [GridShareController::class, 'index'])->name('index');
        Route::post('people', [GridShareController::class, 'share'])->name('share');
        Route::patch('people/{user}', [GridShareController::class, 'change'])->name('change');
        Route::delete('people/{user}', [GridShareController::class, 'remove'])->name('remove');
        Route::delete('invitations/{invitation}', [GridShareController::class, 'cancel'])->name('cancel');
        Route::post('link', [GridShareController::class, 'link'])->name('link');
        Route::post('link/new', [GridShareController::class, 'newLink'])->name('link.new');
    });
    Route::prefix('spreadsheets/{spreadsheet}/tabs/{sheetTab}')->name('grid.')->middleware(['throttle:1200,1', RefuseProtectedSheet::class])->group(function () {
        Route::get('sheet', [GridController::class, 'sheet'])->name('sheet');
        Route::get('cells', [GridController::class, 'cells'])->name('cells');
        Route::post('cells', [GridController::class, 'edit'])->name('edit');
        Route::post('paste', [GridController::class, 'paste'])->name('paste');
        Route::post('fill', [GridController::class, 'fill'])->name('fill');
        Route::get('find', [GridController::class, 'find'])->name('find');
        Route::post('replace', [GridController::class, 'replace'])->name('replace');
        Route::post('format', [GridController::class, 'format'])->name('format');
        Route::post('layout', [GridController::class, 'layout'])->name('layout');
        Route::post('freeze', [GridController::class, 'freeze'])->name('freeze');
        Route::post('hidden', [GridController::class, 'hidden'])->name('hidden');
        Route::post('rules', [GridController::class, 'rules'])->name('rules');
        Route::post('allowed', [GridController::class, 'allowed'])->name('allowed');
        Route::post('sort', [GridController::class, 'sort'])->name('sort');
        Route::post('filter', [GridController::class, 'filter'])->name('filter');
        Route::post('rename', [GridController::class, 'renameSheet'])->name('rename');
        Route::post('duplicate', [GridDuplicateController::class, 'sheet'])->middleware('throttle:20,1')->name('duplicate');
        Route::post('look/colour', [GridTabController::class, 'colour'])->name('look.colour');
        Route::post('look/hidden', [GridTabController::class, 'hidden'])->name('look.hidden');
        Route::get('removal', [GridController::class, 'removalCost'])->name('removal');
        Route::post('remove', [GridController::class, 'removeSheet'])->name('remove');
        Route::post('structure', [GridController::class, 'structure'])->name('structure');
        Route::post('restore', [GridController::class, 'restoreCopy'])->name('restore');
        Route::post('merge', [GridController::class, 'merge'])->name('merge');
        Route::post('unmerge', [GridController::class, 'unmerge'])->name('unmerge');
        Route::get('stats', [GridController::class, 'stats'])->name('stats');
        Route::get('pivots', [GridPivotController::class, 'index'])->name('pivots');
        Route::post('pivots', [GridPivotController::class, 'store'])->name('pivots.store');
        Route::get('pivots/fields', [GridPivotController::class, 'fields'])->name('pivots.fields');
        Route::get('pivots/{pivot}', [GridPivotController::class, 'show'])->whereNumber('pivot')->name('pivots.show');
        Route::delete('pivots/{pivot}', [GridPivotController::class, 'destroy'])->whereNumber('pivot')->name('pivots.destroy');
        Route::get('charts', [GridChartController::class, 'index'])->name('charts');
        Route::post('charts', [GridChartController::class, 'store'])->name('charts.store');
        Route::get('charts/{chart}', [GridChartController::class, 'show'])->name('charts.show');
        Route::delete('charts/{chart}', [GridChartController::class, 'destroy'])->name('charts.destroy');
        Route::get('names', [GridNameController::class, 'index'])->name('names');
        Route::get('from-words', [GridFormulaController::class, 'fromWords'])->name('from-words');
        Route::get('explain/{row}/{col}', [GridFormulaController::class, 'explain'])->whereNumber(['row', 'col'])->name('explain');
        Route::get('protections', [GridProtectionController::class, 'index'])->name('protections.index');
        Route::post('protections', [GridProtectionController::class, 'store'])->name('protections.store');
        Route::delete('protections/{protection}', [GridProtectionController::class, 'destroy'])->name('protections.destroy');
        Route::post('names', [GridNameController::class, 'store'])->name('names.store');
        Route::get('names/{namedRange}/uses', [GridNameController::class, 'uses'])->name('names.uses');
        Route::delete('names/{namedRange}', [GridNameController::class, 'destroy'])->name('names.destroy');
        Route::get('before-import', [GridCopyController::class, 'beforeImport'])->name('import.before');
        Route::get('copies', [GridCopyController::class, 'index'])->name('copies');
        Route::post('copies', [GridCopyController::class, 'store'])->name('copies.store');
        Route::get('copies/{copy}/changes', [GridCopyController::class, 'changes'])->name('copies.changes');
        Route::get('comments', [GridCommentController::class, 'index'])->name('comments');
        Route::post('comments', [GridCommentController::class, 'store'])->name('comments.store');
        Route::post('comments/{comment}/reply', [GridCommentController::class, 'reply'])->name('comments.reply');
        Route::post('comments/{comment}/resolve', [GridCommentController::class, 'resolve'])->name('comments.resolve');
        Route::delete('comments/{comment}', [GridCommentController::class, 'destroy'])->name('comments.destroy');
        Route::get('trace/{row}/{col}', [GridController::class, 'trace'])->whereNumber(['row', 'col'])->name('trace');
    });

    Route::post('spreadsheets/{spreadsheet}/import', [SpreadsheetController::class, 'import'])->name('spreadsheets.import');
    Route::get('spreadsheets/{spreadsheet}/import/batches/{batchId}', [SpreadsheetController::class, 'importBatchStatus'])->name('spreadsheets.import.batch-status');
    Route::get('spreadsheets/{spreadsheet}/export/workbook/{format}', [SpreadsheetController::class, 'exportWorkbook'])->whereIn('format', ['xlsx', 'ods'])->middleware('throttle:20,1')->name('spreadsheets.export.workbook');
    Route::get('spreadsheets/{spreadsheet}/export/pdf', [SpreadsheetController::class, 'exportPdf'])->middleware('throttle:20,1')->name('spreadsheets.export.pdf');
    Route::get('spreadsheets/{spreadsheet}/export/tsv', [SpreadsheetController::class, 'exportTsv'])->name('spreadsheets.export.tsv');
    Route::get('spreadsheets/{spreadsheet}/print-sheet', [SpreadsheetController::class, 'printSheet'])->middleware('throttle:60,1')->name('spreadsheets.print-sheet');
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

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
