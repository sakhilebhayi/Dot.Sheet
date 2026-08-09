<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_prune_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('backup_path');
            $table->timestamp('backup_created_at');
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'backup_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_prune_proposals');
    }
};
