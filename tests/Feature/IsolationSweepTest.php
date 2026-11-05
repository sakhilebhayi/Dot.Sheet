<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RouteItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Walks the routes themselves, so a route added later is covered without anyone remembering: nobody reaches another
 * business's workbook by changing an address, and a record of theirs cannot be reached through a workbook of your own.
 */
class IsolationSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Public by design, or leads nowhere without a right that is tested elsewhere. */
    private const OUTSIDE = ['spreadsheets.public'];

    private User $victim;

    private User $stranger;

    private Spreadsheet $theirs;

    private Spreadsheet $mine;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sheet.structure_ops_enabled' => true]);
        // Limits are counted per person across routes, and these tests walk every route at once.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->victim = User::factory()->withPersonalTeam()->create();
        $this->stranger = User::factory()->withPersonalTeam()->create();
        $this->theirs = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->victim->id, 'team_id' => $this->victim->currentTeam->id, 'name' => 'Theirs']);
        $this->mine = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->stranger->id, 'name' => 'Mine']);
    }

    /** @return list<array{0: string, 1: string}> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteItem $route */
            if (! str_contains($route->uri(), '{spreadsheet}') || in_array($route->getName(), self::OUTSIDE, true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $out[] = [$method, $route->uri()];
            }
        }

        return $out;
    }

    /** Fills the address, with $tab for a sheet and a row of theirs, or 1, for anything else named in it. */
    private function address(string $uri, int $spreadsheet, int $tab, array $rows = []): string
    {
        $uri = str_replace(['{spreadsheet}', '{sheetTab}'], [$spreadsheet, $tab], $uri);

        return '/'.preg_replace_callback('/\{([a-zA-Z]+)\}/', fn (array $m) => (string) ($rows[$m[1]] ?? 1), $uri);
    }

    public function test_a_stranger_reaches_no_route_of_a_workbook_that_is_not_theirs(): void
    {
        $tab = $this->theirs->sheetTabs()->firstOrFail()->id;
        $routes = $this->routes();
        $this->assertGreaterThan(70, count($routes));

        foreach ($routes as [$method, $uri]) {
            $status = $this->actingAs($this->stranger)->json($method, $this->address($uri, $this->theirs->id, $tab))->getStatusCode();

            $this->assertContains($status, [302, 403, 404], "{$method} {$uri} answered {$status}.");
            if ($status === 302) {
                $this->assertStringEndsWith('/grid', $uri, "{$method} {$uri} redirected.");
            }
        }
    }

    public function test_a_sheet_of_theirs_cannot_be_reached_through_a_workbook_of_mine(): void
    {
        $theirTab = $this->theirs->sheetTabs()->firstOrFail()->id;

        foreach ($this->routes() as [$method, $uri]) {
            if (! str_contains($uri, '{sheetTab}')) {
                continue;
            }
            $status = $this->actingAs($this->stranger)->json($method, $this->address($uri, $this->mine->id, $theirTab))->getStatusCode();

            $this->assertContains($status, [403, 404], "{$method} {$uri} answered {$status}.");
        }
    }

    public function test_a_record_of_theirs_cannot_be_reached_through_a_workbook_of_mine(): void
    {
        $tab = $this->theirs->sheetTabs()->firstOrFail();
        $myTab = $this->mine->sheetTabs()->firstOrFail()->id;
        $now = now();
        $on = ['spreadsheet_id' => $this->theirs->id, 'sheet_tab_id' => $tab->id, 'created_at' => $now, 'updated_at' => $now];

        $rows = [
            'comment' => DB::table('comments')->insertGetId($on + ['user_id' => $this->victim->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'x']),
            'pivot' => DB::table('pivot_tables')->insertGetId($on + ['title' => 'p', 'source_range' => 'A1:B2', 'row_field' => 'A', 'value_field' => 'B']),
            'chart' => DB::table('chart_configs')->insertGetId($on + ['type' => 'bar', 'title' => 't', 'data_range' => 'A1:B2']),
            'namedRange' => DB::table('named_ranges')->insertGetId($on + ['name' => 'n', 'start_row' => 0, 'start_col' => 0, 'end_row' => 1, 'end_col' => 1]),
        ];

        foreach ($this->routes() as [$method, $uri]) {
            preg_match_all('/\{([a-zA-Z]+)\}/', $uri, $names);
            if (array_intersect($names[1], array_keys($rows)) === []) {
                continue;
            }
            $status = $this->actingAs($this->stranger)->json($method, $this->address($uri, $this->mine->id, $myTab, $rows))->getStatusCode();

            $this->assertContains($status, [403, 404], "{$method} {$uri} answered {$status}.");
        }

        foreach (['comments' => 'comment', 'pivot_tables' => 'pivot', 'chart_configs' => 'chart', 'named_ranges' => 'namedRange'] as $table => $name) {
            $this->assertTrue(DB::table($table)->where('id', $rows[$name])->exists(), "{$table} row was removed.");
        }
    }

    public function test_every_change_a_signed_in_person_can_make_has_a_rate_limit(): void
    {
        $bare = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteItem $route */
            if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [] || ! str_contains($route->uri(), 'spreadsheets')) {
                continue;
            }
            if (! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle'))) {
                $bare[] = $route->uri();
            }
        }

        $this->assertSame([], $bare);
    }
}
