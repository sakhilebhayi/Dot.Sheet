<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A version says what it is without opening its snapshot: its kind (an automatic copy, one a person
     * made, or the safety copy taken before a restore) and how many cells it holds, so the history list
     * reads a few columns instead of every snapshot. It also keeps the sheet's layout (sizes, hidden
     * rows and columns, rules), so a restore brings that back too.
     */
    public function up(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->string('kind', 16)->default('manual');
            $table->unsignedInteger('cell_count')->nullable();
            $table->json('layout')->nullable();
            $table->index(['sheet_tab_id', 'kind', 'created_at']);
        });

        DB::table('spreadsheet_versions')->where('label', 'Auto Snapshot')->update(['kind' => 'auto']);

        // One snapshot at a time: they can be large.
        DB::table('spreadsheet_versions')->whereNull('cell_count')->select('id')->orderBy('id')->chunkById(100, function ($versions) {
            foreach ($versions as $version) {
                $snapshot = DB::table('spreadsheet_versions')->where('id', $version->id)->value('cells_snapshot');
                $cells = json_decode((string) $snapshot, true);
                DB::table('spreadsheet_versions')->where('id', $version->id)->update(['cell_count' => is_array($cells) ? count($cells) : 0]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->dropIndex(['sheet_tab_id', 'kind', 'created_at']);
            $table->dropColumn(['kind', 'cell_count', 'layout']);
        });
    }
};
