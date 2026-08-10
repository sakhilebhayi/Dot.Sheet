<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NamedRange extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'sheet_tab_id',
        'name',
        'start_row',
        'start_col',
        'end_row',
        'end_col',
    ];

    protected $casts = [
        'start_row' => 'integer',
        'start_col' => 'integer',
        'end_row' => 'integer',
        'end_col' => 'integer',
    ];

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function sheetTab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class);
    }

    /**
     * The A1-style reference text this name expands to when substituted
     * into a formula, e.g. "A1:B10" for a range or "A1" for a single cell.
     */
    public function toReferenceText(): string
    {
        $start = self::colIndexToLetter($this->start_col).($this->start_row + 1);

        if ($this->start_row === $this->end_row && $this->start_col === $this->end_col) {
            return $start;
        }

        $end = self::colIndexToLetter($this->end_col).($this->end_row + 1);

        return "{$start}:{$end}";
    }

    public static function colIndexToLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26) - 1;
            if ($index < 0) {
                break;
            }
        }

        return $letter;
    }
}
