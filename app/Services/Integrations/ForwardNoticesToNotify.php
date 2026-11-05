<?php

namespace App\Services\Integrations;

use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * A notice to someone in a business linked to InfoDot goes out through Dot.Notify, as the other Dot apps do it.
 * What leaves is the minimum: the business, the workbook, what kind of thing happened and a link back. A comment's
 * words and cell contents stay here. Where Notify cannot reach the business, the notice goes by this app's own mail.
 */
final class ForwardNoticesToNotify
{
    public function __construct(private NotifyClient $notify, private Outbox $outbox) {}

    /**
     * How a notice about this workbook is delivered from here. Never both ways, so nobody is told twice.
     *
     * @return list<string>
     */
    public function channelsFor(int $spreadsheetId): array
    {
        return $this->teamReachedFor($spreadsheetId) === null ? ['database', 'mail'] : ['database'];
    }

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $event->notification instanceof TellsThroughNotify) {
            return;
        }

        try {
            $this->forward($event->notification, $event->notifiable, (string) $event->notification->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function forward(TellsThroughNotify $notice, User $recipient, string $id): void
    {
        $spreadsheet = Spreadsheet::find($notice->notifySpreadsheetId());
        $team = $this->teamReachedFor($notice->notifySpreadsheetId());
        if ($id === '' || $spreadsheet === null || $team === null || ! filled($recipient->email)) {
            return;
        }

        $name = 'sheet.'.$notice->notifyEvent();
        $this->outbox->push('notify', $team->id, $name, $name.':'.$id.':'.sha1(strtolower(trim($recipient->email))), [
            'platform' => (string) config('services.notify.platform'),
            'company_uuid' => (string) $team->infodot_team_uuid,
            'event' => $name,
            'recipient' => ['email' => $recipient->email],
            'data' => [
                'business_name' => (string) $team->name,
                'workbook_name' => (string) $spreadsheet->name,
                'title' => $notice->notifyTitle(),
                'url' => (string) preg_replace('#^http://#i', 'https://', route('spreadsheets.show', $spreadsheet)),
            ],
            'idempotency_key' => $name.':'.$id,
        ]);
    }

    private function teamReachedFor(int $spreadsheetId): ?Team
    {
        if (! $this->notify->enabled()) {
            return null;
        }

        $team = Spreadsheet::find($spreadsheetId)?->team;

        return filled($team?->infodot_team_uuid) ? $team : null;
    }
}
