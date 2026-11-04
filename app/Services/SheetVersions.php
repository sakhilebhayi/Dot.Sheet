<?php

namespace App\Services;

use App\Models\SheetTab;
use App\Models\SpreadsheetVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies of one sheet: taking them, putting one back, and keeping their number in check.
 *
 * A copy holds the sheet's cells, its layout, and where its comments were. Restoring first takes a safety copy of the sheet as it
 * is, so a restore can itself be undone, then changes only the cells that differ: cells that stay keep
 * their edit history, and each changed value is recorded in it. The work reads plain rows and writes in
 * chunks, so a large sheet does not hold thousands of models in memory.
 */
class SheetVersions
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    public const SAFETY = 'safety';

    /** Automatic copies kept per sheet; older ones are removed. Copies people made are never removed. */
    public const KEEP_AUTO = 30;

    /** Safety copies kept per sheet. */
    public const KEEP_SAFETY = 10;

    /** Largest sheet copied automatically. */
    public const AUTO_MAX_CELLS = 20000;

    private const CHUNK = 500;

    /** The columns a snapshot holds for each cell. */
    private const CELL_COLUMNS = ['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formatting'];

    public function snapshot(int $spreadsheetId, int $sheetTabId, ?int $userId, string $kind, string $label): SpreadsheetVersion
    {
        $cells = [];
        // Cells a formula's answer landed on are not content: the formula puts them back.
        foreach ($this->cells($spreadsheetId, $sheetTabId)->whereNull('spill_row')->orderBy('row_index')->orderBy('col_index')->lazy(1000) as $cell) {
            $cells[] = $this->snapshotCell($cell);
        }

        $version = SpreadsheetVersion::create([
            'spreadsheet_id' => $spreadsheetId,
            'sheet_tab_id' => $sheetTabId,
            'user_id' => $userId,
            'label' => $label,
            'kind' => $kind,
            'cell_count' => count($cells),
            'layout' => SheetTab::whereKey($sheetTabId)->value('layout'),
            'comment_places' => app(SheetComments::class)->placesOf($sheetTabId),
            'cells_snapshot' => $cells,
        ]);

        $this->prune($sheetTabId);

        return $version;
    }

    /**
     * The automatic copy, at most one every five minutes. A sheet too large to copy in memory is skipped
     * and the log says so; a manual copy is still possible.
     */
    public function autoSnapshot(int $spreadsheetId, int $sheetTabId, ?int $userId): void
    {
        $latest = SpreadsheetVersion::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId)->latest()->first();
        if ($latest?->created_at?->gt(now()->subMinutes(5))) {
            return;
        }

        $count = $this->cells($spreadsheetId, $sheetTabId)->count();
        if ($count > self::AUTO_MAX_CELLS) {
            Log::notice('Auto snapshot skipped: tab too large', ['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'cells' => $count, 'limit' => self::AUTO_MAX_CELLS]);

            return;
        }

        $this->snapshot($spreadsheetId, $sheetTabId, $userId, self::AUTO, 'Auto Snapshot');
    }

    /**
     * Put a copy back on its sheet. Run it inside a transaction: the safety copy, the cells, the layout
     * and the structure version change together or not at all.
     */
    public function restore(SpreadsheetVersion $version, int $userId): void
    {
        $spreadsheetId = (int) $version->spreadsheet_id;
        $sheetTabId = (int) $version->sheet_tab_id;

        $this->snapshot($spreadsheetId, $sheetTabId, $userId, self::SAFETY, 'Before restoring “'.$version->label.'” of '.$version->created_at?->format('j M Y H:i'));

        $this->cells($spreadsheetId, $sheetTabId)->whereNotNull('spill_row')->delete();

        $wanted = [];
        foreach ($version->cells_snapshot ?? [] as $cell) {
            $wanted[$cell['row_index'].':'.$cell['col_index']] = $cell;
        }

        $now = now();
        $history = [];
        $gone = [];
        foreach ($this->cells($spreadsheetId, $sheetTabId)->select(['id', ...self::CELL_COLUMNS])->lazyById(1000) as $current) {
            $key = $current->row_index.':'.$current->col_index;
            $target = $wanted[$key] ?? null;

            if ($target === null) {
                $gone[] = $current->id;
            } elseif ($this->differs($current, $target)) {
                if ((string) $current->raw_value !== (string) ($target['raw_value'] ?? '')) {
                    $history[] = ['cell_id' => $current->id, 'user_id' => $userId, 'old_value' => $current->raw_value, 'new_value' => $target['raw_value'] ?? null, 'created_at' => $now];
                }
            } else {
                unset($wanted[$key]);   // already as it should be
            }
        }

        foreach (array_chunk($gone, self::CHUNK) as $ids) {
            DB::table('cells')->whereIn('id', $ids)->delete();
        }

        // What is left in $wanted is new or changed: written in place, so a cell keeps its id and history.
        foreach (array_chunk(array_values($wanted), self::CHUNK) as $chunk) {
            DB::table('cells')->upsert(
                array_map(fn (array $cell) => [
                    'spreadsheet_id' => $spreadsheetId,
                    'sheet_tab_id' => $sheetTabId,
                    'row_index' => (int) $cell['row_index'],
                    'col_index' => (int) $cell['col_index'],
                    'raw_value' => $cell['raw_value'] ?? null,
                    'computed_value' => $cell['computed_value'] ?? null,
                    'formula' => $cell['formula'] ?? null,
                    'formatting' => isset($cell['formatting']) ? json_encode($cell['formatting']) : null,
                    'updated_at' => $now,
                ], $chunk),
                ['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index'],
                ['raw_value', 'computed_value', 'formula', 'formatting', 'updated_at'],
            );
        }

        foreach (array_chunk($history, self::CHUNK) as $rows) {
            DB::table('cell_history')->insert($rows);
        }

        // A copy taken before layouts were kept has none: the sheet keeps the layout it has.
        if ($version->layout !== null) {
            SheetTab::updateLayout($sheetTabId, fn () => $version->layout);
        }

        // A copy taken before comments were kept leaves them where they are.
        if ($version->comment_places !== null) {
            app(SheetComments::class)->restorePlaces($sheetTabId, $version->comment_places);
        }

        // Every cell may have moved: editors that had the old grid open must reload, and the formulas
        // (here and on sheets that read this one) are worked out again on the next read.
        StructureVersion::bump($spreadsheetId);
        app(Formula\Recalculator::class)->markStale($spreadsheetId, $sheetTabId);
    }

    /**
     * Keep the newest automatic and safety copies of a sheet; remove the rest. Returns how many went.
     */
    public function prune(int $sheetTabId): int
    {
        $removed = 0;
        foreach ([self::AUTO => self::KEEP_AUTO, self::SAFETY => self::KEEP_SAFETY] as $kind => $keep) {
            $kept = SpreadsheetVersion::where('sheet_tab_id', $sheetTabId)->where('kind', $kind)
                ->orderByDesc('created_at')->orderByDesc('id')->limit($keep)->pluck('id');

            $removed += SpreadsheetVersion::where('sheet_tab_id', $sheetTabId)->where('kind', $kind)
                ->whereNotIn('id', $kept)->delete();
        }

        return $removed;
    }

    private function cells(int $spreadsheetId, int $sheetTabId)
    {
        return DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotCell(object $cell): array
    {
        $row = [];
        foreach (self::CELL_COLUMNS as $column) {
            $row[$column] = $cell->{$column};
        }
        $row['row_index'] = (int) $row['row_index'];
        $row['col_index'] = (int) $row['col_index'];
        $row['formatting'] = $row['formatting'] !== null ? json_decode((string) $row['formatting'], true) : null;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function differs(object $current, array $target): bool
    {
        $now = $this->snapshotCell($current);

        foreach (['raw_value', 'computed_value', 'formula', 'formatting'] as $column) {
            if ($now[$column] != ($target[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
