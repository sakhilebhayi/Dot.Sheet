<?php

namespace Tests\Unit;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class FormulaEvaluatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_supports_cell_references_and_functions(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Eval Sheet',
            'settings' => [],
        ]);

        Cell::create([
            'spreadsheet_id' => $spreadsheet->id,
            'row_index' => 0,
            'col_index' => 0,
            'raw_value' => '10',
            'computed_value' => '10',
            'formula' => null,
            'updated_at' => now(),
        ]);

        Cell::create([
            'spreadsheet_id' => $spreadsheet->id,
            'row_index' => 0,
            'col_index' => 1,
            'raw_value' => '15',
            'computed_value' => '15',
            'formula' => null,
            'updated_at' => now(),
        ]);

        $service = new FormulaEvaluatorService;
        $result = $service->evaluate('=SUM(A1,B1)', $spreadsheet->id);

        $this->assertFalse($result['error']);
        $this->assertSame(25.0, (float) $result['value']);
    }

    public function test_dependency_graph_tracks_formula_references(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Graph Sheet',
            'settings' => [],
        ]);

        Cell::create([
            'spreadsheet_id' => $spreadsheet->id,
            'row_index' => 1,
            'col_index' => 0,
            'raw_value' => null,
            'computed_value' => null,
            'formula' => '=SUM(A1:B1)',
            'updated_at' => now(),
        ]);

        $service = new FormulaEvaluatorService;

        $method = new ReflectionMethod(FormulaEvaluatorService::class, 'getDependencyGraph');
        $method->setAccessible(true);
        $graph = $method->invoke($service, $spreadsheet->id);

        $this->assertArrayHasKey('0_0', $graph);
        $this->assertArrayHasKey('0_1', $graph);
        $this->assertContains('1_0', $graph['0_0']);
        $this->assertContains('1_0', $graph['0_1']);
    }
}
