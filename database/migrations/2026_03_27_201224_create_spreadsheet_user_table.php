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
        // 2026_03_27_201222_create_spreadsheet_user_table (renamed to fix the migration
        // ordering). Without this guard that database would try to create it
        // a second time the first time it runs this file.
        if (Schema::hasTable('spreadsheet_user')) {
            return;
        }

        Schema::create('spreadsheet_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained('spreadsheets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('permission', ['view', 'comment', 'edit', 'admin'])->default('view');
            $table->timestamps();
            $table->unique(['spreadsheet_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_user');
    }
};
