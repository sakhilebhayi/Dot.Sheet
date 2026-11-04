<?php

namespace App\Http\Middleware;

use App\Models\Spreadsheet;
use App\Services\ProtectionRefused;
use App\Services\SheetProtections;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** For a change to a workbook that does not go through one of its sheets, such as adding a sheet. */
class RefuseSealedWorkbook
{
    public function __construct(private SheetProtections $protections) {}

    public function handle(Request $request, Closure $next): Response
    {
        $spreadsheet = $request->route('spreadsheet');

        if ($request->isMethodSafe() || ! $spreadsheet instanceof Spreadsheet) {
            return $next($request);
        }

        try {
            $this->protections->refuseSealed($spreadsheet);
        } catch (ProtectionRefused $refused) {
            return $request->expectsJson()
                ? response()->json(['message' => $refused->getMessage()], 422)
                : back()->with('error', $refused->getMessage());
        }

        return $next($request);
    }
}
