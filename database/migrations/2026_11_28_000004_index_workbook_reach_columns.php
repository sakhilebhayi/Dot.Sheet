<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-4 (P-09): SQLite does not index foreign keys by itself. These are the columns the list, the search and the
 * policy reach workbooks by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spreadsheets', function (Blueprint $table) {
            $table->index(['owner_id', 'updated_at'], 'spreadsheets_owner_updated_index');
            $table->index(['team_id', 'updated_at'], 'spreadsheets_team_updated_index');
        });
        Schema::table('spreadsheet_user', function (Blueprint $table) {
            $table->index('user_id', 'spreadsheet_user_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheets', function (Blueprint $table) {
            $table->dropIndex('spreadsheets_owner_updated_index');
            $table->dropIndex('spreadsheets_team_updated_index');
        });
        Schema::table('spreadsheet_user', function (Blueprint $table) {
            $table->dropIndex('spreadsheet_user_user_index');
        });
    }
};
