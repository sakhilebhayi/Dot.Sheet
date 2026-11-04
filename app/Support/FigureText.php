<?php

namespace App\Support;

/**
 * How a stored value reads on a sheet, the same way resources/js/grid/format.js shows it: a true minus,
 * thousands grouped with a space, a year left as typed.
 */
final class FigureText
{
    private const MINUS = "\u{2212}";

    /**
     * @return array{text: string, figure: bool, loss: bool}
     */
    public static function shown(mixed $value, ?string $format = null): array
    {
        $text = (string) $value;
        if ($text === '' || ! is_numeric($text) || preg_match('/^-?0\d/', $text)) {
            return ['text' => $text, 'figure' => false, 'loss' => false];
        }

        $number = (float) $text;
        $size = abs($number);
        $sign = $number < 0 ? self::MINUS : '';

        $shown = match ($format) {
            'percent' => number_format($size * 100, 1, '.', '').'%',
            'currency' => 'R '.number_format($size, 2, '.', ' '),
            'number' => number_format($size, 2, '.', ' '),
            'date' => null,
            default => self::general($size, $number < 0),
        };

        if ($shown === null) {
            return ['text' => gmdate('Y-m-d', (int) round(($number - 25569) * 86400)), 'figure' => true, 'loss' => false];
        }

        return ['text' => $sign.$shown, 'figure' => true, 'loss' => $number < 0];
    }

    private static function general(float $size, bool $negative): string
    {
        if (floor($size) === $size && $size < 1e15) {
            $year = ! $negative && $size >= 1900 && $size < 2100;

            return number_format($size, 0, '.', $size >= 1000 && ! $year ? ' ' : '');
        }

        $text = rtrim(rtrim(number_format((float) sprintf('%.9g', $size), 9, '.', ''), '0'), '.');
        [$whole, $part] = array_pad(explode('.', $text, 2), 2, null);

        return number_format((float) $whole, 0, '.', ' ').($part === null ? '' : '.'.$part);
    }
}
