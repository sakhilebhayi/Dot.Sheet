<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A row with no cells named protects the whole sheet.
        Schema::create('sheet_protections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_tab_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 80)->nullable();
            $table->unsignedInteger('start_row')->nullable();
            $table->unsignedInteger('start_col')->nullable();
            $table->unsignedInteger('end_row')->nullable();
            $table->unsignedInteger('end_col')->nullable();
            $table->timestamps();
            $table->index('sheet_tab_id');
        });

        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->json('protection_places')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->dropColumn('protection_places');
        });
        Schema::dropIfExists('sheet_protections');
    }
};
