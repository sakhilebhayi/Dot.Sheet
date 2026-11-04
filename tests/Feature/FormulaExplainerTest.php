<?php

namespace Tests\Feature;

use App\Services\Formula\FormulaExplainer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormulaExplainerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function formulas(): array
    {
        return [
            'a total' => ['=SUM(B2:B10)', 'The total of cells B2 to B10.'],
            'several' => ['=SUM(A1,B2:B3,5)', 'The total of A1, cells B2 to B3 and 5.'],
            'an average' => ['=AVERAGE(C1:C4)', 'The average of cells C1 to C4.'],
            'dollar signs' => ['=SUM($B$2:$B$10)', 'The total of cells B2 to B10.'],
            'another sheet' => ["=SUM('Cash flow'!A1:A9)", 'The total of cells A1 to A9 on Cash flow.'],
            'a plain sheet' => ['=Sales!B2', 'B2 on Sales.'],
            'a condition' => ['=IF(B2>100,"High","Low")', 'If B2 is more than 100, “High”, otherwise “Low”.'],
            'at least' => ['=IF(B2>=1,"y","n")', 'If B2 is at least 1, “y”, otherwise “n”.'],
            'not equal' => ['=IF(A1<>"",1,0)', 'If A1 is not nothing, 1, otherwise 0.'],
            'equal' => ['=IF(A1=2,1,0)', 'If A1 is 2, 1, otherwise 0.'],
            'blank when empty' => ['=IF(A2="","",A2*2)', 'If A2 is nothing, nothing, otherwise A2 times 2.'],
            'sums' => ['=A1+B1-C1', 'A1 plus B1 minus C1.'],
            'division' => ['=B1/C1', 'B1 divided by C1.'],
            'brackets' => ['=(A1+B1)*2', 'A1 plus B1 times 2.'],
            'joined' => ['=A1&" "&B1', 'A1 joined to “ ” joined to B1.'],
            'a negative' => ['=A1*-2', 'A1 times -2.'],
            'conditional total' => ['=SUMIFS(C2:C9,A2:A9,"Rent")', 'The total of cells C2 to C9 where cells A2 to A9 matches “Rent”.'],
            'conditional count' => ['=COUNTIF(A1:A5,"Won")', 'How many cells in cells A1 to A5 match “Won”.'],
            'lookup' => ['=VLOOKUP(A1,D1:F9,2,FALSE)', 'The value in column 2 of cells D1 to F9, on the row where the first column matches A1.'],
            'rounding' => ['=ROUND(A1,0)', 'A1 rounded to a whole number.'],
            'error guard' => ['=IFERROR(A1/B1,"")', 'A1 divided by B1, or nothing if that gives an error.'],
            'nested' => ['=IF(SUM(A1:A3)>10,"Over","Under")', 'If the total of cells A1 to A3 is more than 10, “Over”, otherwise “Under”.'],
            'a name' => ['=SUM(Takings)', 'The total of Takings.'],
            'today' => ['=TODAY()', "Today's date."],
            'a comma in quotes' => ['=IF(A1="a,b",1,0)', 'If A1 is “a,b”, 1, otherwise 0.'],
            'a bracket in quotes' => ['=CONCAT("(",A1)', '“(” and A1 joined together.'],
            'an unknown function' => ['=SUMM(A1)', 'SUMM of A1, which this product does not know.'],
            'a catalogued function' => ['=MEDIAN(A1:A3)', 'The middle number (MEDIAN of cells A1 to A3).'],
        ];
    }

    #[DataProvider('formulas')]
    public function test_a_formula_reads_as_a_sentence(string $formula, string $sentence): void
    {
        $this->assertSame($sentence, (new FormulaExplainer)->explain($formula));
    }

    public function test_an_empty_formula_says_so(): void
    {
        $this->assertSame('This formula is empty.', (new FormulaExplainer)->explain('='));
    }

    public function test_a_formula_that_cannot_be_read_says_so_rather_than_guessing(): void
    {
        $deep = '=A1';
        foreach (range(1, 12) as $ignored) {
            $deep = '=SUM('.ltrim($deep, '=').')';
        }

        foreach ([$deep, '=@#$', '=SUM(A1'] as $formula) {
            $this->assertStringContainsString('too involved', (new FormulaExplainer)->explain($formula));
        }
    }
}
