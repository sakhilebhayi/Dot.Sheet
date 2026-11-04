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
        'kind',
        'cell_count',
        'layout',
        'comment_places',
        'chart_ranges',
        'pivot_sources',
        'cells_snapshot',
    ];

    protected $casts = [
        'cells_snapshot' => 'array',
        'layout' => 'json',
        'comment_places' => 'array',
        'chart_ranges' => 'array',
        'pivot_sources' => 'array',
        'cell_count' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
