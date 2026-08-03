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
