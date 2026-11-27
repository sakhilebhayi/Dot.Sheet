<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedNotice;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The notices the audit found missing (EA-2, E-07): access given, changed or taken away, an approval decided,
 * a change to someone's place in a company, security changes and a deleted account. One class, so every one
 * reads the same, is escaped the same and respects the same preferences.
 *
 * $category: 'collaboration' or 'team' (the person may turn the email off) or 'security' (never optional).
 * $body is plain text, names and notes included; it is escaped for email here.
 */
class ActivityNotice extends Notification implements ShouldQueue
{
    use Queueable, QueuedNotice;

    public function __construct(
        public string $type,
        public string $category,
        public string $title,
        public string $body,
        public ?string $url = null,
        public string $actionText = 'Open Dot.Sheet',
        public bool $mailOnly = false,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ($this->mailOnly) {
            return ['mail'];
        }

        return $this->respectingPreferences($notifiable, ['database', 'mail'], $this->category);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title.' - '.config('app.name'))
            ->greeting($this->title)
            ->line(MailText::inline($this->body, 600));

        if ($this->url !== null) {
            $mail->action($this->actionText, $this->url);
        }

        return $this->category === 'security'
            ? $mail->line('If this was not you, sign in through InfoDot and change your password there, or reply to this email.')
            : $mail->line('You can turn these emails off under Profile, Emails.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'message' => $this->body, 'url' => $this->url, 'title' => $this->title];
    }
}
