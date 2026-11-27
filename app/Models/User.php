<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property array<string, bool>|null $notification_preferences which optional emails the person wants (EA-2)
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_operator' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /** The kinds of email a person may turn off (EA-2, E-10). Security and account email is never optional. */
    public const OPTIONAL_EMAIL = [
        'collaboration' => 'Mentions, approvals, sharing and workflow rules on workbooks',
        'team' => 'Changes to your companies and your role in them',
    ];

    public function wantsEmailAbout(string $category): bool
    {
        if (! array_key_exists($category, self::OPTIONAL_EMAIL)) {
            return true; // security and account notices
        }

        return (bool) (($this->notification_preferences ?? [])[$category.'_email'] ?? true);
    }

    public function spreadsheets()
    {
        return $this->hasMany(Spreadsheet::class, 'owner_id');
    }

    public function sharedSpreadsheets()
    {
        return $this->belongsToMany(Spreadsheet::class, 'spreadsheet_user')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function aiPrompts()
    {
        return $this->hasMany(AiPrompt::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}
