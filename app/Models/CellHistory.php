<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CellHistory extends Model
{
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
