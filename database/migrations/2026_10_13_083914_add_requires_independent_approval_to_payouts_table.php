<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing payouts default to requiring an independent checker, preserving the maker-checker rule they were created under.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->boolean('requires_independent_approval')->default(true)->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn('requires_independent_approval');
        });
    }
};
