<?php

namespace App\Services\Formula;

/**
 * The functions Dot.Sheets tells people about, for formula help. Only functions that calculate belong
 * here: FunctionCatalogueTest works out every example against this table and compares the answer.
 *
 *        A        B    C
 *   1    North    10   apple
 *   2    South    20   pear
 *   3    North    30   apple
 */
final class FunctionCatalogue
{
    public const EXAMPLE_ROWS = [['North', 10, 'apple'], ['South', 20, 'pear'], ['North', 30, 'apple']];

    public const CATEGORIES = [
        'Maths', 'Statistics', 'Logic', 'Text', 'Date and time', 'Lookup', 'Finance', 'Conditional totals', 'Arrays',
    ];

    /**
     * @return list<array{name: string, category: string, syntax: string, summary: string, example: string, answer: string}>
     */
    public static function all(): array
    {
        $functions = [];
        foreach (self::ENTRIES as $name => [$category, $syntax, $summary, $example, $answer]) {
            $functions[] = compact('name', 'category', 'syntax', 'summary', 'example', 'answer');
        }

        return $functions;
    }

    /**
     * @return array{name: string, category: string, syntax: string, summary: string, example: string, answer: string}|null
     */
    public static function find(string $name): ?array
    {
        $entry = self::ENTRIES[strtoupper($name)] ?? null;
        if ($entry === null) {
            return null;
        }

        [$category, $syntax, $summary, $example, $answer] = $entry;

        return ['name' => strtoupper($name), 'category' => $category, 'syntax' => $syntax, 'summary' => $summary, 'example' => $example, 'answer' => $answer];
    }

