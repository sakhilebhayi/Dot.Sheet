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
        Schema::table('cells', function (Blueprint $table) {
            // Replace existing formatting column with an expanded version if not already present
            if (! Schema::hasColumn('cells', 'formatting')) {
                $table->json('formatting')->nullable()->after('formula');
            }
            // Add note/comment for quick cell annotations
            if (! Schema::hasColumn('cells', 'note')) {
                $table->string('note')->nullable()->after('formatting');
            }
            // Validation rule stored per cell
            if (! Schema::hasColumn('cells', 'validation')) {
                $table->json('validation')->nullable()->after('note');
            }
            // Conditional formatting rules
            if (! Schema::hasColumn('cells', 'conditional_formats')) {
                $table->json('conditional_formats')->nullable()->after('validation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cells', function (Blueprint $table) {
            $table->dropColumn(['note', 'validation', 'conditional_formats']);
        });
    }
};
