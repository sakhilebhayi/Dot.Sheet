<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pivot table's source follows rows and columns as they move, so a copy of a sheet remembers it:
     * putting the copy back puts the pivot back on the same cells.
     */
    public function up(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->json('pivot_sources')->nullable()->after('chart_ranges');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->dropColumn('pivot_sources');
        });
    }
};
