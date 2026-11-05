<?php

namespace App\Notifications;

use App\Services\Integrations\ForwardNoticesToNotify;
use App\Services\Integrations\TellsThroughNotify;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInComment extends Notification implements TellsThroughNotify
{
    use Queueable;

    public function __construct(
        public string $actorName,
        public int $spreadsheetId,
        public string $spreadsheetName,
        public int $row,
        public int $col,
        public string $excerpt,
        public ?int $sheetTabId = null,
    ) {}

    /**
     * Create a new notification instance.
     */
    public function via(object $notifiable): array
    {
        return app(ForwardNoticesToNotify::class)->channelsFor($this->spreadsheetId);
    }

    public function notifySpreadsheetId(): int
    {
        return $this->spreadsheetId;
    }

    public function notifyEvent(): string
    {
        return 'mention';
    }

    public function notifyTitle(): string
    {
        return 'You were mentioned in a comment';
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
            ->action('Open Spreadsheet', route('spreadsheets.show', $this->spreadsheetId).'?'.http_build_query(array_filter(['tab' => $this->sheetTabId, 'cell' => $cellRef])));
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
            'sheet_tab_id' => $this->sheetTabId,
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
