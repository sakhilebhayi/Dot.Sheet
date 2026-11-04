<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 16);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['spreadsheet_id', 'id']);
        });

        Schema::table('spreadsheets', function (Blueprint $table) {
            $table->boolean('is_template')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheets', function (Blueprint $table) {
            $table->dropColumn('is_template');
        });
        Schema::dropIfExists('workbook_approvals');
    }
};
