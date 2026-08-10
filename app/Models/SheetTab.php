<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetTab extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'name',
        'sort_order',
    ];

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
