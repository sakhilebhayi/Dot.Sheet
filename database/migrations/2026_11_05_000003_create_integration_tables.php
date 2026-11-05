<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox for calls to other Dot platforms: each call is written here first, never sent inside a person's
 * request, keyed so the same thing is never sent twice. And where a workbook has been filed in Dot.Files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 20);
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('key', 190);
            $table->text('payload');
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['integration', 'key']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['team_id', 'integration', 'status']);
        });

        Schema::create('workbook_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->uuid('file_uuid');
            $table->unsignedInteger('version')->nullable();
            $table->string('open_url', 500)->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_filings');
        Schema::dropIfExists('integration_deliveries');
    }
};
