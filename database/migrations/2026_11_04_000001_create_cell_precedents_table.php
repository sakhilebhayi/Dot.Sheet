<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The formula engine's dependency graph: one row for each range a formula reads. Changing a cell
     * finds the formulas to recalculate by asking which rows' ranges contain it.
     *
     * A sheet whose formulas have not been worked out by this engine yet (every sheet, at first) has no
     * formulas_built_at: it is worked out the next time it is opened. A formula's error value comes with
     * a sentence that says what went wrong.
     */
    public function up(): void
    {
        Schema::create('cell_precedents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_tab_id')->constrained('sheet_tabs')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('col_index');
            $table->foreignId('precedent_tab_id')->constrained('sheet_tabs')->cascadeOnDelete();
            $table->unsignedInteger('row_from');
            $table->unsignedInteger('row_to');
            $table->unsignedInteger('col_from');
            $table->unsignedInteger('col_to');

            $table->index(['sheet_tab_id', 'row_index', 'col_index'], 'cell_precedents_dependent');
            $table->index(['precedent_tab_id', 'col_from', 'col_to', 'row_from'], 'cell_precedents_precedent');
        });

        Schema::table('sheet_tabs', function (Blueprint $table) {
            $table->timestamp('formulas_built_at')->nullable();
        });

        Schema::table('cells', function (Blueprint $table) {
            $table->string('formula_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->dropColumn('formula_error');
        });

        Schema::table('sheet_tabs', function (Blueprint $table) {
            $table->dropColumn('formulas_built_at');
        });

        Schema::dropIfExists('cell_precedents');
    }
};
