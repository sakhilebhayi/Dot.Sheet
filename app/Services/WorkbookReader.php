<?php

namespace App\Services;

use App\Services\Formula\FunctionCatalogue;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Book;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/**
 * Reads a whole Excel or ODS workbook into plain data, and keeps a count of everything it could not bring,
 * so what is left behind is said out loud. It only reads: WorkbookImporter writes.
 */
class WorkbookReader
{
    private const READERS = ['xlsx' => IOFactory::READER_XLSX, 'xls' => IOFactory::READER_XLS, 'ods' => IOFactory::READER_ODS];

    private const OPERATORS = [
        Conditional::OPERATOR_GREATERTHAN => '>', Conditional::OPERATOR_GREATERTHANOREQUAL => '>=',
        Conditional::OPERATOR_LESSTHAN => '<', Conditional::OPERATOR_LESSTHANOREQUAL => '<=',
        Conditional::OPERATOR_EQUAL => '==', Conditional::OPERATOR_NOTEQUAL => '!=',
    ];

    private const MAX_FROZEN_ROWS = 50;

    private const MAX_FROZEN_COLS = 20;

    /** Sheet colours, by the hue they sit nearest. */
    private const SHEET_COLOURS = ['green' => [31, 138, 95], 'plum' => [139, 63, 168], 'olive' => [110, 122, 30], 'teal' => [14, 138, 160], 'amber' => [181, 121, 26], 'pink' => [176, 73, 140]];

    /** @var array<int, array<string, mixed>|null> */
    private array $formats = [];

    /** @var array<string, int> */
    private array $lost = [];

    /** @var array<string, true> */
    private array $unknownFunctions = [];

    /**
     * How many cells the file's sheets span, without loading them.
     */
    public function size(string $path, string $extension): int
    {
        $total = 0;
        foreach (IOFactory::createReader(self::READERS[$extension])->listWorksheetInfo($path) as $sheet) {
            $total += (int) $sheet['totalRows'] * (int) $sheet['totalColumns'];
        }

        return $total;
    }

