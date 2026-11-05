<?php

namespace App\Jobs;

use App\Services\Formula\Recalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Finishes the formulas of a sheet that took too long to work out while someone waited. */
class WorkOutSheet implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(public int $spreadsheetId, public int $sheetTabId) {}

    public function uniqueId(): string
    {
        return (string) $this->sheetTabId;
    }

    public function handle(Recalculator $recalculator): void
    {
        $recalculator->finishSheet($this->spreadsheetId, $this->sheetTabId);
    }
}