    /** name => [category, syntax, what it does, example, the example's answer] */
    private const ENTRIES = [
        'SUM' => ['Maths', 'SUM(number1, [number2], ...)', 'Adds numbers.', '=SUM(B1:B3)', '60'],
        'PRODUCT' => ['Maths', 'PRODUCT(number1, [number2], ...)', 'Multiplies numbers.', '=PRODUCT(B1:B2)', '200'],
        'ROUND' => ['Maths', 'ROUND(number, digits)', 'Rounds to a number of decimal places.', '=ROUND(2.567,1)', '2.6'],
        'ROUNDUP' => ['Maths', 'ROUNDUP(number, digits)', 'Rounds away from zero.', '=ROUNDUP(2.11,1)', '2.2'],
        'ROUNDDOWN' => ['Maths', 'ROUNDDOWN(number, digits)', 'Rounds towards zero.', '=ROUNDDOWN(2.19,1)', '2.1'],
        'ABS' => ['Maths', 'ABS(number)', 'The number without its sign.', '=ABS(-4)', '4'],
        'MOD' => ['Maths', 'MOD(number, divisor)', 'The remainder after dividing.', '=MOD(7,3)', '1'],
        'POWER' => ['Maths', 'POWER(number, power)', 'A number raised to a power.', '=POWER(2,10)', '1024'],
        'SQRT' => ['Maths', 'SQRT(number)', 'The square root.', '=SQRT(81)', '9'],
        'INT' => ['Maths', 'INT(number)', 'Rounds down to a whole number.', '=INT(7.9)', '7'],

        'AVERAGE' => ['Statistics', 'AVERAGE(number1, [number2], ...)', 'The mean of the numbers.', '=AVERAGE(B1:B3)', '20'],
        'MEDIAN' => ['Statistics', 'MEDIAN(number1, [number2], ...)', 'The middle number.', '=MEDIAN(B1:B3)', '20'],
        'MIN' => ['Statistics', 'MIN(number1, [number2], ...)', 'The smallest number.', '=MIN(B1:B3)', '10'],
        'MAX' => ['Statistics', 'MAX(number1, [number2], ...)', 'The largest number.', '=MAX(B1:B3)', '30'],
        'COUNT' => ['Statistics', 'COUNT(value1, [value2], ...)', 'Counts cells holding numbers.', '=COUNT(A1:B3)', '3'],
        'COUNTA' => ['Statistics', 'COUNTA(value1, [value2], ...)', 'Counts cells that are not empty.', '=COUNTA(A1:A3)', '3'],
        'COUNTBLANK' => ['Statistics', 'COUNTBLANK(range)', 'Counts empty cells.', '=COUNTBLANK(A1:D1)', '1'],
        'LARGE' => ['Statistics', 'LARGE(range, k)', 'The k-th largest number.', '=LARGE(B1:B3,2)', '20'],
        'SMALL' => ['Statistics', 'SMALL(range, k)', 'The k-th smallest number.', '=SMALL(B1:B3,1)', '10'],
        'STDEV' => ['Statistics', 'STDEV(number1, [number2], ...)', 'Standard deviation of a sample.', '=STDEV(B1:B3)', '10'],

        'IF' => ['Logic', 'IF(test, if_true, [if_false])', 'One answer if a test is true, another if not.', '=IF(B1>5,"big","small")', 'big'],
        'IFS' => ['Logic', 'IFS(test1, value1, [test2, value2], ...)', 'The answer of the first test that is true.', '=IFS(B1>50,"a",B1>5,"b")', 'b'],
        'AND' => ['Logic', 'AND(test1, [test2], ...)', 'True when every test is true.', '=AND(B1>5,B2>5)', 'TRUE'],
        'OR' => ['Logic', 'OR(test1, [test2], ...)', 'True when any test is true.', '=OR(B1>50,B2>5)', 'TRUE'],
        'NOT' => ['Logic', 'NOT(test)', 'The opposite of a test.', '=NOT(B1>5)', 'FALSE'],
        'IFERROR' => ['Logic', 'IFERROR(value, if_error)', 'A value, or another if it is an error.', '=IFERROR(1/0,"none")', 'none'],
        'ISBLANK' => ['Logic', 'ISBLANK(value)', 'True when a cell is empty.', '=ISBLANK(D1)', 'TRUE'],
        'ISNUMBER' => ['Logic', 'ISNUMBER(value)', 'True when a value is a number.', '=ISNUMBER(B1)', 'TRUE'],

        'LEFT' => ['Text', 'LEFT(text, [count])', 'Characters from the start.', '=LEFT(A1,3)', 'Nor'],
        'RIGHT' => ['Text', 'RIGHT(text, [count])', 'Characters from the end.', '=RIGHT(A1,2)', 'th'],
        'MID' => ['Text', 'MID(text, start, count)', 'Characters from the middle.', '=MID(A2,2,2)', 'ou'],
        'LEN' => ['Text', 'LEN(text)', 'How many characters.', '=LEN(A1)', '5'],
        'UPPER' => ['Text', 'UPPER(text)', 'Text in capitals.', '=UPPER(C2)', 'PEAR'],
        'LOWER' => ['Text', 'LOWER(text)', 'Text in small letters.', '=LOWER(A1)', 'north'],
        'TRIM' => ['Text', 'TRIM(text)', 'Removes extra spaces.', '=TRIM("  a  b ")', 'a b'],
        'CONCAT' => ['Text', 'CONCAT(text1, [text2], ...)', 'Joins text.', '=CONCAT(A1,"-",C1)', 'North-apple'],
        'TEXTJOIN' => ['Text', 'TEXTJOIN(separator, skip_empty, text1, ...)', 'Joins text with a separator.', '=TEXTJOIN(", ",TRUE,C1:C2)', 'apple, pear'],
        'SUBSTITUTE' => ['Text', 'SUBSTITUTE(text, old, new)', 'Replaces text.', '=SUBSTITUTE(A1,"North","N")', 'N'],
        'FIND' => ['Text', 'FIND(find, within, [start])', 'Where text starts, matching case.', '=FIND("o",A1)', '2'],
        'TEXT' => ['Text', 'TEXT(value, format)', 'A value written in a format.', '=TEXT(1234.5,"#,##0.00")', '1,234.50'],
        'VALUE' => ['Text', 'VALUE(text)', 'Text that is a number, as a number.', '=VALUE("42")', '42'],

        'DATE' => ['Date and time', 'DATE(year, month, day)', 'A date from its parts.', '=DATE(2026,1,31)', '46053'],
        'YEAR' => ['Date and time', 'YEAR(date)', 'The year of a date.', '=YEAR(DATE(2026,3,9))', '2026'],
        'MONTH' => ['Date and time', 'MONTH(date)', 'The month of a date.', '=MONTH(DATE(2026,3,9))', '3'],
        'DAY' => ['Date and time', 'DAY(date)', 'The day of a date.', '=DAY(DATE(2026,3,9))', '9'],
        'EOMONTH' => ['Date and time', 'EOMONTH(date, months)', 'The last day of a month.', '=EOMONTH(DATE(2026,1,15),0)', '46053'],
        'EDATE' => ['Date and time', 'EDATE(date, months)', 'A date some months away.', '=EDATE(DATE(2026,1,15),1)', '46068'],
        'DAYS' => ['Date and time', 'DAYS(end, start)', 'Days between two dates.', '=DAYS(DATE(2026,1,31),DATE(2026,1,1))', '30'],
        'NETWORKDAYS' => ['Date and time', 'NETWORKDAYS(start, end)', 'Working days between two dates.', '=NETWORKDAYS(DATE(2026,1,5),DATE(2026,1,9))', '5'],
        'WEEKDAY' => ['Date and time', 'WEEKDAY(date)', 'The day of the week, as a number.', '=WEEKDAY(DATE(2026,1,4))', '1'],

        'XLOOKUP' => ['Lookup', 'XLOOKUP(find, in_range, return_range, [if_missing])', 'Finds a value and returns the one beside it.', '=XLOOKUP("South",A1:A3,B1:B3)', '20'],
        'VLOOKUP' => ['Lookup', 'VLOOKUP(find, table, column, FALSE)', 'Finds a value in the first column of a table.', '=VLOOKUP("South",A1:B3,2,FALSE)', '20'],
        'HLOOKUP' => ['Lookup', 'HLOOKUP(find, table, row, FALSE)', 'Finds a value in the first row of a table.', '=HLOOKUP("North",A1:C2,2,FALSE)', 'South'],
        'INDEX' => ['Lookup', 'INDEX(range, row, [column])', 'The value at a position.', '=INDEX(B1:B3,3)', '30'],
        'MATCH' => ['Lookup', 'MATCH(find, range, 0)', 'The position of a value.', '=MATCH(20,B1:B3,0)', '2'],
        'ROWS' => ['Lookup', 'ROWS(range)', 'How many rows a range has.', '=ROWS(A1:B3)', '3'],
        'COLUMNS' => ['Lookup', 'COLUMNS(range)', 'How many columns a range has.', '=COLUMNS(A1:B3)', '2'],

        'PMT' => ['Finance', 'PMT(rate, periods, present_value)', 'The payment on a loan each period.', '=ROUND(PMT(0.1/12,12,-1000),2)', '87.92'],
        'FV' => ['Finance', 'FV(rate, periods, payment, [present_value])', 'What regular saving grows to.', '=ROUND(FV(0.01,12,-100),2)', '1268.25'],
        'PV' => ['Finance', 'PV(rate, periods, payment)', 'What future payments are worth now.', '=ROUND(PV(0.01,12,-100),2)', '1125.51'],
        'NPV' => ['Finance', 'NPV(rate, value1, [value2], ...)', 'Today\'s value of future cash flows.', '=ROUND(NPV(0.1,110,121),2)', '200'],
        'IRR' => ['Finance', 'IRR(values)', 'The return of a series of cash flows.', '=ROUND(IRR({-100,60,60}),4)', '0.1307'],

        'SUMIF' => ['Conditional totals', 'SUMIF(range, condition, [sum_range])', 'Adds the cells that meet a condition.', '=SUMIF(A1:A3,"North",B1:B3)', '40'],
        'SUMIFS' => ['Conditional totals', 'SUMIFS(sum_range, range1, condition1, ...)', 'Adds the cells that meet every condition.', '=SUMIFS(B1:B3,A1:A3,"North",C1:C3,"apple")', '40'],
        'COUNTIF' => ['Conditional totals', 'COUNTIF(range, condition)', 'Counts the cells that meet a condition.', '=COUNTIF(A1:A3,"North")', '2'],
        'COUNTIFS' => ['Conditional totals', 'COUNTIFS(range1, condition1, ...)', 'Counts the cells that meet every condition.', '=COUNTIFS(A1:A3,"North",B1:B3,">15")', '1'],
        'AVERAGEIF' => ['Conditional totals', 'AVERAGEIF(range, condition, [average_range])', 'Averages the cells that meet a condition.', '=AVERAGEIF(A1:A3,"North",B1:B3)', '20'],
        'MAXIFS' => ['Conditional totals', 'MAXIFS(max_range, range1, condition1, ...)', 'The largest value meeting the conditions.', '=MAXIFS(B1:B3,A1:A3,"North")', '30'],
        'MINIFS' => ['Conditional totals', 'MINIFS(min_range, range1, condition1, ...)', 'The smallest value meeting the conditions.', '=MINIFS(B1:B3,A1:A3,"North")', '10'],

        'FILTER' => ['Arrays', 'FILTER(range, condition)', 'The rows that meet a condition; fills the cells below.', '=FILTER(B1:B3,A1:A3="North")', '10'],
        'SORT' => ['Arrays', 'SORT(range, [column], [order])', 'A range in order; fills the cells below.', '=SORT(B1:B3,1,-1)', '30'],
        'UNIQUE' => ['Arrays', 'UNIQUE(range)', 'The different values in a range; fills the cells below.', '=UNIQUE(A1:A3)', 'North'],
        'SEQUENCE' => ['Arrays', 'SEQUENCE(rows, [columns], [start], [step])', 'A run of numbers; fills the cells around it.', '=SEQUENCE(3)', '1'],
    ];
}
