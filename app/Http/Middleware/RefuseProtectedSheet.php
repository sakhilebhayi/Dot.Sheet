<?php

namespace App\Http\Middleware;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\ProtectionRefused;
use App\Services\SheetProtections;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sits in front of every change to a sheet, so a route added later is covered without anyone remembering.
 * What may still happen on a protected sheet is named here: talking about it, and keeping a copy of it.
 */
class RefuseProtectedSheet
{
    private const STILL_ALLOWED = ['grid.comments.*', 'grid.copies.store', 'grid.duplicate', 'grid.protections.*'];

    public function __construct(private SheetProtections $protections) {}

    public function handle(Request $request, Closure $next): Response
    {
        $spreadsheet = $request->route('spreadsheet');
        $tab = $request->route('sheetTab');
        $user = $request->user();

        // Someone who cannot edit at all is the controller's to refuse, and is told nothing about protection.
        if ($request->isMethodSafe() || $request->routeIs(...self::STILL_ALLOWED) || ! $spreadsheet instanceof Spreadsheet
            || ! $tab instanceof SheetTab || $tab->spreadsheet_id !== $spreadsheet->id || $user === null || ! $user->can('update', $spreadsheet)) {
            return $next($request);
        }

        try {
            $this->protections->refuseSheet($spreadsheet, $tab->id, $user);
        } catch (ProtectionRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $next($request);
    }
}
