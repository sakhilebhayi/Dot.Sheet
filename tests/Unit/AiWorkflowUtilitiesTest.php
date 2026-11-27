<?php

namespace Tests\Unit;

use App\Services\AiService;
use App\Services\SheetWorkflows;
use ReflectionMethod;
use Tests\TestCase;

class AiWorkflowUtilitiesTest extends TestCase
{
    public function test_workflow_condition_matching_for_numeric_and_string_values(): void
    {
        $workflows = new SheetWorkflows;
        $method = new ReflectionMethod(SheetWorkflows::class, 'meets');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($workflows, 4, '<', 10));
        $this->assertTrue($method->invoke($workflows, 'done', '==', 'done'));
        $this->assertFalse($method->invoke($workflows, 12, '<', 10));
    }

    public function test_cleaning_transform_normalizes_whitespace(): void
    {
        $service = new AiService;

        $this->assertSame('hello world', $service->normalizeCellTextForCleaning("  hello\n   world  "));
        $this->assertSame(25, $service->normalizeCellTextForCleaning(25));
    }
}
