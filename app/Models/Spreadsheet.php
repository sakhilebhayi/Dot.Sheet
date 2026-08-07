<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Spreadsheet extends Model
{
    protected $fillable = [
        'uuid',
        'owner_id',
        'team_id',
        'name',
        'settings',
        'public_enabled',
        'public_token',
        'public_expires_at',
    ];

    protected $casts = [
        'settings' => 'json',
        'public_enabled' => 'boolean',
        'public_expires_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function cells(): HasMany
    {
        return $this->hasMany(Cell::class);
    }

    public function sharedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'spreadsheet_user')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function aiPrompts(): HasMany
    {
        return $this->hasMany(AiPrompt::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SpreadsheetVersion::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SpreadsheetInvitation::class);
    }

    public function workflowExecutionLogs(): HasMany
    {
        return $this->hasMany(WorkflowExecutionLog::class);
    }

    public function publicLinkIsActive(): bool
    {
        if (! $this->public_enabled || ! $this->public_token) {
            return false;
        }

        if ($this->public_expires_at && $this->public_expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