    /**
     * @return array{sheets: list<array<string, mixed>>, names: list<array{name: string, sheet: string, range: string}>, lost: array<string, int>, unknown: list<string>}
     */
    public function read(string $path, string $extension): array
    {
        $this->formats = [];
        $this->lost = [];
        $this->unknownFunctions = [];

        $reader = IOFactory::createReader(self::READERS[$extension]);
        $reader->setIncludeCharts(true);
        $book = $reader->load($path);

        try {
            $this->countWhatCannotBeRead($path, $extension);

            $sheets = [];
            foreach ($book->getAllSheets() as $sheet) {
                $sheets[] = $this->sheet($sheet, $book);
            }

            return ['sheets' => $sheets, 'names' => $this->names($book), 'lost' => $this->lost, 'unknown' => array_keys($this->unknownFunctions)];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(Worksheet $sheet, Book $book): array
    {
        $cells = [];
        $collection = $sheet->getCellCollection();
        foreach ($collection->getCoordinates() as $coordinate) {
            $cell = $collection->get($coordinate);
            if ($cell === null) {
                continue;
            }
            [$letters, $row] = Coordinate::coordinateFromString($coordinate);
            $x = $this->format($book, (int) $cell->getXfIndex());
            [$value, $formula] = $this->value($cell->getValue(), $cell->getOldCalculatedValue(), $cell->getDataType() === DataType::TYPE_FORMULA);
            if ($value === '' && $formula === null && $x === null) {
                continue;
            }
            $cells[] = ['r' => (int) $row - 1, 'c' => Coordinate::columnIndexFromString($letters) - 1, 'value' => $value, 'formula' => $formula, 'x' => $x];
        }

        [$widths, $hiddenCols] = $this->columns($sheet);
        [$heights, $hiddenRows] = $this->rows($sheet);
        $this->count('images', count($sheet->getDrawingCollection()));
        $this->count('charts', $sheet->getChartCount());
        $this->count('links', count($sheet->getHyperlinkCollection()));

        return [
            'name' => $sheet->getTitle(),
            'hidden' => $sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE,
            'color' => $this->tabColour($sheet),
            'cells' => $cells,
            'layout' => array_filter([
                'col_widths' => $widths,
                'row_heights' => $heights,
                'hidden_cols' => $hiddenCols,
                'hidden_rows' => $hiddenRows,
                'merges' => $this->merges($sheet),
                ...$this->frozen($sheet),
                'validation_rules' => $this->validation($sheet),
                'conditional_formatting_rules' => $this->rules($sheet),
            ]),
            'comments' => $this->comments($sheet),
        ];
    }

    /**
     * @return array{0: string, 1: string|null} what a cell holds, and its formula
     */
    private function value(mixed $value, mixed $calculated, bool $isFormula): array
    {
        if ($value instanceof RichText) {
            return [$value->getPlainText(), null];
        }
        if (is_bool($value)) {
            return [$value ? 'TRUE' : 'FALSE', null];
        }
        if (! is_string($value)) {
            return [$value === null ? '' : (string) $value, null];
        }
        if (! $isFormula) {
            return [$value, null];
        }

        $formula = $value;
        // A link to another file cannot be followed, so its last answer stays and the report says so.
        if (preg_match('/\[[^\]]*\]/', (string) preg_replace('/"[^"]*"/', '', $formula)) === 1) {
            $this->count('external');

            return [is_scalar($calculated) ? (string) $calculated : '', null];
        }

        preg_match_all('/([A-Za-z_][A-Za-z0-9_.]*)\s*\(/', (string) preg_replace('/"[^"]*"/', '', $formula), $calls);
        foreach ($calls[1] as $function) {
            if (FunctionCatalogue::find($function) === null && ! $this->calculates($function)) {
                $this->unknownFunctions[strtoupper($function)] = true;
            }
        }

        return ['', $formula];
    }

    private function calculates(string $function): bool
    {
        return Calculation::getInstance()->isImplemented(strtoupper($function));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function format(Book $book, int $index): ?array
    {
        if (! array_key_exists($index, $this->formats)) {
            $this->formats[$index] = $this->style($book->getCellXfByIndex($index), $book->getDefaultStyle()->getFont());
        }

        return $this->formats[$index];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function style(Style $style, Font $default): ?array
    {
        $font = $style->getFont();
        $x = [];
        if ($font->getBold()) {
            $x['bold'] = true;
        }
        if ($font->getItalic()) {
            $x['italic'] = true;
        }
        if ($font->getUnderline() !== Font::UNDERLINE_NONE) {
            $x['underline'] = true;
        }
        if ($font->getStrikethrough()) {
            $x['strike'] = true;
        }
        if ($font->getSize() !== null && $font->getSize() !== $default->getSize()) {
            $x['font_size'] = (int) round($font->getSize() * 4 / 3);
        }

        $ink = strtoupper($font->getColor()->getRGB());
        if ($ink !== '' && $ink !== '000000') {
            $x['font_color'] = "#{$ink}";
        }
        $fill = $style->getFill();
        $paint = strtoupper($fill->getStartColor()->getRGB());
        if ($fill->getFillType() === Fill::FILL_SOLID && $paint !== '' && $paint !== 'FFFFFF') {
            $x['bg_color'] = "#{$paint}";
        }

        $align = $style->getAlignment()->getHorizontal();
        if (in_array($align, ['left', 'center', 'right'], true)) {
            $x['align'] = $align;
        }

        return CellFormat::clean($x + $this->numberFormat((string) $style->getNumberFormat()->getFormatCode()));
    }

    /**
     * What a format code comes to in this product's terms.
     *
     * @return array<string, mixed>
     */
    private function numberFormat(string $code): array
    {
        if ($code === '' || strtolower($code) === 'general' || $code === '@') {
            return $code === '@' ? ['number_format' => 'text'] : [];
        }

        $bare = (string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);
        $places = preg_match('/\.(0+)/', $bare, $found) === 1 ? min(strlen($found[1]), CellFormat::MAX_DECIMALS) : 0;

        if (preg_match('/[Ee][+-]/', $bare) === 1) {
            return ['number_format' => 'scientific', 'decimals' => $places];
        }
        if (Date::isDateTimeFormatCode($code)) {
            $hasDate = preg_match('/[dy]/i', $bare) === 1;
            $hasTime = preg_match('/h|s|am\/pm/i', $bare) === 1;

            return ['number_format' => $hasDate && $hasTime ? 'datetime' : ($hasTime ? 'time' : 'date')];
        }
        if (str_contains($bare, '%')) {
            return ['number_format' => 'percent', 'decimals' => $places];
        }
        foreach (['€' => 'EUR', '£' => 'GBP', '$' => 'USD', 'R' => 'ZAR'] as $symbol => $currency) {
            if (str_contains($code, $symbol)) {
                return ['number_format' => 'currency', 'currency' => $currency, 'decimals' => $places];
            }
        }

        return str_contains($bare, '0') ? ['number_format' => 'number', 'decimals' => $places] : [];
    }

    /**
     * @return array{0: array<int, int>, 1: list<int>} widths in pixels, and hidden columns
     */
    private function columns(Worksheet $sheet): array
    {
        $widths = [];
        $hidden = [];
        foreach ($sheet->getColumnDimensions() as $letters => $dimension) {
            $col = Coordinate::columnIndexFromString((string) $letters) - 1;
            if ($dimension->getWidth() > 0) {
                $widths[$col] = max(48, min(420, (int) round($dimension->getWidth() * 7 + 5)));
            }
            if (! $dimension->getVisible()) {
                $hidden[] = $col;
            }
        }

        return [$widths, $hidden];
    }

    /**
     * @return array{0: array<int, int>, 1: list<int>} heights in pixels, and hidden rows
     */
    private function rows(Worksheet $sheet): array
    {
        $heights = [];
        $hidden = [];
        foreach ($sheet->getRowDimensions() as $row => $dimension) {
            if ($dimension->getRowHeight() > 0) {
                $heights[(int) $row - 1] = max(18, min(120, (int) round($dimension->getRowHeight() * 4 / 3)));
            }
            if (! $dimension->getVisible()) {
                $hidden[] = (int) $row - 1;
            }
        }

        return [$heights, $hidden];
    }

    /**
     * @return list<array{r1: int, c1: int, r2: int, c2: int}>
     */
    private function merges(Worksheet $sheet): array
    {
        return array_map(function (string $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);

            return ['r1' => $r1 - 1, 'c1' => $c1 - 1, 'r2' => $r2 - 1, 'c2' => $c2 - 1];
        }, array_values($sheet->getMergeCells()));
    }

    /**
     * @return array<string, int>
     */
    private function frozen(Worksheet $sheet): array
    {
        $pane = $sheet->getFreezePane();
        if ($pane === null) {
            return [];
        }
        [$letters, $row] = Coordinate::coordinateFromString($pane);

        return ['frozen_rows' => min(self::MAX_FROZEN_ROWS, (int) $row - 1), 'frozen_cols' => min(self::MAX_FROZEN_COLS, Coordinate::columnIndexFromString($letters) - 1)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validation(Worksheet $sheet): array
    {
        $rules = [];
        foreach ($sheet->getDataValidationCollection() as $range => $validation) {
            foreach (explode(' ', (string) $range) as $part) {
                [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($part);
                $place = ['id' => bin2hex(random_bytes(8)), 'row' => $r1 - 1, 'col' => $c1 - 1, 'row2' => $r2 - 1, 'col2' => $c2 - 1];
                $rule = $this->allowed($validation);
                if ($rule === null) {
                    $this->count('validation');

                    continue;
                }
                $rules[] = $place + $rule;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function allowed(DataValidation $validation): ?array
    {
        $first = (string) $validation->getFormula1();
        $type = $validation->getType();

        if ($type === DataValidation::TYPE_LIST) {
            // A list typed into the rule. One that reads from cells has no equal here.
            return preg_match('/^"(.*)"$/s', $first, $items) === 1 ? ['type' => 'list', 'items' => implode(', ', array_map('trim', explode(',', $items[1])))] : null;
        }

        if (in_array($type, [DataValidation::TYPE_WHOLE, DataValidation::TYPE_DECIMAL], true) && is_numeric($first)) {
            $second = (string) $validation->getFormula2();

            return match ($validation->getOperator()) {
                DataValidation::OPERATOR_BETWEEN, '' => is_numeric($second) ? ['type' => 'number', 'min' => (float) $first, 'max' => (float) $second] : null,
                DataValidation::OPERATOR_GREATERTHANOREQUAL => ['type' => 'number', 'min' => (float) $first],
                DataValidation::OPERATOR_LESSTHANOREQUAL => ['type' => 'number', 'max' => (float) $first],
                default => null,
            };
        }

        return null;
    }

    /**
     * Rules that compare a cell with a value. Colour scales, data bars and the like count as lost.
     *
     * @return list<array<string, mixed>>
     */
    private function rules(Worksheet $sheet): array
    {
        $rules = [];
        foreach ($sheet->getConditionalStylesCollection() as $range => $styles) {
            foreach ($styles as $style) {
                $operator = self::OPERATORS[$style->getOperatorType()] ?? null;
                $value = $style->getConditions()[0] ?? null;
                $paint = strtoupper($style->getStyle()->getFill()->getEndColor()->getRGB());
                if ($style->getConditionType() !== Conditional::CONDITION_CELLIS || $operator === null || ! is_numeric($value) || $paint === '') {
                    $this->count('rules');

                    continue;
                }
                foreach (explode(' ', (string) $range) as $part) {
                    [[$c1, $r1], [$c2]] = Coordinate::rangeBoundaries($part);
                    foreach (range($c1, min($c2, $c1 + 25)) as $col) {
                        $rules[] = ['id' => bin2hex(random_bytes(8)), 'target' => 'column', 'row' => $r1 - 1, 'col' => $col - 1, 'operator' => $operator, 'value' => (string) $value, 'color' => "#{$paint}"];
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * @return list<array{r: int, c: int, text: string}>
     */
    private function comments(Worksheet $sheet): array
    {
        $comments = [];
        foreach ($sheet->getComments() as $coordinate => $comment) {
            [$letters, $row] = Coordinate::coordinateFromString((string) $coordinate);
            $text = trim($comment->getText()->getPlainText());
            if ($text !== '') {
                $author = trim($comment->getAuthor());
                $comments[] = ['r' => (int) $row - 1, 'c' => Coordinate::columnIndexFromString($letters) - 1, 'text' => $author === '' ? $text : "{$author}: {$text}"];
            }
        }

        return $comments;
    }

    private function tabColour(Worksheet $sheet): ?string
    {
        $rgb = $sheet->isTabColorSet() ? $sheet->getTabColor()->getRGB() : '';
        if (strlen($rgb) !== 6) {
            return null;
        }

        [$r, $g, $b] = array_map('hexdec', str_split($rgb, 2));
        $nearest = null;
        $best = PHP_INT_MAX;
        foreach (self::SHEET_COLOURS as $name => [$cr, $cg, $cb]) {
            $apart = ($r - $cr) ** 2 + ($g - $cg) ** 2 + ($b - $cb) ** 2;
            if ($apart < $best) {
                $best = $apart;
                $nearest = $name;
            }
        }

        return $nearest;
    }

    /**
     * @return list<array{name: string, sheet: string, range: string}>
     */
    private function names(Book $book): array
    {
        $names = [];
        foreach ($book->getDefinedNames() as $defined) {
            $range = str_replace('$', '', preg_replace('/^.*!/', '', $defined->getValue()) ?? '');
            $sheet = $defined->getWorksheet()?->getTitle();
            if ($defined->isFormula() || $sheet === null || RangeText::parse($range) === null) {
                $this->count('names');

                continue;
            }
            $names[] = ['name' => $defined->getName(), 'sheet' => $sheet, 'range' => $range];
        }

        return $names;
    }

    /** What only the file's own listing shows. */
    private function countWhatCannotBeRead(string $path, string $extension): void
    {
        if ($extension !== 'xlsx') {
            return;
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/pivotTables/pivotTable\d+\.xml$#', $entry) === 1) {
                $this->count('pivots');
            }
            if ($entry === 'xl/vbaProject.bin') {
                $this->count('macros');
            }
        }
        $zip->close();
    }

    private function count(string $what, int $by = 1): void
    {
        if ($by > 0) {
            $this->lost[$what] = ($this->lost[$what] ?? 0) + $by;
        }
    }
}
