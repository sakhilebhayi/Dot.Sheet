<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PivotTable extends Model
{
    public const AGGREGATIONS = ['sum', 'count', 'average', 'min', 'max'];

    protected $fillable = [
        'spreadsheet_id',
        'sheet_tab_id',
        'title',
        'source_range',
        'row_field',
        'column_field',
        'value_field',
        'aggregation',
    ];

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function sheetTab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class);
    }
}
