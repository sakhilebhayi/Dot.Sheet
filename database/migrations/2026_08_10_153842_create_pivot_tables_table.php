<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores the pivot's *configuration* only (source range + which
     * columns feed rows/columns/values) -- the actual pivot result is
     * always computed fresh from live Cell data, never cached here, so a
     * saved pivot reflects the current data every time it's opened.
     */
    public function up(): void
    {
        Schema::create('pivot_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained('spreadsheets')->cascadeOnDelete();
            $table->foreignId('sheet_tab_id')->constrained('sheet_tabs')->cascadeOnDelete();
            $table->string('title');
            $table->string('source_range'); // e.g. "A1:D50" -- first row is treated as headers
            $table->string('row_field'); // source column letter grouped into pivot rows
            $table->string('column_field')->nullable(); // source column letter grouped into pivot columns
            $table->string('value_field'); // source column letter aggregated into the pivot body
            $table->string('aggregation')->default('sum'); // sum, count, average, min, max
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pivot_tables');
    }
};
