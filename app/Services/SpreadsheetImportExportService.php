<?php

namespace App\Services;

use App\Jobs\ImportRowsChunkJob;
use App\Models\Cell;
use App\Models\Spreadsheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

class SpreadsheetImportExportService
{
    /**
     * Export spreadsheet to CSV string
     */
    public function exportCsv(Spreadsheet $spreadsheet): string
    {
        $cells = Cell::where('spreadsheet_id', $spreadsheet->id)
            ->orderBy('row_index')
            ->orderBy('col_index')
            ->get();

        if ($cells->isEmpty()) {
            return '';
        }

        $maxRow = $cells->max('row_index');
        $maxCol = $cells->max('col_index');

        // Build 2D grid
        $grid = [];
        foreach ($cells as $cell) {
            $grid[$cell->row_index][$cell->col_index] = $cell->computed_value ?? $cell->raw_value ?? '';
        }

        // Generate CSV
        $output = fopen('php://temp', 'r+');

        for ($row = 1; $row <= $maxRow; $row++) {
            $rowData = [];
            for ($col = 1; $col <= $maxCol; $col++) {
                $rowData[] = $grid[$row][$col] ?? '';
            }
            fputcsv($output, $rowData);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export spreadsheet to Excel file (returns file path)
     */
    public function exportExcel(Spreadsheet $spreadsheet): string
    {
        $cells = Cell::where('spreadsheet_id', $spreadsheet->id)
            ->orderBy('row_index')
            ->orderBy('col_index')
            ->get();

        $maxRow = $cells->max('row_index') ?? 1;
        $maxCol = $cells->max('col_index') ?? 1;

        $grid = [];
        foreach ($cells as $cell) {
            $grid[$cell->row_index][$cell->col_index] = $cell->computed_value ?? $cell->raw_value ?? '';
        }

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $rowData = [];
            for ($col = 1; $col <= $maxCol; $col++) {
                $rowData[] = $grid[$row][$col] ?? '';
            }
            $rows[] = $rowData;
        }

        $fileName = 'spreadsheet_'.$spreadsheet->id.'_'.now()->format('YmdHis').'.xlsx';
        $filePath = 'exports/'.$fileName;

        Excel::store(new class($rows) implements FromArray
        {
            public function __construct(private array $data) {}

            public function array(): array
            {
                return $this->data;
            }
        }, $filePath, 'local');

        return storage_path('app/'.$filePath);
    }

    /**
     * Import from CSV content string into a spreadsheet
     */
    public function importCsv(Spreadsheet $spreadsheet, string $csvContent): array
    {
        $rows = array_map('str_getcsv', explode("\n", trim($csvContent)));

        return $this->insertRows($spreadsheet, $rows);
    }

    /**
     * Import from uploaded CSV/Excel file
     */
    public function importFile(Spreadsheet $spreadsheet, UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $rows = null;

        if ($ext === 'csv') {
            $content = file_get_contents($file->getRealPath());
            $rows = array_map('str_getcsv', explode("\n", trim((string) $content)));
        }

        if (in_array($ext, ['xlsx', 'xls'])) {
            try {
                $data = Excel::toArray(new class implements ToArray
                {
                    public function toArray(array $array): array
                    {
                        return $array;
                    }
                }, $file);
                $rows = $data[0] ?? [];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => 'Excel import failed: '.$e->getMessage()];
            }
        }

        if ($rows === null) {
            return ['success' => false, 'error' => 'Unsupported file type: '.$ext];
        }

        $effectiveRows = count(array_filter($rows, fn ($row) => ! empty(array_filter((array) $row, fn ($v) => $v !== null && $v !== ''))));
        $largeImportThreshold = 1500;
        if ($effectiveRows >= $largeImportThreshold) {
            $chunkSize = 500;
            $chunks = array_chunk($rows, $chunkSize);
            $jobs = [];
            foreach ($chunks as $index => $chunk) {
                $jobs[] = new ImportRowsChunkJob(
                    spreadsheetId: $spreadsheet->id,
                    rows: $chunk,
                    startRowIndex: ($index * $chunkSize) + 1,
                );
            }

            $batch = Bus::batch($jobs)
                ->name("import:spreadsheet:{$spreadsheet->id}")
                ->allowFailures()
                ->dispatch();

            return [
                'success' => true,
                'queued' => true,
                'batch_id' => $batch->id,
                'rows' => $effectiveRows,
                'chunks' => count($chunks),
                'message' => 'Large import queued in background jobs.',
            ];
        }

        return $this->insertRows($spreadsheet, $rows);
    }

    /**
     * Import from Excel file using maatwebsite/excel
     */
    protected function importExcel(Spreadsheet $spreadsheet, UploadedFile $file): array
    {
        try {
            $data = Excel::toArray(new class implements ToArray
            {
                public function toArray(array $array): array
                {
                    return $array;
                }
            }, $file);

            $rows = $data[0] ?? [];

            return $this->insertRows($spreadsheet, $rows);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Excel import failed: '.$e->getMessage()];
        }
    }

    /**
     * Insert rows into spreadsheet cells
     */
    protected function insertRows(Spreadsheet $spreadsheet, array $rows): array
    {
        $rowCount = 0;
        $cellCount = 0;

        DB::beginTransaction();

        try {
            foreach ($rows as $rowIndex => $row) {
                if (empty(array_filter((array) $row, fn ($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                $rowNumber = $rowIndex + 1;
                $rowCount++;

                foreach ((array) $row as $colIndex => $value) {
                    $value = (string) ($value ?? '');

                    if ($value === '') {
                        continue;
                    }

                    $isFormula = str_starts_with($value, '=');

                    Cell::updateOrCreate(
                        [
                            'spreadsheet_id' => $spreadsheet->id,
                            'row_index' => $rowNumber,
                            'col_index' => $colIndex + 1,
                        ],
                        [
                            'raw_value' => $value,
                            'computed_value' => $isFormula ? null : $value,
                            'formula' => $isFormula ? $value : null,
                        ]
                    );

                    $cellCount++;
                }
            }

            DB::commit();

            return [
                'success' => true,
                'rows' => $rowCount,
                'cells' => $cellCount,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
