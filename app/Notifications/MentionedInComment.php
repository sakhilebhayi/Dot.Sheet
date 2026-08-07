<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInComment extends Notification
{
    use Queueable;

    public function __construct(
        public string $actorName,
        public int $spreadsheetId,
        public string $spreadsheetName,
        public int $row,
        public int $col,
        public string $excerpt,
    ) {}

    /**
     * Create a new notification instance.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $cellRef = $this->colToLetter($this->col).$this->row;

        return (new MailMessage)
            ->subject('You were mentioned in a spreadsheet comment')
            ->line("{$this->actorName} mentioned you in {$this->spreadsheetName}.")
            ->line("Cell: {$cellRef}")
            ->line("Comment: \"{$this->excerpt}\"")
            ->action('Open Spreadsheet', url('/spreadsheets/'.$this->spreadsheetId));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mention',
            'actor_name' => $this->actorName,
            'spreadsheet_id' => $this->spreadsheetId,
            'spreadsheet_name' => $this->spreadsheetName,
            'row' => $this->row,
            'col' => $this->col,
            'excerpt' => $this->excerpt,
        ];
    }

    protected function colToLetter(int $col): string
    {
        $col = max(1, $col);
        $letter = '';
        while ($col > 0) {
            $rem = ($col - 1) % 26;
            $letter = chr(65 + $rem).$letter;
            $col = intdiv($col - 1, 26);
        }

        return $letter;
    }
}
