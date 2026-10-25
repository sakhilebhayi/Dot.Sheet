<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A counter that goes up whenever rows or columns of a spreadsheet move (insert, delete, sort,
     * restore, tab delete). It is a column of its own, not a key of the settings JSON: every writer of
     * that JSON replaces it as a whole, and one that had read it earlier wrote the old counter back.
     */
    public function up(): void
    {
        Schema::table('spreadsheets', function (Blueprint $table) {
            $table->unsignedInteger('structure_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheets', function (Blueprint $table) {
            $table->dropColumn('structure_version');
        });
    }
};
