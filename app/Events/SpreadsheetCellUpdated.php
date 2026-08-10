<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SpreadsheetCellUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $spreadsheetId,
        public ?int $sheetTabId,
        public int $row,
        public int $col,
        public ?string $rawValue,
        public ?string $computedValue,
        public ?string $formula,
        public int $userId,
        public string $userName,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('spreadsheet.'.$this->spreadsheetId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cell.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'spreadsheet_id' => $this->spreadsheetId,
            // Every tab in a spreadsheet shares the one presence channel
            // (collaborators show as present whichever tab they're on), so
            // recipients need sheet_tab_id to know whether this update is
            // for the tab they're currently viewing.
            'sheet_tab_id' => $this->sheetTabId,
            'row' => $this->row,
            'col' => $this->col,
            'raw_value' => $this->rawValue,
            'computed_value' => $this->computedValue,
            'formula' => $this->formula,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'timestamp' => now()->toISOString(),
        ];
    }
}
