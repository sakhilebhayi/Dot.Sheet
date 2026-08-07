<?php

namespace Tests\Unit;

use App\Livewire\AiAnalysisPanel;
use App\Livewire\ShowSpreadsheet;
use App\Services\AiService;
use ReflectionMethod;
use Tests\TestCase;

class AiWorkflowUtilitiesTest extends TestCase
{
    public function test_range_parsing_normalizes_reverse_bounds(): void
    {
        $panel = new AiAnalysisPanel;
        $method = new ReflectionMethod(AiAnalysisPanel::class, 'parseRangeBounds');
        $method->setAccessible(true);

        $bounds = $method->invoke($panel, 'B2:A1');

        $this->assertSame([0, 1, 0, 1], $bounds);
    }

    public function test_range_parsing_handles_single_cell(): void
    {
        $panel = new AiAnalysisPanel;
        $method = new ReflectionMethod(AiAnalysisPanel::class, 'parseRangeBounds');
        $method->setAccessible(true);

        $bounds = $method->invoke($panel, 'C3');

        $this->assertSame([2, 2, 2, 2], $bounds);
    }

    public function test_workflow_condition_matching_for_numeric_and_string_values(): void
    {
        $sheet = new ShowSpreadsheet;
        $method = new ReflectionMethod(ShowSpreadsheet::class, 'compareWorkflowCondition');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($sheet, 4, '<', 10));
        $this->assertTrue($method->invoke($sheet, 'done', '==', 'done'));
        $this->assertFalse($method->invoke($sheet, 12, '<', 10));
    }

    public function test_cleaning_transform_normalizes_whitespace(): void
    {
        $service = new AiService;

        $this->assertSame('hello world', $service->normalizeCellTextForCleaning("  hello\n   world  "));
        $this->assertSame(25, $service->normalizeCellTextForCleaning(25));
    }
}
