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
     * Versions become tab-scoped: a snapshot/restore only ever touches the
     * tab it was taken on, not the whole workbook.
     */
    public function up(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->foreignId('sheet_tab_id')->nullable()->after('spreadsheet_id')->constrained('sheet_tabs')->cascadeOnDelete();
        });

        // Portable (no raw UPDATE...FROM) so it also runs against the
        // SQLite connection tests use.
        DB::table('sheet_tabs')->orderBy('spreadsheet_id')->orderBy('id')->select('id', 'spreadsheet_id')
            ->get()
            ->unique('spreadsheet_id')
            ->each(function ($tab) {
                DB::table('spreadsheet_versions')
                    ->where('spreadsheet_id', $tab->spreadsheet_id)
                    ->whereNull('sheet_tab_id')
                    ->update(['sheet_tab_id' => $tab->id]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sheet_tab_id');
        });
    }
};
