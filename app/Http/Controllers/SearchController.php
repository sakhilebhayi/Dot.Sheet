<?php

namespace App\Http\Controllers;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search's server half: the spreadsheets and sheet tabs a person can reach, by name.
 *
 * Reach is exactly what the dashboard lists (SpreadsheetController::index): their own workbooks, workbooks shared
 * with them and their teams' workbooks. Nothing outside that comes back, whatever the query says. The query is
 * bound as a parameter (never concatenated), its LIKE wildcards are escaped (with an explicit ESCAPE clause, which SQLite needs), and the answer is capped.
 */
class SearchController extends Controller
{
    private const MIN_LENGTH = 2;

    private const MAX_LENGTH = 80;

    private const SPREADSHEETS = 6;

    private const TABS = 4;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $q = mb_substr($q, 0, self::MAX_LENGTH);

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return response()->json(['query' => $q, 'spreadsheets' => [], 'tabs' => []]);
        }

        // `!` is the escape character: SQLite has none by default, so every LIKE here names it.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
        $user = $request->user();
        $teamIds = $user->allTeams()->pluck('id');

        $reachable = fn ($query) => $query->where(function ($inner) use ($user, $teamIds) {
            $inner->where('owner_id', $user->id)
                ->orWhereHas('sharedUsers', fn ($shared) => $shared->where('user_id', $user->id))
                ->orWhereIn('team_id', $teamIds);
        });

        $spreadsheets = $reachable(Spreadsheet::query())
            ->whereRaw("name like ? escape '!'", [$like])
            ->orderBy('name')
            ->limit(self::SPREADSHEETS)
            ->get(['id', 'name']);

        $tabs = SheetTab::query()
            ->where('hidden', false)
            ->whereRaw("name like ? escape '!'", [$like])
            ->whereHas('spreadsheet', fn ($query) => $reachable($query))
            ->orderBy('name')
            ->limit(self::TABS)
            ->get(['id', 'spreadsheet_id', 'name']);

        // The workbooks' names for the tabs, read from the same reach (the tabs above were found through it).
        $books = Spreadsheet::query()->whereIn('id', $tabs->pluck('spreadsheet_id'))->pluck('name', 'id');

        // The workbooks' names for the tabs, read from the same reach (the tabs above were found through it).
        $books = Spreadsheet::query()->whereIn('id', $tabs->pluck('spreadsheet_id'))->pluck('name', 'id');

        return response()->json([
            'query' => $q,
            'spreadsheets' => $spreadsheets->map(fn (Spreadsheet $sheet) => [
                'label' => $sheet->name,
                'href' => route('spreadsheets.show', $sheet),
            ])->all(),
            'tabs' => $tabs->map(fn (SheetTab $tab) => [
                'label' => $tab->name,
                'in' => $books[$tab->spreadsheet_id],
                'href' => route('spreadsheets.show', $tab->spreadsheet_id).'?tab='.$tab->id,
            ])->all(),
        ]);
    }
}
