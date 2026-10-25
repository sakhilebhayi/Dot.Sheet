<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use PDOException;

/**
 * SQLite has one write lock; a writer that cannot get it within busy_timeout fails with "database is
 * locked". That must reach the person as a sentence, not as a 500 page: every writer goes through here.
 */
class WriteLock
{
    public const BUSY_MESSAGE = 'The sheet is busy; try again in a moment.';

    public static function isTimeout(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked') || str_contains($message, 'database table is locked')
            || str_contains($message, 'lock wait timeout') || str_contains($message, 'deadlock');
    }

    /**
     * Run a write; when the lock could not be had in time, call $onBusy (nothing was written) instead
     * of letting the exception out. Anything else is rethrown.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @param  callable(): mixed  $onBusy
     * @return T|mixed
     */
    public static function guard(callable $write, callable $onBusy): mixed
    {
        try {
            return $write();
        } catch (QueryException|PDOException $e) {
            if (! self::isTimeout($e)) {
                throw $e;
            }

            return $onBusy();
        }
    }
}
