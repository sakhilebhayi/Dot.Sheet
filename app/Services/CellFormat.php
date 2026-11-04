<?php

namespace App\Services;

/**
 * Cell formatting that came from a browser. Only known keys with sensible values are kept, because
 * these end up in the styles of every editor that draws the cell.
 */
final class CellFormat
{
    private const SWITCHES = ['bold', 'italic', 'underline', 'strike'];

    private const COLOURS = ['bg_color', 'font_color', 'backgroundColor'];

    public const NUMBER_FORMATS = ['general', 'percent', 'currency', 'number', 'date', 'time', 'datetime', 'scientific', 'text'];

    public const CURRENCIES = ['ZAR', 'USD', 'EUR', 'GBP'];

    public const DATE_STYLES = ['iso', 'long', 'dmy'];

    public const MAX_DECIMALS = 6;

    private const ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * @param  array<string, mixed>|null  $format
     * @return array<string, mixed>|null
     */
    public static function clean(?array $format): ?array
    {
        $clean = [];
        foreach ($format ?? [] as $key => $value) {
            if (in_array($key, self::SWITCHES, true) && is_bool($value)) {
                $clean[$key] = $value;
            } elseif (in_array($key, self::COLOURS, true) && is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
                $clean[$key] = $value;
            } elseif ($key === 'number_format' && in_array($value, self::NUMBER_FORMATS, true)) {
                $clean[$key] = $value;
            } elseif ($key === 'currency' && in_array($value, self::CURRENCIES, true)) {
                $clean[$key] = $value;
            } elseif ($key === 'date_style' && in_array($value, self::DATE_STYLES, true)) {
                $clean[$key] = $value;
            } elseif ($key === 'decimals' && is_int($value) && $value >= 0 && $value <= self::MAX_DECIMALS) {
                $clean[$key] = $value;
            } elseif ($key === 'sparkline' && is_string($value) && RangeText::parse($value) !== null) {
                $clean[$key] = $value;
            } elseif ($key === 'align' && in_array($value, self::ALIGNMENTS, true)) {
                $clean[$key] = $value;
            } elseif ($key === 'font_size' && is_numeric($value) && $value >= 6 && $value <= 96) {
                $clean[$key] = (int) $value;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
