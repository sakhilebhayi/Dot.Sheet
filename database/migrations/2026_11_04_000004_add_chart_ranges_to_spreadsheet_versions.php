<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chart's ranges follow rows and columns as they move, so a copy of a sheet remembers them: putting
     * the copy back puts the charts back on the same cells.
     */
    public function up(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->json('chart_ranges')->nullable()->after('comment_places');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->dropColumn('chart_ranges');
        });
    }
};
