<?php

namespace App\Services;

/**
 * How a stored value reads, for what is drawn on the server: print and PDF. The same rules as show() in
 * resources/js/grid/format.js, and a test holds the two to the same table of values.
 */
final class CellDisplay
{
    private const MINUS = '−';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private const SYMBOLS = ['ZAR' => 'R', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    /**
     * @param  array<string, mixed>|null  $x
     */
    public static function text(?string $value, ?array $x): string
    {
        $value = (string) $value;
        $format = $x['number_format'] ?? 'general';
        if ($value === '' || $format === 'text' || ! is_numeric($value) || preg_match('/^-?0\d/', $value) === 1) {
            return $value;
        }

        $n = (float) $value;
        $abs = abs($n);
        $sign = $n < 0 ? self::MINUS : '';
        $places = is_int($x['decimals'] ?? null) ? $x['decimals'] : null;

        return match ($format) {
            'percent' => $sign.self::grouped($abs * 100, $places ?? 1).'%',
            'currency' => $sign.(self::SYMBOLS[$x['currency'] ?? ''] ?? 'R').' '.self::grouped($abs, $places ?? 2),
            'number' => $sign.self::grouped($abs, $places ?? 2),
            'date' => self::date($n, $x['date_style'] ?? null),
            'time' => self::time($n),
            'datetime' => self::date($n, $x['date_style'] ?? null).' '.self::time($n),
            'scientific' => $sign.str_replace(['e+', 'e-'], ['E+', 'E-'], sprintf('%.'.($places ?? 2).'e', $abs)),
            default => $sign.self::general($n, $abs, $places),
        };
    }

    private static function general(float $n, float $abs, ?int $places): string
    {
        if ($places !== null) {
            return self::grouped($abs, $places);
        }
        if (floor($abs) === $abs) {
            $year = $n > 0 && $abs >= 1900 && $abs < 2100;

            return $abs >= 1000 && $abs < 1e15 && ! $year ? self::grouped($abs, 0) : (string) (int) $abs;
        }
        $text = rtrim(rtrim(sprintf('%.9G', $abs), '0'), '.');
        if (str_contains($text, 'E')) {
            return $text;
        }
        [$whole, $part] = array_pad(explode('.', $text), 2, '');

        return self::group($whole).($part === '' ? '' : ".{$part}");
    }

    private static function grouped(float $abs, int $decimals): string
    {
        $text = number_format($abs, $decimals, '.', '');
        [$whole, $part] = array_pad(explode('.', $text), 2, '');

        return self::group($whole).($part === '' ? '' : ".{$part}");
    }

    private static function group(string $digits): string
    {
        return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $digits);
    }

    private static function date(float $serial, ?string $style): string
    {
        $seconds = ((int) floor($serial) - 25569) * 86400;
        [$year, $month, $day] = explode('-', gmdate('Y-m-d', $seconds));

        return match ($style) {
            'long' => (int) $day.' '.self::MONTHS[(int) $month - 1].' '.$year,
            'dmy' => "{$day}/{$month}/{$year}",
            default => "{$year}-{$month}-{$day}",
        };
    }

    private static function time(float $serial): string
    {
        $minutes = ((int) round(($serial - floor($serial)) * 1440)) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
