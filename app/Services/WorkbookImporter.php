<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Formula\Recalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns what WorkbookReader found into a workbook, and writes down what came across and what did not.
 * That report stays with the workbook, so it can be read again.
 */
class WorkbookImporter
{
    private const CHUNK = 500;

    /** @var array<string, array{0: string, 1: string}> what a count means: singular, plural */
    private const LOST = [
        'charts' => ['a chart', 'charts'],
        'images' => ['a picture', 'pictures'],
        'pivots' => ['a pivot table', 'pivot tables'],
        'macros' => ['macros', 'macros'],
        'links' => ['a link in a cell', 'links in cells'],
        'external' => ['a formula that reads another file', 'formulas that read other files'],
        'validation' => ['a rule about allowed values', 'rules about allowed values'],
        'rules' => ['a colour rule', 'colour rules'],
        'names' => ['a name for something other than cells', 'names for something other than cells'],
    ];

    private const WHY = [
        'charts' => 'Add it again from Charts.',
        'images' => 'Pictures are not kept in a sheet here.',
        'pivots' => 'Build it again from Pivot tables.',
        'macros' => 'Macros from Excel do not run here. Use Macros in the palette for repeated steps.',
        'links' => 'The text stayed and the link did not.',
        'external' => 'Each keeps its last answer as a plain value.',
        'validation' => 'Only typed lists and number ranges come across.',
        'rules' => 'Only rules comparing a cell with a number come across.',
        'names' => 'Only names for a range of cells come across.',
    ];

    public function __construct(private WorkbookReader $reader, private WorkbookSheets $sheets) {}

    /**
     * Whether the file is small enough to read whole in one request on a shared host.
     */
    public function fits(string $path, string $extension): bool
    {
        $limit = (int) config('sheet.import_whole_max_cells');

        return $limit === 0 || $this->reader->size($path, $extension) <= $limit;
    }

    public function import(string $path, string $extension, string $name, User $user, ?int $teamId): Spreadsheet
    {
        $found = $this->reader->read($path, $extension);

        return DB::transaction(function () use ($found, $name, $user, $teamId) {
            $spreadsheet = new Spreadsheet(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $teamId, 'name' => $name, 'settings' => []]);
            $spreadsheet->saveQuietly();

            $tabs = [];
            $cells = 0;
            $formulas = 0;
            foreach ($found['sheets'] as $order => $sheet) {
                $tab = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => $this->sheetName($sheet['name'], $spreadsheet->id), 'sort_order' => $order, 'color' => $sheet['color'], 'hidden' => $sheet['hidden']]);
                $tabs[$sheet['name']] = $tab;

                $this->writeCells($spreadsheet->id, $tab->id, $sheet['cells']);
                $cells += count($sheet['cells']);
                $formulas += count(array_filter($sheet['cells'], fn (array $cell) => $cell['formula'] !== null));

                SheetTab::updateLayout($tab->id, fn (array $layout) => [...$layout, ...$sheet['layout']]);
                $this->writeComments($spreadsheet->id, $tab->id, $sheet['comments'], $user->id);
            }

            // A workbook keeps one sheet showing, whatever the file did.
            if (collect($tabs)->every(fn (SheetTab $tab) => $tab->hidden)) {
                reset($tabs);
                current($tabs)->update(['hidden' => false]);
            }

            $kept = $this->writeNames($spreadsheet->id, $found['names'], $tabs);

            $spreadsheet->settings = ['import_report' => $this->report(count($tabs), $cells, $formulas, $kept, $found)];
            $spreadsheet->saveQuietly();

