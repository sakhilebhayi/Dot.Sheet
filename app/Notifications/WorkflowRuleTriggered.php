<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowRuleTriggered extends Notification
{
    use Queueable;

    public function __construct(
        public int $spreadsheetId,
        public string $spreadsheetName,
        public string $ruleName,
        public string $cellReference,
        public mixed $actualValue,
        public array $channels = ['email'],
    ) {}

    /**
     * Get the notification delivery channels.
     */
    public function via(object $notifiable): array
    {
        $mapped = [];

        if (in_array('database', $this->channels, true)) {
            $mapped[] = 'database';
        }

        if (in_array('email', $this->channels, true)) {
            $mapped[] = 'mail';
        }

        return $mapped;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $value = is_scalar($this->actualValue) ? (string) $this->actualValue : json_encode($this->actualValue);

        return (new MailMessage)
            ->subject('Dot.Sheet workflow triggered')
            ->line("Workflow rule '{$this->ruleName}' was triggered.")
            ->line("Cell: {$this->cellReference}")
            ->line("Value: {$value}")
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
            'type' => 'workflow_rule_triggered',
            'spreadsheet_id' => $this->spreadsheetId,
            'spreadsheet_name' => $this->spreadsheetName,
            'rule_name' => $this->ruleName,
            'cell_reference' => $this->cellReference,
            'actual_value' => $this->actualValue,
        ];
    }
}
