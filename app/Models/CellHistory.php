<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CellHistory extends Model
{
    // The migration creates 'cell_history' (singular); Eloquent's default
    // pluralized guess ('cell_histories') doesn't exist. Without this
    // override, every cell save crashes here -- this was previously
    // undetected because the test suite never exercised the live
    // saveCell() flow against a fresh cell (only the skipped Browser tests
    // would have).
    protected $table = 'cell_history';

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'cell_id',
        'user_id',
        'old_value',
        'new_value',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function cell(): BelongsTo
    {
        return $this->belongsTo(Cell::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
