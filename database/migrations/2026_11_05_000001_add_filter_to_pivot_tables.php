<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pivot_tables', function (Blueprint $table) {
            $table->string('filter_field')->nullable()->after('value_field');
            $table->string('filter_value')->nullable()->after('filter_field');
        });
    }

    public function down(): void
    {
        Schema::table('pivot_tables', function (Blueprint $table) {
            $table->dropColumn(['filter_field', 'filter_value']);
        });
    }
};
