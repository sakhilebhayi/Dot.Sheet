<?php

namespace Tests\Feature;

use App\Events\SpreadsheetCellUpdated;
use App\Events\SpreadsheetCursorMoved;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Production has no websocket server, so broadcasting is "null". Both events
 * are ShouldBroadcastNow: with the null driver they must neither throw nor
 * leave a failing job on the queue.
 */
class BroadcastingDisabledTest extends TestCase
{
    public function test_broadcasting_defaults_to_the_null_driver(): void
    {
        $this->assertStringContainsString(
            "env('BROADCAST_CONNECTION', 'null')",
            file_get_contents(config_path('broadcasting.php')),
        );
        // env('BROADCAST_CONNECTION') = "null" reads as PHP null; the manager
        // maps that to its null driver.
        $this->assertSame('null', $this->app->make(BroadcastManager::class)->getDefaultDriver());
        $this->assertInstanceOf(NullBroadcaster::class, $this->app->make(BroadcastManager::class)->connection());
    }

    public function test_broadcasting_the_events_with_the_null_driver_is_harmless(): void
    {
        Queue::fake();

        broadcast(new SpreadsheetCellUpdated(1, null, 2, 3, 'x', 'x', null, 4, 'Ada'))->toOthers();
        broadcast(new SpreadsheetCursorMoved(1, null, 2, 3, 4, 'Ada'))->toOthers();

        Queue::assertNothingPushed();
        $this->assertTrue(true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function envFiles(): array
    {
        return [
            '.env.example' => ['.env.example'],
            '.env.production.example' => ['.env.production.example'],
        ];
    }

    #[DataProvider('envFiles')]
    public function test_env_templates_match_the_production_setup(string $file): void
    {
        $env = [];
        foreach (file(base_path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = trim($m[2], "\"' ");
            }
        }

        $this->assertSame('sqlite', $env['DB_CONNECTION'] ?? null, "$file DB_CONNECTION");
        $this->assertSame('null', $env['BROADCAST_CONNECTION'] ?? null, "$file BROADCAST_CONNECTION");
        $this->assertSame('database', $env['QUEUE_CONNECTION'] ?? null, "$file QUEUE_CONNECTION");
        $this->assertSame('database', $env['SESSION_DRIVER'] ?? null, "$file SESSION_DRIVER");
        $this->assertSame('database', $env['CACHE_STORE'] ?? null, "$file CACHE_STORE");
    }
}
