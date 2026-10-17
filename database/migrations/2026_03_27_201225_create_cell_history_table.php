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
        // 2026_03_27_201222_create_cell_history_table (renamed to fix the migration
        // ordering). Without this guard that database would try to create it
        // a second time the first time it runs this file.
        if (Schema::hasTable('cell_history')) {
            return;
        }

        Schema::create('cell_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cell_id')->constrained('cells')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at');
            $table->index(['cell_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cell_history');
    }
};
