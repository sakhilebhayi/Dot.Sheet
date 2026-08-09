<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupPruneProposal extends Model
{
    protected $fillable = [
        'backup_path', 'backup_created_at', 'status', 'reviewed_by', 'reviewed_at', 'rejected_reason',
    ];

    protected $casts = [
        'backup_created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
