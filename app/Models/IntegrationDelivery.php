<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call to another Dot platform, waiting in or done from the outbox
 * (App\Services\Integrations\Outbox).
 *
 * @property int $id
 * @property string $integration
 * @property int|null $team_id
 * @property string $event
 * @property string $key
 * @property array<string, mixed> $payload encrypted at rest
 * @property string $status pending, sent or given_up
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
class IntegrationDelivery extends Model
{
    public const STATUSES = ['pending' => 'Waiting to send', 'sent' => 'Sent', 'given_up' => 'Not sent'];

    private const EVENTS = ['sheet.mention' => 'Mention email', 'sheet.approval' => 'Approval email', 'sheet.snapshot' => 'Daily numbers'];

    public function eventName(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    protected $fillable = ['integration', 'team_id', 'event', 'key', 'payload', 'status', 'next_attempt_at'];

    protected $casts = ['payload' => 'encrypted:array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer', 'last_status' => 'integer'];
}
