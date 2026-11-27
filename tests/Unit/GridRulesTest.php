<?php

namespace Tests\Unit;

use App\Http\Validation\GridRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** EA-6 (Q-05): the grid's rule sets, checked on their own. */
class GridRulesTest extends TestCase
{
    public function test_a_format_range_must_run_forwards_and_colours_must_be_hex(): void
    {
        $ok = ['version' => 1, 'r1' => 0, 'r2' => 2, 'c1' => 0, 'c2' => 1, 'font_color' => '#2438C9'];
        $this->assertTrue(Validator::make($ok, GridRules::format())->passes());
        $this->assertTrue(Validator::make([...$ok, 'r2' => -1], GridRules::format())->fails());
        $this->assertTrue(Validator::make([...$ok, 'font_color' => 'red'], GridRules::format())->fails());
        $this->assertTrue(Validator::make([...$ok, 'font_size' => 200], GridRules::format())->fails());
    }

    public function test_a_paste_needs_its_source_and_a_positive_size(): void
    {
        $ok = ['version' => 1, 'from_tab' => 3, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'row' => 5, 'col' => 5, 'rows' => 1, 'cols' => 1];
        $this->assertTrue(Validator::make($ok, GridRules::paste())->passes());
        $this->assertTrue(Validator::make([...$ok, 'rows' => 0], GridRules::paste())->fails());
        $this->assertTrue(Validator::make(array_diff_key($ok, ['from_tab' => 1]), GridRules::paste())->fails());
    }

    public function test_a_replace_needs_something_to_find(): void
    {
        $this->assertTrue(Validator::make(['version' => 1, 'q' => 'x', 'with' => null], GridRules::replace())->passes());
        $this->assertTrue(Validator::make(['version' => 1, 'q' => '', 'with' => 'y'], GridRules::replace())->fails());
        $this->assertTrue(Validator::make(['version' => 1, 'q' => 'x', 'with' => 'y', 'only' => ['tab' => 1]], GridRules::replace())->fails());
    }
}
