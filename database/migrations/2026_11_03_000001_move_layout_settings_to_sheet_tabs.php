<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The keys that describe one sheet's rows and columns. */
    private const KEYS = [
        'row_heights', 'col_widths', 'hidden_rows', 'hidden_cols',
        'validation_rules', 'conditional_formatting_rules', 'filter_rules',
    ];

    /**
     * Row heights, column widths, hidden rows and columns, validation, conditional formats and filters
     * were stored once per workbook, so every sheet of a workbook showed the same ones. Each sheet now
     * keeps its own. Every existing sheet starts with what the workbook had, which is what it showed.
     */
    public function up(): void
    {
        Schema::table('sheet_tabs', function (Blueprint $table) {
            $table->json('layout')->nullable();
        });

        DB::table('spreadsheets')->whereNotNull('settings')->orderBy('id')->chunkById(200, function ($spreadsheets) {
            foreach ($spreadsheets as $spreadsheet) {
                $settings = json_decode((string) $spreadsheet->settings, true);
                if (! is_array($settings)) {
                    continue;
                }

                $layout = array_intersect_key($settings, array_flip(self::KEYS));
                if ($layout === []) {
                    continue;
                }

                DB::table('sheet_tabs')->where('spreadsheet_id', $spreadsheet->id)->update(['layout' => json_encode($layout)]);
                DB::table('spreadsheets')->where('id', $spreadsheet->id)->update([
                    'settings' => json_encode(array_diff_key($settings, $layout)),
                ]);
            }
        });
    }

    /**
     * Back to one layout per workbook: the first sheet's.
     */
    public function down(): void
    {
        DB::table('spreadsheets')->orderBy('id')->chunkById(200, function ($spreadsheets) {
            foreach ($spreadsheets as $spreadsheet) {
                $first = DB::table('sheet_tabs')->where('spreadsheet_id', $spreadsheet->id)
                    ->orderBy('sort_order')->orderBy('id')->value('layout');
                $layout = json_decode((string) $first, true);
                if (! is_array($layout) || $layout === []) {
                    continue;
                }

                $settings = json_decode((string) $spreadsheet->settings, true);
                DB::table('spreadsheets')->where('id', $spreadsheet->id)->update([
                    'settings' => json_encode(array_replace(is_array($settings) ? $settings : [], $layout)),
                ]);
            }
        });

        Schema::table('sheet_tabs', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
