<?php

namespace App\Services;

use App\Jobs\ImportRowsChunkJob;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\Recalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SpreadsheetImportExportService
{
    public const UNREADABLE_MESSAGE = 'That file could not be read. Check that it opens, then try again.';

    public const NOT_FINISHED_MESSAGE = 'The import did not finish, so nothing changed. Try again.';

    public const MAX_ROWS_PER_CHUNK = 500;

    public const QUEUED_MESSAGE = 'This file is large, so it is being imported in the background. You can keep working; the sheet reloads when it is done.';

    /**
     * Export spreadsheet to CSV string
     *
     * Rows and columns are 0-based, like everywhere else in the editor, so the first row and
     * the first column of the sheet are part of the file.
     */
    public function exportCsv(Spreadsheet $spreadsheet, ?SheetTab $sheetTab = null): string
    {
        $sheetTab ??= $spreadsheet->defaultSheetTab();

        $bounds = $this->exportBounds($spreadsheet, $sheetTab);
        if ($bounds === null) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        foreach ($this->exportRows($spreadsheet, $sheetTab, $bounds) as $rowData) {
            // fputcsv's default escape character is deprecated in PHP 8.4; '' selects RFC 4180 quoting.
            fputcsv($output, array_map($this->neutraliseSpreadsheetFormula(...), $rowData), ',', '"', '');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export spreadsheet to Excel file (returns file path)
     *
     * @throws ExportTooLargeException when the sheet has more cells than Excel export can build
     *                                 within a shared host's memory limit; CSV has no such limit.
     */
    public function exportExcel(Spreadsheet $spreadsheet, ?SheetTab $sheetTab = null): string
    {
        $sheetTab ??= $spreadsheet->defaultSheetTab();

        $limit = (int) config('sheet.export_excel_max_cells');
        $cellCount = Cell::where('spreadsheet_id', $spreadsheet->id)->where('sheet_tab_id', $sheetTab?->id)->count();
        if ($limit > 0 && $cellCount > $limit) {
            throw ExportTooLargeException::forCells($cellCount, $limit);
        }

        $bounds = $this->exportBounds($spreadsheet, $sheetTab);
        if ($bounds !== null && $limit > 0 && ($bounds['maxRow'] + 1) * ($bounds['maxCol'] + 1) > $limit) {
            throw ExportTooLargeException::forGrid($bounds['maxRow'] + 1, $bounds['maxCol'] + 1, $limit);
        }

        $rows = $bounds === null ? [[]] : iterator_to_array($this->exportRows($spreadsheet, $sheetTab, $bounds), false);

        $fileName = 'spreadsheet_'.$spreadsheet->id.'_'.now()->format('YmdHis').'_'.Str::random(8).'.xlsx';
        $filePath = 'exports/'.$fileName;

        $workbook = new Workbook;
        $sheet = $workbook->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                if ($value === '') {
                    continue;   // a blank stays an empty cell
                }

                // Values go in as values: text that starts with "=" must not become a formula, and the
                // sheet's formulas are exported as their computed result. Plain numbers become numbers;
                // text with leading zeros ("007") stays text.
                $isPlainNumber = preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,15})?$/', $value) === 1 && $value !== '-0';

                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($colIndex + 1).($rowIndex + 1),
                    $isPlainNumber ? (str_contains($value, '.') ? (float) $value : (int) $value) : $value,
                    $isPlainNumber ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                );
            }
        }

        $frozenRows = (int) ($sheetTab?->layout['frozen_rows'] ?? 0);
        $frozenCols = (int) ($sheetTab?->layout['frozen_cols'] ?? 0);
        if ($frozenRows > 0 || $frozenCols > 0) {
            $sheet->freezePane(Coordinate::stringFromColumnIndex($frozenCols + 1).($frozenRows + 1));
        }

        foreach (SheetMerges::of($sheetTab?->layout) as $merge) {
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($merge['c1'] + 1).($merge['r1'] + 1).':'.Coordinate::stringFromColumnIndex($merge['c2'] + 1).($merge['r2'] + 1));
        }

        Storage::disk('local')->makeDirectory('exports');
        (new Xlsx($workbook))->save(Storage::disk('local')->path($filePath));
        $workbook->disconnectWorksheets();

        // The disk's own root: Laravel 11+ points "local" at storage/app/private, not storage/app.
        return Storage::disk('local')->path($filePath);
    }

    /**
     * The last used row and column of the tab, or null when it has no cells.
     *
     * @return array{maxRow: int, maxCol: int}|null
     */
    protected function exportBounds(Spreadsheet $spreadsheet, ?SheetTab $sheetTab): ?array
    {
        // Results go out as they are stored: work a sheet nobody has opened since it changed out first.
        if ($sheetTab) {
            app(Recalculator::class)->ensureBuilt((int) $spreadsheet->id, (int) $sheetTab->id);
        }

        $query = Cell::where('spreadsheet_id', $spreadsheet->id)->where('sheet_tab_id', $sheetTab?->id);

        $maxRow = $query->max('row_index');
        if ($maxRow === null) {
            return null;
        }

        return ['maxRow' => (int) $maxRow, 'maxCol' => (int) (clone $query)->max('col_index')];
    }

    /**
     * One list of values per row from row 0 to the last used row, read with a cursor so the
     * cells are never all in memory next to the rows built from them.
     *
     * @param  array{maxRow: int, maxCol: int}  $bounds
     * @return \Generator<int, list<string>>
     */
    protected function exportRows(Spreadsheet $spreadsheet, ?SheetTab $sheetTab, array $bounds): \Generator
    {
        $blank = array_fill(0, $bounds['maxCol'] + 1, '');
        $currentRow = null;
        $current = $blank;

        $cells = Cell::where('spreadsheet_id', $spreadsheet->id)
            ->where('sheet_tab_id', $sheetTab?->id)
            ->orderBy('row_index')
            ->orderBy('col_index')
            ->select(['row_index', 'col_index', 'raw_value', 'computed_value'])
            ->cursor();

        foreach ($cells as $cell) {
            $row = (int) $cell->row_index;

            if ($row !== $currentRow) {
                if ($currentRow !== null) {
                    yield $current;
                }
                for ($gap = ($currentRow ?? -1) + 1; $gap < $row; $gap++) {
                    yield $blank;
                }
                $currentRow = $row;
                $current = $blank;
            }

            $current[(int) $cell->col_index] = (string) ($cell->computed_value ?? $cell->raw_value ?? '');
        }

        if ($currentRow !== null) {
            yield $current;
        }
    }

    /**
     * A CSV cell that starts with = + - @ (or a tab or carriage return) is run as a formula
     * when the file is opened in Excel or Sheets. Prefix those with an apostrophe, the way
     * both of them show text that must not be evaluated. Numbers and number-like text such as
     * a phone number ("+27 82 123 4567") are left alone.
     */
    protected function neutraliseSpreadsheetFormula(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        // Text that already starts with an apostrophe and then one of the characters below gets
        // one more, so importCsv() removing exactly one gives the original back.
        if (preg_match('/^\'+[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        if (preg_match('/^[=@\t\r]/', $value)) {
            return "'".$value;
        }

        if (preg_match('/^[+\-]/', $value) && ! preg_match('/^[+\-]?[\d\s().,+\-]+$/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Whether an imported value is a cell at all (empty strings and nulls are not written).
     */
    public static function importedCellHasValue(mixed $value): bool
    {
        if (is_array($value)) {
            $value = $value['text'] ?? '';
        }

        return (string) ($value ?? '') !== '';
    }

    /**
     * What to store for one imported value, or null for an empty one. A value starting with "="
     * is a formula unless the importer says the file holds values only ($valuesAreText) or the
     * cell is marked as text (['text' => value], see parseCsv()).
     *
     * @return array{raw_value: string, computed_value: string|null, formula: string|null, updated_at: Carbon}|null
     */
    public static function importedCellAttributes(mixed $value, bool $valuesAreText = false): ?array
    {
        if (! self::importedCellHasValue($value)) {
            return null;
        }

        if (is_array($value)) {
            $valuesAreText = true;
            $value = $value['text'];
        }

        $value = (string) $value;

        $isFormula = ! $valuesAreText && str_starts_with($value, '=');

        return [
            'raw_value' => $value,
            'computed_value' => $isFormula ? null : $value,
            'formula' => $isFormula ? $value : null,
            'updated_at' => now(),
        ];
    }

    /**
     * Parse CSV text into rows. Quoted fields may hold commas, quotes and line breaks, and
     * both \n and \r\n end a row (splitting on "\n" left a stray "\r" on every last field).
     *
     * @return list<list<string|array{text: string}|null>> a cell that must stay text is ['text' => value]
     */
    protected function parseCsv(string $csvContent, string $separator = ','): array
    {
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, null, $separator, '"', '')) !== false) {
            if ($row === [null]) {
                $row = [];
            }
            // Undo the apostrophe exportCsv() put in front of text Excel would run as a formula.
            // The cell comes back as text, not as a formula.
            $rows[] = array_map(
                fn ($field) => is_string($field) && preg_match('/^\'+[=+\-@\t\r]/', $field) === 1 ? ['text' => substr($field, 1)] : $field,
                $row
            );
        }
        fclose($stream);

        return $rows;
    }

    /**
     * Import from CSV content string into a spreadsheet
     */
    public function importCsv(Spreadsheet $spreadsheet, string $csvContent, ?SheetTab $sheetTab = null): array
    {
        $rows = $this->parseCsv($csvContent);

        return $this->insertRows($spreadsheet, $rows, $sheetTab);
    }

    /**
     * Import from uploaded CSV/Excel file
     */
    public function importFile(Spreadsheet $spreadsheet, UploadedFile $file, ?SheetTab $sheetTab = null): array
    {
        $sheetTab ??= $spreadsheet->defaultSheetTab();

        $ext = strtolower($file->getClientOriginalExtension());
        $rows = null;
        // A cell read from an Excel file stays text, so one that happens to start with "=" is not taken
        // for a formula. So is a real formula, for now: it lands as its own text until S8 reads the type.
        $valuesAreText = false;

        if (in_array($ext, ['csv', 'tsv'], true)) {
            $content = file_get_contents($file->getRealPath());
            $rows = $this->parseCsv((string) $content, $ext === 'tsv' ? "\t" : ',');
        }

        if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            try {
                $rows = $this->readFirstSheet($file);
                $valuesAreText = true;
            } catch (\Exception $e) {
                report($e);

                return ['success' => false, 'error' => self::UNREADABLE_MESSAGE];
            }
        }

        if ($rows === null) {
            return ['success' => false, 'error' => 'Import a CSV or Excel file.'];
        }

        $size = self::measureRows($rows);
        $inlineMaxCells = max(0, (int) config('sheet.import_inline_max_cells'));

        // The write lock is held for about 0.35 ms per CELL, so the size of the inline path is counted
        // in cells: a file of 1,499 rows x 20 columns kept it for 10 seconds, one of 1,499 x 2 does not.
        if ($size['cells'] >= $inlineMaxCells && $size['cells'] > 0) {
            return $this->queueImport($spreadsheet, $sheetTab, $rows, $size, $valuesAreText);
        }

        return $this->insertRows($spreadsheet, $rows, $sheetTab, $valuesAreText);
    }

    /**
     * The first sheet of an Excel file as rows of raw values: a formula is read as its text, not its
     * result, and nothing is formatted. Reading every sheet, formulas and formatting is S8.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function readFirstSheet(UploadedFile $file): array
    {
        // Named outright: left to guess, the library reads a broken workbook as CSV and its junk lands on the sheet.
        $reader = IOFactory::createReaderForFile($file->getRealPath(), [IOFactory::READER_XLSX, IOFactory::READER_XLS, IOFactory::READER_ODS]);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($file->getRealPath());

        try {
            $sheet = $workbook->getSheet(0);

            return $sheet->rangeToArray(
                'A1:'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow(),
                null, false, false, false,
            );
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    /**
     * The non-empty rows and cells of a parsed file, and the most cells in any one row: what the
     * import writes (the same test insertRows() and the chunk job apply), counted without writing.
     *
     * @param  array<int, mixed>  $rows
     * @return array{rows: int, cells: int, widest: int}
     */
    public static function measureRows(array $rows): array
    {
        $rowCount = 0;
        $cellCount = 0;
        $widest = 0;

        foreach ($rows as $row) {
            $inRow = 0;
            foreach ((array) $row as $value) {
                if (self::importedCellHasValue($value)) {
                    $inRow++;
                }
            }
            if ($inRow === 0) {
                continue;
            }
            $rowCount++;
            $cellCount += $inRow;
            $widest = max($widest, $inRow);
        }

        return ['rows' => $rowCount, 'cells' => $cellCount, 'widest' => $widest];
    }

    /**
     * Rows per queued chunk: one chunk is one write transaction, so it holds about
     * sheet.import_chunk_cells cells (the widest row of the file decides), never fewer than one row
     * and never more than 500.
     */
    public static function rowsPerChunk(int $widestRowCells): int
    {
        $budget = max(1, (int) config('sheet.import_chunk_cells'));

        return max(1, min(self::MAX_ROWS_PER_CHUNK, intdiv($budget, max(1, $widestRowCells))));
    }

    /**
     * Split a large file into chunk jobs on a batch. Chunks are consecutive runs of rows of one size
     * (the last one shorter), and each knows the file row it starts at.
     *
     * @param  array<int, mixed>  $rows
     * @param  array{rows: int, cells: int, widest: int}  $size
     */
    protected function queueImport(Spreadsheet $spreadsheet, ?SheetTab $sheetTab, array $rows, array $size, bool $valuesAreText): array
    {
        $chunkRows = self::rowsPerChunk($size['widest']);
        $structureVersion = StructureVersion::current($spreadsheet->id);
        $jobs = [];
        $startRowIndex = 0;
        foreach (array_chunk(array_values($rows), $chunkRows) as $chunk) {
            $jobs[] = new ImportRowsChunkJob(
                spreadsheetId: $spreadsheet->id,
                sheetTabId: $sheetTab?->id,
                rows: $chunk,
                startRowIndex: $startRowIndex,
                valuesAreText: $valuesAreText,
                structureVersion: $structureVersion,
            );
            $startRowIndex += count($chunk);
        }

        $batch = Bus::batch($jobs)
            ->name("import:spreadsheet:{$spreadsheet->id}")
            ->allowFailures()
            ->dispatch();

        // For the status endpoint's "failed after N of M rows".
        Cache::put("import:{$batch->id}:rows", $size['rows'], 86400);
        Cache::put("import:{$batch->id}:chunk_rows", $chunkRows, 86400);

        return [
            'success' => true,
            'queued' => true,
            'batch_id' => $batch->id,
            'rows' => $size['rows'],
            'cells' => $size['cells'],
            'chunks' => count($jobs),
            'message' => self::QUEUED_MESSAGE,
        ];
    }

    /**
     * Import from an Excel file
     */
    protected function importExcel(Spreadsheet $spreadsheet, UploadedFile $file, ?SheetTab $sheetTab = null): array
    {
        try {
            return $this->insertRows($spreadsheet, $this->readFirstSheet($file), $sheetTab);
        } catch (\Exception $e) {
            report($e);

            return ['success' => false, 'error' => self::UNREADABLE_MESSAGE];
        }
    }

    /**
     * Insert rows into spreadsheet cells
     */
    protected function insertRows(Spreadsheet $spreadsheet, array $rows, ?SheetTab $sheetTab = null, bool $valuesAreText = false): array
    {
        $sheetTab ??= $spreadsheet->defaultSheetTab();

        $rowCount = 0;
        $cellCount = 0;

        DB::beginTransaction();

        try {
            foreach ($rows as $rowIndex => $row) {
                if (empty(array_filter((array) $row, fn ($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                // Rows and columns are 0-based, like the editor and the export.
                $rowNumber = $rowIndex;
                $rowCount++;

                foreach ((array) $row as $colIndex => $value) {
                    $attributes = self::importedCellAttributes($value, $valuesAreText);
                    if ($attributes === null) {
                        continue;
                    }

                    Cell::updateOrCreate(
                        [
                            'spreadsheet_id' => $spreadsheet->id,
                            'sheet_tab_id' => $sheetTab?->id,
                            'row_index' => $rowNumber,
                            'col_index' => $colIndex,
                        ],
                        $attributes
                    );

                    $cellCount++;
                }
            }

            // Imported formulas are worked out when the sheet is next shown.
            if ($sheetTab) {
                app(Recalculator::class)->markStale((int) $spreadsheet->id, (int) $sheetTab->id);
            }

            DB::commit();

            return [
                'success' => true,
                'rows' => $rowCount,
                'cells' => $cellCount,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return ['success' => false, 'error' => WriteLock::isTimeout($e) ? WriteLock::BUSY_MESSAGE : self::NOT_FINISHED_MESSAGE];
        }
    }
}
