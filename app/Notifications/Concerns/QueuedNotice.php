<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use Throwable;

/**
 * Every notice from this app is sent by the queue worker, never inside a person's request (EA-2, E-05):
 * a mail server that is slow or down cannot fail or half-finish the action that raised the notice.
 * Three tries, one and then five minutes apart; a notice that still cannot go is reported, which the
 * error log and the ops checks read.
 */
trait QueuedNotice
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }

    /**
     * The channels this notice uses for this person: in-app always; email unless the person turned email off
     * for this kind of notice (security and account notices are never optional).
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    protected function respectingPreferences(object $notifiable, array $channels, string $category): array
    {
        if ($notifiable instanceof User && ! $notifiable->wantsEmailAbout($category)) {
            return array_values(array_diff($channels, ['mail']));
        }

        return $channels;
    }
}
