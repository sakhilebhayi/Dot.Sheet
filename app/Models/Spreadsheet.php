<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    protected static function booted(): void
    {
        // Every spreadsheet needs at least one tab to open into -- mirrors
        // what a new file in Sheets/Excel looks like on creation.
        static::created(function (Spreadsheet $spreadsheet) {
            $spreadsheet->sheetTabs()->create([
                'name' => 'Sheet1',
                'sort_order' => 0,
            ]);
        });
    }

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

    public function sheetTabs(): HasMany
    {
        return $this->hasMany(SheetTab::class);
    }

    public function defaultSheetTab(): ?SheetTab
    {
        return $this->sheetTabs()->orderBy('sort_order')->orderBy('id')->first();
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

    /**
     * Change the settings JSON without losing anyone else's keys: read, change and write inside one
     * transaction that holds the write lock (BEGIN IMMEDIATE on SQLite, a row lock elsewhere), so two
     * writers cannot both start from the same old copy and the later one overwrite the earlier.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change  receives the stored settings, returns the new ones
     * @return array<string, mixed> the stored settings after the change
     */
    public static function updateSettings(int $id, callable $change): array
    {
        return DB::transaction(function () use ($id, $change) {
            $spreadsheet = static::query()->lockForUpdate()->findOrFail($id);
            $settings = is_array($spreadsheet->settings) ? $spreadsheet->settings : [];

            $new = $change($settings);

            $spreadsheet->settings = $new;
            $spreadsheet->save();

            return $new;
        });
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
