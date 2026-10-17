<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The live database created this table under the earlier file name
        // 2026_03_27_201222_create_cells_table (renamed to fix the migration
        // ordering). Without this guard that database would try to create it
        // a second time the first time it runs this file.
        if (Schema::hasTable('cells')) {
            return;
        }

        Schema::create('cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained('spreadsheets')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('col_index');
            $table->text('raw_value')->nullable();
            $table->text('computed_value')->nullable();
            $table->text('formula')->nullable();
            $table->json('formatting')->nullable();
            $table->timestamp('updated_at');
            $table->unique(['spreadsheet_id', 'row_index', 'col_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cells');
    }
};
