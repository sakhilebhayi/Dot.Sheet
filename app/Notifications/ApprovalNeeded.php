<?php

namespace App\Notifications;

use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalNeeded extends Notification
{
    use Queueable;

    public function __construct(public Spreadsheet $spreadsheet, public User $asking) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A spreadsheet is waiting for your approval')
            ->line("{$this->asking->name} asked you to approve {$this->spreadsheet->name}.")
            ->action('Open Spreadsheet', route('spreadsheets.show', $this->spreadsheet->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'approval',
            'actor_name' => $this->asking->name,
            'spreadsheet_id' => $this->spreadsheet->id,
            'spreadsheet_name' => $this->spreadsheet->name,
        ];
    }
}
