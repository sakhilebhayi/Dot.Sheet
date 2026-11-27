<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedNotice;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A workflow rule was met. One change that meets a rule in many cells sends ONE notice for that rule,
 * naming the first cell and how many more (EA-2, E-08), never a message per cell.
 */
class WorkflowRuleTriggered extends Notification implements ShouldQueue
{
    use Queueable, QueuedNotice;

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public int $spreadsheetId,
        public string $spreadsheetName,
        public string $ruleName,
        public string $cellReference,
        public mixed $actualValue,
        public array $channels = ['email'],
        public int $more = 0,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $mapped = [];
        if (in_array('database', $this->channels, true)) {
            $mapped[] = 'database';
        }
        if (in_array('email', $this->channels, true)) {
            $mapped[] = 'mail';
        }

        $allowed = $this->respectingPreferences($notifiable, $mapped, 'collaboration');

        // Email turned off and the rule only emails: it still shows in the app, so the rule is never silent.
        return $allowed === [] ? ['database'] : $allowed;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $where = 'cell '.$this->cellReference.($this->more > 0 ? ' and '.$this->more.' more' : '');

        return (new MailMessage)
            ->subject('A workflow rule was met in '.config('app.name'))
            ->greeting('A workflow rule was met')
            ->line('The rule "'.MailText::inline($this->ruleName).'" was met in '.MailText::inline($this->spreadsheetName).', '.$where.'.')
            ->action('Open the workbook', route('spreadsheets.show', $this->spreadsheetId))
            ->line('You can turn these emails off under Profile, Emails.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workflow_rule_triggered',
            'spreadsheet_id' => $this->spreadsheetId,
            'spreadsheet_name' => $this->spreadsheetName,
            'rule_name' => $this->ruleName,
            'cell_reference' => $this->cellReference,
            'more' => $this->more,
            'actual_value' => $this->actualValue,
            'message' => 'The rule "'.$this->ruleName.'" was met in '.$this->spreadsheetName.', '.$this->cellReference.($this->more > 0 ? ' and '.$this->more.' more' : ''),
            'url' => route('spreadsheets.show', $this->spreadsheetId),
        ];
    }
}
