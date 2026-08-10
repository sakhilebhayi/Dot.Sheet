<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Named ranges are scoped to a tab, not the whole workbook -- formulas
     * already don't cross tabs (see the multi-tab workbook migrations), so
     * a name is only ever resolved against the tab it was defined on.
     */
    public function up(): void
    {
        Schema::create('named_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained('spreadsheets')->cascadeOnDelete();
            $table->foreignId('sheet_tab_id')->constrained('sheet_tabs')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('start_row');
            $table->unsignedInteger('start_col');
            $table->unsignedInteger('end_row');
            $table->unsignedInteger('end_col');
            $table->timestamps();

            $table->unique(['sheet_tab_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('named_ranges');
    }
};
