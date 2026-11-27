<?php

namespace App\Notifications;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Notifications\Concerns\QueuedNotice;
use App\Services\Integrations\ForwardNoticesToNotify;
use App\Services\Integrations\TellsThroughNotify;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalNeeded extends Notification implements ShouldQueue, TellsThroughNotify
{
    use Queueable, QueuedNotice;

    public function __construct(public Spreadsheet $spreadsheet, public User $asking)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->respectingPreferences($notifiable, app(ForwardNoticesToNotify::class)->channelsFor($this->spreadsheet->id), 'collaboration');
    }

    public function notifySpreadsheetId(): int
    {
        return $this->spreadsheet->id;
    }

    public function notifyEvent(): string
    {
        return 'approval';
    }

    public function notifyTitle(): string
    {
        return 'Waiting for your approval';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Waiting for your approval in '.config('app.name'))
            ->greeting('Waiting for your approval')
            ->line(MailText::inline($this->asking->name).' asked you to approve '.MailText::inline($this->spreadsheet->name).'.')
            ->action('Open the workbook', route('spreadsheets.show', $this->spreadsheet->id))
            ->line('You can turn these emails off under Profile, Emails.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'approval',
            'actor_name' => $this->asking->name,
            'spreadsheet_id' => $this->spreadsheet->id,
            'spreadsheet_name' => $this->spreadsheet->name,
            'message' => $this->asking->name.' asked you to approve '.$this->spreadsheet->name,
            'url' => route('spreadsheets.show', $this->spreadsheet->id),
        ];
    }
}
