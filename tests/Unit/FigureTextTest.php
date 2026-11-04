<?php

namespace Tests\Unit;

use App\Support\FigureText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FigureTextTest extends TestCase
{
    /**
     * @return array<string, array{mixed, ?string, string, bool, bool}>
     */
    public static function values(): array
    {
        return [
            'text' => ['Sales', null, 'Sales', false, false],
            'empty' => ['', null, '', false, false],
            'a code with a leading zero' => ['007', null, '007', false, false],
            'small' => ['120', null, '120', true, false],
            'grouped' => ['125000', null, '125 000', true, false],
            'a year stays' => ['2026', null, '2026', true, false],
            'negative' => ['-9240', null, "\u{2212}9 240", true, true],
            'a negative is never a year' => ['-1999', null, "\u{2212}1 999", true, true],
            'long decimals' => ['0.22122555410691', null, '0.221225554', true, false],
            'large with decimals' => ['1234.5', null, '1 234.5', true, false],
            'percent' => ['0.2978', 'percent', '29.8%', true, false],
            'rand' => ['1234.5', 'currency', 'R 1 234.50', true, false],
            'negative rand' => ['-40', 'currency', "\u{2212}R 40.00", true, true],
            'date' => ['45000', 'date', '2023-03-15', true, false],
            'an error value' => ['#DIV/0!', null, '#DIV/0!', false, false],
        ];
    }

    #[DataProvider('values')]
    public function test_a_value_reads_as_it_does_in_the_new_editor(mixed $value, ?string $format, string $text, bool $figure, bool $loss): void
    {
        $this->assertSame(['text' => $text, 'figure' => $figure, 'loss' => $loss], FigureText::shown($value, $format));
    }
}
