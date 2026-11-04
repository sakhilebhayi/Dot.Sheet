<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SheetTab extends Model
{
    /**
     * What a sheet keeps about its own rows and columns: sizes, hidden rows and columns, and the rules
     * that sit on them. Each sheet has its own; the workbook's settings hold the rest (scripts, macros,
     * workflows).
     */
    public const LAYOUT_KEYS = [
        'row_heights', 'col_widths', 'hidden_rows', 'hidden_cols',
        'validation_rules', 'conditional_formatting_rules', 'filter_rules',
        'frozen_rows', 'frozen_cols', 'merges', 'filtered_rows',
    ];

    protected $fillable = [
        'spreadsheet_id',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'layout' => 'json',
    ];

    /**
     * Change one sheet's layout under a row lock, from what is STORED rather than from a copy read
     * earlier, so two people changing different things both keep their change. Returns the new layout.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public static function updateLayout(int $id, callable $change): array
    {
        return DB::transaction(function () use ($id, $change) {
            $tab = static::query()->lockForUpdate()->findOrFail($id);
            $layout = is_array($tab->layout) ? $tab->layout : [];

            $new = $change($layout);

            $tab->layout = $new;
            $tab->save();

            return $new;
        });
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function cells(): HasMany
    {
        return $this->hasMany(Cell::class);
    }

    public function chartConfigs(): HasMany
    {
        return $this->hasMany(ChartConfig::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SpreadsheetVersion::class);
    }
}
