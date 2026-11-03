<?php

namespace Tests\Unit;

use App\Services\Formula\FormulaText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormulaTextMovedTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function moves(): array
    {
        return [
            'a cell' => ['=A1+1', 1, 2, '=C2+1'],
            'a range' => ['=SUM(B2:B10)', 2, 1, '=SUM(C4:C12)'],
            'anchored column' => ['=$A1', 3, 3, '=$A4'],
            'anchored row' => ['=A$1', 3, 3, '=D$1'],
            'anchored both' => ['=$A$1*B1', 3, 3, '=$A$1*E4'],
            'whole columns' => ['=SUM(B:D)', 5, 1, '=SUM(C:E)'],
            'whole rows' => ['=SUM(2:4)', 1, 5, '=SUM(3:5)'],
            'anchored whole column' => ['=SUM($B:C)', 0, 1, '=SUM($B:D)'],
            'anchored whole row' => ['=SUM($2:3)', 1, 0, '=SUM($2:4)'],
            'another sheet' => ['=Stock!B2*2', 1, 0, '=Stock!B3*2'],
            'a quoted sheet' => ["='Sales 2026'!A1", 0, 1, "='Sales 2026'!B1"],
            'text stays' => ['=IF(A1="B2",1,0)', 1, 1, '=IF(B2="B2",1,0)'],
            'function names stay' => ['=LOG10(A1)', 1, 0, '=LOG10(A2)'],
            'moving back' => ['=C3', -1, -2, '=A2'],
            'off the top' => ['=A1+B2', -1, 0, '=#REF!+B1'],
            'off the left' => ['=A1', 0, -1, '=#REF!'],
            'a range leaving the sheet' => ['=SUM(A1:A3)', -1, 0, '=SUM(#REF!)'],
            'a sheet leaving with it' => ['=Stock!A1', -1, 0, '=#REF!'],
            'past the last row' => ['=A1048576', 1, 0, '=#REF!'],
        ];
    }

    #[DataProvider('moves')]
    public function test_a_formula_follows_its_cell(string $formula, int $rows, int $cols, string $expected): void
    {
        $this->assertSame($expected, FormulaText::moved($formula, $rows, $cols));
    }
}
