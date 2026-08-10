<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a default "Sheet1" tab for every existing spreadsheet (every
     * spreadsheet needs at least one tab to land in) and backfills every
     * existing cell onto it, then tightens the cell uniqueness constraint
     * from (spreadsheet_id, row_index, col_index) to include sheet_tab_id
     * so two tabs in the same spreadsheet can both have a cell at, say,
     * row 1 col 1.
     */
    public function up(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->foreignId('sheet_tab_id')->nullable()->after('spreadsheet_id')->constrained('sheet_tabs')->cascadeOnDelete();
        });

        $now = now();

        DB::table('spreadsheets')->select('id')->orderBy('id')->each(function ($spreadsheet) use ($now) {
            $tabId = DB::table('sheet_tabs')->insertGetId([
                'spreadsheet_id' => $spreadsheet->id,
                'name' => 'Sheet1',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('cells')->where('spreadsheet_id', $spreadsheet->id)->update(['sheet_tab_id' => $tabId]);
        });

        Schema::table('cells', function (Blueprint $table) {
            $table->dropUnique(['spreadsheet_id', 'row_index', 'col_index']);
            $table->unique(['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->dropUnique(['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index']);
            $table->unique(['spreadsheet_id', 'row_index', 'col_index']);
            $table->dropConstrainedForeignId('sheet_tab_id');
        });
    }
};