            return $spreadsheet;
        });
    }

    /**
     * The first sheet of a file, written over a sheet from A1 with its formulas and formatting. Cells
     * outside the file stay, as they do for any import.
     *
     * @return array{success: true, rows: int, cells: int, other_sheets: int, left: list<array{what: string, why: string}>}
     */
    public function intoSheet(string $path, string $extension, Spreadsheet $spreadsheet, SheetTab $tab): array
    {
        $found = $this->reader->read($path, $extension);
        $sheet = $found['sheets'][0];

        DB::transaction(function () use ($sheet, $spreadsheet, $tab) {
            $now = now();
            foreach (array_chunk($sheet['cells'], self::CHUNK) as $chunk) {
                DB::table('cells')->upsert(array_map(fn (array $cell) => [
                    'spreadsheet_id' => $spreadsheet->id,
                    'sheet_tab_id' => $tab->id,
                    'row_index' => $cell['r'],
                    'col_index' => $cell['c'],
                    'raw_value' => $cell['value'],
                    'computed_value' => null,
                    'formula' => $cell['formula'],
                    'formatting' => $cell['x'] === null ? null : json_encode($cell['x']),
                    'updated_at' => $now,
                ], $chunk), ['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index'], ['raw_value', 'computed_value', 'formula', 'formatting', 'updated_at']);
            }
            $widths = $sheet['layout']['col_widths'] ?? [];
            SheetTab::updateLayout($tab->id, fn (array $layout) => [...$layout, 'col_widths' => array_replace($layout['col_widths'] ?? [], $widths)]);
            app(Recalculator::class)->markWorkbookStale($spreadsheet->id);
        });

        return [
            'success' => true,
            'rows' => count(array_unique(array_column($sheet['cells'], 'r'))),
            'cells' => count($sheet['cells']),
            'other_sheets' => count($found['sheets']) - 1,
            'left' => $this->report(1, 0, 0, 0, $found)['left'],
        ];
    }

    /**
     * @param  array<string, mixed>  $found
     * @return array{sheets: int, cells: int, formulas: int, names: int, left: list<array{what: string, why: string}>, unknown: list<string>}
     */
    private function report(int $sheets, int $cells, int $formulas, int $names, array $found): array
    {
        $left = [];
        foreach (self::LOST as $key => [$one, $many]) {
            $count = $found['lost'][$key] ?? 0;
            if ($count > 0) {
                $left[] = ['what' => $count === 1 ? $one : (in_array($key, ['macros'], true) ? $many : "{$count} {$many}"), 'why' => self::WHY[$key]];
            }
        }

        return ['sheets' => $sheets, 'cells' => $cells, 'formulas' => $formulas, 'names' => $names, 'left' => $left, 'unknown' => $found['unknown']];
    }

    private function sheetName(string $name, int $spreadsheetId): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', '', $name) ?? '', " '");
        $name = mb_substr($name === '' ? 'Sheet' : $name, 0, WorkbookSheets::MAX_NAME);
        $free = $name;
        for ($count = 2; $this->sheets->refusal($spreadsheetId, $free) !== null && $count < 500; $count++) {
            $free = mb_substr($name, 0, WorkbookSheets::MAX_NAME - 4)." {$count}";
        }

        return $free;
    }

    /**
     * @param  list<array{r: int, c: int, value: string, formula: string|null, x: array<string, mixed>|null}>  $cells
     */
    private function writeCells(int $spreadsheetId, int $tabId, array $cells): void
    {
        $now = now();
        foreach (array_chunk($cells, self::CHUNK) as $chunk) {
            DB::table('cells')->insert(array_map(fn (array $cell) => [
                'spreadsheet_id' => $spreadsheetId,
                'sheet_tab_id' => $tabId,
                'row_index' => $cell['r'],
                'col_index' => $cell['c'],
                'raw_value' => $cell['value'],
                'formula' => $cell['formula'],
                'formatting' => $cell['x'] === null ? null : json_encode($cell['x']),
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /**
     * @param  list<array{r: int, c: int, text: string}>  $comments
     */
    private function writeComments(int $spreadsheetId, int $tabId, array $comments, int $userId): void
    {
        foreach ($comments as $comment) {
            Comment::create(['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $tabId, 'user_id' => $userId, 'row_index' => $comment['r'], 'col_index' => $comment['c'], 'content' => mb_substr($comment['text'], 0, 2000)]);
        }
    }

    /**
     * @param  list<array{name: string, sheet: string, range: string}>  $names
     * @param  array<string, SheetTab>  $tabs
     */
    private function writeNames(int $spreadsheetId, array $names, array $tabs): int
    {
        $kept = 0;
        foreach ($names as $named) {
            $area = RangeText::parse($named['range']);
            $tab = $tabs[$named['sheet']] ?? null;
            if ($area === null || $tab === null) {
                continue;
            }
            NamedRange::create(['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $tab->id, 'name' => $named['name'], 'start_row' => $area['r1'], 'start_col' => $area['c1'], 'end_row' => $area['r2'], 'end_col' => $area['c2']]);
            $kept++;
        }

        return $kept;
    }
}
