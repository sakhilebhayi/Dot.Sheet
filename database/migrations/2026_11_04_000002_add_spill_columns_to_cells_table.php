<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A formula such as =SORT(A1:A9) answers many cells. The cell holding it records the size of its
     * answer (spill_rows, spill_cols), blocked or not, and each cell the answer lands on records the
     * formula it came from (spill_row, spill_col): those cells hold a value and no content of their own.
     */
    public function up(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->unsignedInteger('spill_rows')->nullable();
            $table->unsignedInteger('spill_cols')->nullable();
            $table->unsignedInteger('spill_row')->nullable();
            $table->unsignedInteger('spill_col')->nullable();
            $table->index(['sheet_tab_id', 'spill_row', 'spill_col'], 'cells_spilled_from');
            $table->index(['sheet_tab_id', 'spill_rows'], 'cells_spill_anchor');
        });
    }

    public function down(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->dropIndex('cells_spill_anchor');
            $table->dropIndex('cells_spilled_from');
            $table->dropColumn(['spill_rows', 'spill_cols', 'spill_row', 'spill_col']);
        });
    }
};
