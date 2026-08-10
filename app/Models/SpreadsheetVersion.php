<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpreadsheetVersion extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'sheet_tab_id',
        'user_id',
        'label',
        'cells_snapshot',
    ];

    protected $casts = [
        'cells_snapshot' => 'array',
    ];

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function sheetTab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
