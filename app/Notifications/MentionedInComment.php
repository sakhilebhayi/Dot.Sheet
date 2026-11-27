<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedNotice;
use App\Services\Integrations\ForwardNoticesToNotify;
use App\Services\Integrations\TellsThroughNotify;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInComment extends Notification implements ShouldQueue, TellsThroughNotify
{
    use Queueable, QueuedNotice;

    public function __construct(
        public string $actorName,
        public int $spreadsheetId,
        public string $spreadsheetName,
        public int $row,
        public int $col,
        public string $excerpt,
        public ?int $sheetTabId = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->respectingPreferences($notifiable, app(ForwardNoticesToNotify::class)->channelsFor($this->spreadsheetId), 'collaboration');
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

    public function toMail(object $notifiable): MailMessage
    {
        $cellRef = $this->colToLetter($this->col).$this->row;

        return (new MailMessage)
            ->subject('You were mentioned in '.config('app.name'))
            ->greeting('You were mentioned')
            ->line(MailText::inline($this->actorName).' mentioned you in '.MailText::inline($this->spreadsheetName).', cell '.$cellRef.':')
            ->line('"'.MailText::inline($this->excerpt, 140).'"')
            ->action('Open the comment', $this->url())
            ->line('You can turn these emails off under Profile, Emails.');
    }

    /** @return array<string, mixed> */
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
            'message' => $this->actorName.' mentioned you in '.$this->spreadsheetName,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        $cellRef = $this->colToLetter($this->col).$this->row;

        return route('spreadsheets.show', $this->spreadsheetId).'?'.http_build_query(array_filter(['tab' => $this->sheetTabId, 'cell' => $cellRef]));
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
