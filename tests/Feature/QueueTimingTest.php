<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/** EA-4 (P-01): no job may run longer than the queue waits before handing it to another worker. */
class QueueTimingTest extends TestCase
{
    public function test_the_database_queue_waits_longer_than_any_job_may_run(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $checked = 0;

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.Str::before(basename($file), '.php');
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $timeout = (int) ($defaults['timeout'] ?? 60); // the worker's default limit
            $this->assertLessThan($retryAfter, $timeout + 30, "{$class} may run {$timeout}s; the queue re-hands a job after {$retryAfter}s");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }
}
