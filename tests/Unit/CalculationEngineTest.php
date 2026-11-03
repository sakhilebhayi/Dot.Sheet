<?php

namespace Tests\Unit;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the calculation engine (PhpSpreadsheet 5) must be able to do for the formula work in R-35 S2.
 * Version 1.30, which maatwebsite/excel held the app to, failed the XLOOKUP and spill cases: these
 * tests keep a downgrade from passing unnoticed.
 */
class CalculationEngineTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function formulas(): array
    {
        return [
            'operator precedence' => ['=2+3*4-10/4', 11.5],
            'negation before power' => ['=-2^2', 4],
            'references and text joining' => ['=A1&"-"&B1', 'North-10'],
            'XLOOKUP' => ['=XLOOKUP("South",A1:A3,B1:B3)', 20],
            'SUMIFS' => ['=SUMIFS(B1:B3,A1:A3,"*th")', 30],
            'division by zero is an error value' => ['=1/0', '#DIV/0!'],
            'an unknown function is an error value' => ['=NOSUCHFUNCTION(1)', '#NAME?'],
        ];
    }

    #[DataProvider('formulas')]
    public function test_the_engine_calculates(string $formula, mixed $expected): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray([['North', 10], ['South', 20], ['West', 5]]);
        $sheet->setCellValue('D1', $formula);

        $this->assertEquals($expected, $sheet->getCell('D1')->getCalculatedValue());
    }

    public function test_an_array_result_spills_and_a_blocked_spill_is_an_error(): void
    {
        $workbook = new Spreadsheet;
        $workbook->getCalculationEngine()->setInstanceArrayReturnType(Calculation::RETURN_ARRAY_AS_ARRAY);
        $sheet = $workbook->getActiveSheet();
        $sheet->fromArray([[3], [1], [2]]);

        $sheet->setCellValue('C1', '=SORT(A1:A3)');
        // The anchor cell holds the whole result; the cells below receive their part of it.
        $this->assertSame([[1], [2], [3]], $sheet->getCell('C1')->getCalculatedValue());
        $this->assertSame([2, 3], [$sheet->getCell('C2')->getValue(), $sheet->getCell('C3')->getValue()]);

        $sheet->setCellValue('E2', 'in the way');
        $sheet->setCellValue('E1', '=SORT(A1:A3)');
        $this->assertSame('#SPILL!', $sheet->getCell('E1')->getCalculatedValue());
    }
}
