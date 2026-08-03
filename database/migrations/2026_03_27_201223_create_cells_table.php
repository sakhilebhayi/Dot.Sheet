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
