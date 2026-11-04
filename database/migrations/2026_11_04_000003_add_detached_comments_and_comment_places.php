<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A comment whose row or column was deleted keeps its words but has no cell. A copy of a sheet
     * remembers where its comments were, so putting the copy back puts them back too.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('detached')->default(false)->after('resolved');
        });

        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->json('comment_places')->nullable()->after('layout');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_versions', function (Blueprint $table) {
            $table->dropColumn('comment_places');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('detached');
        });
    }
};
