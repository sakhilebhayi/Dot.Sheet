<?php

use App\Http\Controllers\Auth\EcosystemAuthController;

use App\Http\Controllers\SpreadsheetController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('auth.ecosystem');

Route::get('/', function () {
    return view('welcome');
});


Route::get('shared/s/{token}', [SpreadsheetController::class, 'publicShow'])->name('spreadsheets.public');


Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();
        $spreadsheets = \App\Models\Spreadsheet::where('owner_id', $user->id)
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
    Route::get('spreadsheets/{spreadsheet}/export/csv',   [SpreadsheetController::class, 'exportCsv'])->name('spreadsheets.export.csv');
    Route::get('spreadsheets/{spreadsheet}/export/excel', [SpreadsheetController::class, 'exportExcel'])->name('spreadsheets.export.excel');

    // Invitations
    Route::get('spreadsheets/invitations/{token}/accept', [SpreadsheetController::class, 'acceptInvitation'])
        ->name('spreadsheets.invitations.accept');
});
