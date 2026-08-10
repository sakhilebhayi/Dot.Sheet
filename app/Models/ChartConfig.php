<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartConfig extends Model
{
    protected $fillable = [
        'spreadsheet_id', 'sheet_tab_id', 'title', 'type',
        'data_range', 'options',
    ];

    protected $casts = ['options' => 'array'];

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function sheetTab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class);
    }
}
