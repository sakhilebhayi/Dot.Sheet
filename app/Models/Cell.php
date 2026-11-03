<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cell extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'spreadsheet_id',
        'sheet_tab_id',
        'row_index',
        'col_index',
        'raw_value',
        'computed_value',
        'formula',
        'formatting',
        'updated_at',
    ];

    protected $casts = [
        'formatting' => 'json',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Typing over a cell a formula's answer landed on makes it a cell of its own (and blocks the
        // answer): it no longer belongs to that formula.
        static::saving(function (Cell $cell) {
            if ($cell->spill_row !== null && ($cell->formula !== null || (string) $cell->raw_value !== '')) {
                $cell->spill_row = null;
                $cell->spill_col = null;
            }
        });
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function sheetTab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(CellHistory::class);
    }
}
