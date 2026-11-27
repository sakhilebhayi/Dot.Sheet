<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-1 (S-02): deleting an account used to delete that person's comments and cell history on every workbook,
 * including colleagues' shared workbooks. Keep them, with the author cleared, as audit_events already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['comments', 'cell_history'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('user_id')->nullable()->change();
                $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['comments', 'cell_history'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
