<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\Spreadsheet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes down who did what to a workbook: who it was shared with, what was taken out of it, what was decided. Named by
 * route, so the words are in one place and both editors are covered. Cell edits are not listed: each sheet keeps its
 * own copies. A record that cannot be written never stops the work.
 */
class RecordWorkbookAction
{
    /** route name => [what happened, the request fields worth keeping] */
    private const ACTIONS = [
        'grid.sharing.share' => ['Shared with someone', ['email', 'permission']],
        'grid.sharing.change' => ['Changed what someone can do', ['permission']],
        'grid.sharing.remove' => ['Took away someone\'s access', []],
        'grid.sharing.cancel' => ['Cancelled an invitation', []],
        'grid.sharing.link' => ['Changed the link for anyone', ['enabled']],
        'grid.sharing.link.new' => ['Made a new link', []],
        'grid.sharing.place' => ['Moved it', ['team_id']],
        'grid.sharing.template' => ['Changed whether it is a team template', ['on']],
        'grid.protections.store' => ['Protected cells', ['range']],
        'grid.protections.destroy' => ['Lifted a protection', []],
        'grid.approval.ask' => ['Asked for approval', []],
        'grid.approval.decide' => ['Decided on approval', ['approve']],
        'grid.approval.reopen' => ['Reopened it', []],
        'grid.approval.withdraw' => ['Took back an approval request', []],
        'grid.restore' => ['Restored an earlier copy', []],
        'grid.remove' => ['Removed a sheet', []],
        'grid.filed' => ['Saved it to Dot.Files', []],
        'spreadsheets.import' => ['Imported a file', []],
        'spreadsheets.destroy' => ['Deleted it', []],
        'spreadsheets.duplicate' => ['Made a copy of it', []],
        'spreadsheets.export.workbook' => ['Downloaded it', []],
        'spreadsheets.export.pdf' => ['Downloaded a PDF', []],
        'spreadsheets.export.csv' => ['Downloaded a sheet as CSV', []],
        'spreadsheets.export.tsv' => ['Downloaded a sheet as TSV', []],
        'spreadsheets.export.excel' => ['Downloaded a sheet as Excel', []],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $action = self::ACTIONS[(string) $request->route()?->getName()] ?? null;
        $spreadsheet = $request->route('spreadsheet');
        // A delete leaves only the model this request already loaded, so what it names is read now.
        $before = $action !== null && $spreadsheet instanceof Spreadsheet ? [$spreadsheet->team_id, $spreadsheet->name, $spreadsheet->owner_id, $spreadsheet->id] : null;

        $response = $next($request);

        if ($before !== null && $response->getStatusCode() < 400 && ! $this->wasRefused($request, $response)) {
            $this->record($request, $action, $before);
        }

        return $response;
    }

    /** A form that failed goes back with an error rather than an error status. */
    private function wasRefused(Request $request, Response $response): bool
    {
        return $response->isRedirection() && $request->hasSession() && $request->session()->has(['error']);
    }

    /**
     * @param  array{0: string, 1: list<string>}  $action
     * @param  array{0: int|null, 1: string, 2: int, 3: int}  $workbook
     */
    private function record(Request $request, array $action, array $workbook): void
    {
        try {
            $detail = collect(Arr::only($request->all(), $action[1]))
                ->map(fn ($value, $key) => $key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : 'none')))
                ->implode(', ');

            AuditEvent::create([
                'team_id' => $workbook[0],
                'spreadsheet_name' => mb_substr($workbook[1], 0, 255),
                'owner_id' => $workbook[2],
                'spreadsheet_id' => $workbook[3],
                'user_id' => $request->user()?->id,
                'user_name' => mb_substr((string) $request->user()?->name, 0, 255),
                'action' => $action[0],
                'detail' => $detail === '' ? null : mb_substr($detail, 0, 300),
                'ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
