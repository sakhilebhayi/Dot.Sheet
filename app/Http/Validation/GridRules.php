<?php

namespace App\Http\Validation;

use App\Services\CellFormat;

/**
 * The grid's larger request rules, in one place (EA-6, Q-05): reusable and testable on their own, and still
 * applied by the controller after it has checked who may act, so a refused person never learns the rules.
 */
final class GridRules
{
    /** @return array<string, mixed> */
    public static function format(): array
    {
        return [
            'version' => 'required|integer',
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1',
            'bold' => 'sometimes|boolean',
            'italic' => 'sometimes|boolean',
            'underline' => 'sometimes|boolean',
            'strike' => 'sometimes|boolean',
            'number_format' => 'sometimes|in:'.implode(',', CellFormat::NUMBER_FORMATS),
            'currency' => 'sometimes|in:'.implode(',', CellFormat::CURRENCIES),
            'date_style' => 'sometimes|in:'.implode(',', CellFormat::DATE_STYLES),
            'decimals' => 'sometimes|nullable|integer|min:0|max:'.CellFormat::MAX_DECIMALS,
            'align' => 'sometimes|nullable|in:left,center,right',
            'font_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bg_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => 'sometimes|nullable|integer|min:6|max:96',
            'clear' => 'sometimes|accepted',
        ];
    }

    /** @return array<string, mixed> */
    public static function paste(): array
    {
        return [
            'version' => 'required|integer',
            'from_tab' => 'required|integer',
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1',
            'row' => 'required|integer|min:0',
            'col' => 'required|integer|min:0',
            'rows' => 'required|integer|min:1',
            'cols' => 'required|integer|min:1',
            'cut' => 'sometimes|boolean',
        ];
    }

    /** @return array<string, mixed> */
    public static function replace(): array
    {
        return [
            'version' => 'required|integer',
            'q' => 'required|string|max:255',
            'with' => 'present|nullable|string|max:255',
            'case' => 'sometimes|boolean',
            'whole' => 'sometimes|boolean',
            'all' => 'sometimes|boolean',
            'only' => 'sometimes|array',
            'only.tab' => 'required_with:only|integer',
            'only.r' => 'required_with:only|integer|min:0',
            'only.c' => 'required_with:only|integer|min:0',
        ];
    }
}
