<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Most lookups of one cell name its sheet and position, not its workbook, and the only index that helped them was the
 * one for spilled answers, so each one read the whole sheet. Ten thousand formulas took eight seconds to work out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->index(['sheet_tab_id', 'row_index', 'col_index'], 'cells_at');
        });
    }

    public function down(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->dropIndex('cells_at');
        });
    }
};
