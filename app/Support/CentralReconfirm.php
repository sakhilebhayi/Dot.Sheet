<?php

namespace App\Support;

/**
 * People who sign in through InfoDot have no password here (a random one they never see), so account
 * controls that normally ask for the password ask instead for a recent sign-in through InfoDot.
 */
final class CentralReconfirm
{
    public const WINDOW_MINUTES = 15;

    public static function signedInThroughInfoDot(): bool
    {
        return is_array(session()->get('dotsso.session'));
    }

    public static function isRecent(): bool
    {
        $at = (int) session()->get('dotsso.session.at_ms', 0);

        return $at > 0 && (int) floor(microtime(true) * 1000) - $at <= self::WINDOW_MINUTES * 60 * 1000;
    }
}
