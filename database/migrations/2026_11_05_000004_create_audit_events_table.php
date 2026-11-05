<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who did what to a workbook. Rows are only added, and outlive the workbook they name. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('spreadsheet_id');
            $table->string('spreadsheet_name');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('action', 60);
            $table->string('detail', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['team_id', 'id']);
            $table->index(['owner_id', 'id']);
            $table->index(['spreadsheet_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
