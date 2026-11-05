<?php

namespace App\Services\Integrations;

/** A notice that may go out as a Dot.Notify email for a business linked to InfoDot. */
interface TellsThroughNotify
{
    public function notifySpreadsheetId(): int;

    /** The event's name in Notify's catalogue, without the platform. */
    public function notifyEvent(): string;

    /** What happened, with no cell contents or names in it. */
    public function notifyTitle(): string;
}
