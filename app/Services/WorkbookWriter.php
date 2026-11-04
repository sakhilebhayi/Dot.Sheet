<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\Recalculator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange as BookName;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Book;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes a whole workbook to a file a person can take elsewhere: every sheet with its formulas, formats,
 * sizes, merges, allowed values, colour rules, comments and names. Formulas go out as formulas. Text that
 * only looks like one goes out as text, so nothing here can run in someone else's spreadsheet.
 */
class WorkbookWriter
{
    private const WRITERS = ['xlsx' => 'Xlsx', 'ods' => 'Ods'];

    private const SYMBOLS = ['ZAR' => 'R', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    private const DATES = ['iso' => 'yyyy-mm-dd', 'long' => 'd mmm yyyy', 'dmy' => 'dd/mm/yyyy'];

    private const COLOURS = ['green' => '1F8A5F', 'plum' => '8B3FA8', 'olive' => '6E7A1E', 'teal' => '0E8AA0', 'amber' => 'B5791A', 'pink' => 'B0498C'];

    private const OPERATORS = ['>' => Conditional::OPERATOR_GREATERTHAN, '>=' => Conditional::OPERATOR_GREATERTHANOREQUAL, '<' => Conditional::OPERATOR_LESSTHAN, '<=' => Conditional::OPERATOR_LESSTHANOREQUAL, '==' => Conditional::OPERATOR_EQUAL, '!=' => Conditional::OPERATOR_NOTEQUAL];

    /**
     * @return string the path of a file the caller sends and removes
     *
     * @throws ExportTooLargeException
     */
    public function write(Spreadsheet $spreadsheet, string $format): string
    {
        $limit = (int) config('sheet.export_excel_max_cells');
        $cells = Cell::where('spreadsheet_id', $spreadsheet->id)->count();
        if ($limit > 0 && $cells > $limit) {
            throw ExportTooLargeException::forCells($cells, $limit);
        }

        $book = $this->book($spreadsheet);
        try {
            $writer = IOFactory::createWriter($book, self::WRITERS[$format]);
            // Each program works its formulas out when it opens the file.
            $writer->setPreCalculateFormulas(false);
            $path = (string) tempnam(sys_get_temp_dir(), 'workbook');
            $writer->save($path);

            return $path;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function book(Spreadsheet $spreadsheet): Book
    {
        $book = new Book;
        $book->removeSheetByIndex(0);

        $tabs = SheetTab::where('spreadsheet_id', $spreadsheet->id)->orderBy('sort_order')->orderBy('id')->get();
        foreach ($tabs as $tab) {
            app(Recalculator::class)->ensureBuilt($spreadsheet->id, $tab->id);
        }

        $sheets = [];
        foreach ($tabs as $tab) {
            $sheet = $book->createSheet();
            $sheet->setTitle((string) $tab->name, false);
            $sheets[$tab->id] = $sheet;
        }
        foreach ($tabs as $tab) {
            $this->fill($sheets[$tab->id], $tab);
        }
        foreach (NamedRange::where('spreadsheet_id', $spreadsheet->id)->get() as $named) {
            $sheet = $sheets[$named->sheet_tab_id] ?? null;
            if ($sheet !== null) {
                $book->addNamedRange(new BookName($named->name, $sheet, '$'.NamedRange::colIndexToLetter($named->start_col).'$'.($named->start_row + 1).':$'.NamedRange::colIndexToLetter($named->end_col).'$'.($named->end_row + 1)));
            }
        }

        $first = $tabs->search(fn (SheetTab $tab) => ! $tab->hidden);
        $book->setActiveSheetIndex($first === false ? 0 : (int) $first);

        return $book;
    }

    private function fill(Worksheet $sheet, SheetTab $tab): void
    {
        $styles = [];
        foreach (Cell::where('sheet_tab_id', $tab->id)->cursor() as $cell) {
            $coordinate = Coordinate::stringFromColumnIndex($cell->col_index + 1).($cell->row_index + 1);
            if ($cell->spill_row !== null) {
                continue;   // an answer spilled from another cell, which works it out again
            }
            if ($cell->formula !== null) {
                $sheet->setCellValueExplicit($coordinate, (string) $cell->formula, DataType::TYPE_FORMULA);
            } elseif ((string) $cell->raw_value !== '') {
                $value = (string) $cell->raw_value;
                $number = preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,15})?$/', $value) === 1 && $value !== '-0';
                $sheet->setCellValueExplicit($coordinate, $number ? (float) $value : $value, $number ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            if (is_array($cell->formatting) && $cell->formatting !== []) {
                $key = (string) json_encode($cell->formatting);
                $styles[$key] ??= $this->style($cell->formatting);
                $sheet->getStyle($coordinate)->applyFromArray($styles[$key]);
            }
        }

        $layout = is_array($tab->layout) ? $tab->layout : [];
        foreach ($layout['col_widths'] ?? [] as $col => $width) {
            $sheet->getColumnDimensionByColumn((int) $col + 1)->setWidth(max(1, ((int) $width - 5) / 7));
        }
        foreach ($layout['row_heights'] ?? [] as $row => $height) {
            $sheet->getRowDimension((int) $row + 1)->setRowHeight((int) $height * 3 / 4);
        }
        foreach ($layout['hidden_cols'] ?? [] as $col) {
            $sheet->getColumnDimensionByColumn((int) $col + 1)->setVisible(false);
        }
        foreach ($layout['hidden_rows'] ?? [] as $row) {
            $sheet->getRowDimension((int) $row + 1)->setVisible(false);
        }
        foreach (SheetMerges::of($layout) as $merge) {
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($merge['c1'] + 1).($merge['r1'] + 1).':'.Coordinate::stringFromColumnIndex($merge['c2'] + 1).($merge['r2'] + 1));
        }
        $rows = (int) ($layout['frozen_rows'] ?? 0);
        $cols = (int) ($layout['frozen_cols'] ?? 0);
        if ($rows > 0 || $cols > 0) {
            $sheet->freezePane(Coordinate::stringFromColumnIndex($cols + 1).($rows + 1));
        }

        foreach ($layout['validation_rules'] ?? [] as $rule) {
            $this->validation($sheet, $rule);
        }
        $this->rules($sheet, $layout['conditional_formatting_rules'] ?? []);

        foreach (Comment::where('sheet_tab_id', $tab->id)->whereNull('parent_id')->where('detached', false)->get() as $comment) {
            $sheet->getComment(Coordinate::stringFromColumnIndex($comment->col_index + 1).($comment->row_index + 1))->getText()->createTextRun((string) $comment->content);
        }

        if ($tab->color !== null && isset(self::COLOURS[$tab->color])) {
            $sheet->getTabColor()->setRGB(self::COLOURS[$tab->color]);
        }
        if ($tab->hidden) {
            $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        }
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array<string, mixed>
     */
    private function style(array $x): array
    {
        $font = array_filter([
            'bold' => $x['bold'] ?? null,
            'italic' => $x['italic'] ?? null,
            'strikethrough' => $x['strike'] ?? null,
            'underline' => ($x['underline'] ?? false) ? Font::UNDERLINE_SINGLE : null,
            'size' => isset($x['font_size']) ? round($x['font_size'] * 3 / 4, 1) : null,
            'color' => isset($x['font_color']) ? ['rgb' => ltrim($x['font_color'], '#')] : null,
        ]);

        return array_filter([
            'font' => $font,
            'fill' => isset($x['bg_color']) ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ltrim($x['bg_color'], '#')]] : null,
            'alignment' => isset($x['align']) ? ['horizontal' => $x['align']] : null,
            'numberFormat' => ($code = $this->formatCode($x)) === null ? null : ['formatCode' => $code],
        ]);
    }

    /**
     * @param  array<string, mixed>  $x
     */
    private function formatCode(array $x): ?string
    {
        $places = $x['decimals'] ?? null;
        $tail = fn (int $fallback) => ($places ?? $fallback) > 0 ? '.'.str_repeat('0', (int) ($places ?? $fallback)) : '';

        return match ($x['number_format'] ?? 'general') {
            'percent' => '0'.$tail(1).'%',
            'currency' => '"'.(self::SYMBOLS[$x['currency'] ?? 'ZAR'] ?? 'R').'" #,##0'.$tail(2),
            'number' => '#,##0'.$tail(2),
            'date' => self::DATES[$x['date_style'] ?? 'iso'] ?? self::DATES['iso'],
            'time' => 'hh:mm',
            'datetime' => (self::DATES[$x['date_style'] ?? 'iso'] ?? self::DATES['iso']).' hh:mm',
            'scientific' => '0'.$tail(2).'E+00',
            'text' => '@',
            default => $places === null ? null : '#,##0'.$tail(0),
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validation(Worksheet $sheet, array $rule): void
    {
        $from = Coordinate::stringFromColumnIndex((int) $rule['col'] + 1).((int) $rule['row'] + 1);
        $to = Coordinate::stringFromColumnIndex((int) ($rule['col2'] ?? $rule['col']) + 1).((int) ($rule['row2'] ?? $rule['row']) + 1);

        $validation = new DataValidation;
        $validation->setAllowBlank(true)->setShowErrorMessage(true);
        if (($rule['type'] ?? '') === 'list') {
            $validation->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)->setFormula1('"'.str_replace([', ', '"'], [',', ''], (string) ($rule['items'] ?? '')).'"');
        } elseif (($rule['type'] ?? '') === 'number') {
            $validation->setType(DataValidation::TYPE_DECIMAL);
            $min = $rule['min'] ?? null;
            $max = $rule['max'] ?? null;
            if ($min !== null && $max !== null) {
                $validation->setOperator(DataValidation::OPERATOR_BETWEEN)->setFormula1((string) $min)->setFormula2((string) $max);
            } elseif ($min !== null) {
                $validation->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)->setFormula1((string) $min);
            } elseif ($max !== null) {
                $validation->setOperator(DataValidation::OPERATOR_LESSTHANOREQUAL)->setFormula1((string) $max);
            } else {
                return;
            }
        } else {
            return;
        }

        $sheet->setDataValidation("{$from}:{$to}", $validation);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function rules(Worksheet $sheet, array $rules): void
    {
        $byRange = [];
        foreach ($rules as $rule) {
            if (! isset(self::OPERATORS[$rule['operator'] ?? ''])) {
                continue;
            }
            $letter = Coordinate::stringFromColumnIndex((int) $rule['col'] + 1);
            $range = ($rule['target'] ?? '') === 'column' ? $letter.((int) ($rule['row'] ?? 0) + 1).':'.$letter.max(SheetStructureService::GRID_ROWS, 1) : $letter.((int) $rule['row'] + 1);

            $conditional = new Conditional;
            $conditional->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(self::OPERATORS[$rule['operator']]);
            $conditional->addCondition(is_numeric($rule['value']) ? (string) $rule['value'] : '"'.str_replace('"', '""', (string) $rule['value']).'"');
            $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB(ltrim((string) $rule['color'], '#'));
            $byRange[$range][] = $conditional;
        }

        foreach ($byRange as $range => $conditionals) {
            $sheet->getStyle($range)->setConditionalStyles($conditionals);
        }
    }
}
