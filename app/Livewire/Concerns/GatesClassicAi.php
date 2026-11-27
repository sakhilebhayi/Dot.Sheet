<?php

namespace App\Livewire\Concerns;

use App\Services\AiService;

/**
 * EA-3 (F-02): the classic editor's AI panels ask a real model or say plainly that AI is off. With no provider
 * configured the service answers from canned demo text, which these panels used to show as a real result.
 */
trait GatesClassicAi
{
    public const AI_OFF_MESSAGE = 'AI is not switched on for Dot.Sheet yet, so nothing was sent to a model.';

    protected function aiIsOff(): bool
    {
        if (app(AiService::class)->isLive()) {
            return false;
        }

        $this->error = self::AI_OFF_MESSAGE;

        return true;
    }
}
