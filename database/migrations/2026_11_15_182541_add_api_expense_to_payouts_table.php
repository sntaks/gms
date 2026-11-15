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
        Schema::table('payouts', function (Blueprint $table) {
            // The Kadi API expense recorded for a completed payout, or why recording it failed.
            $table->unsignedBigInteger('api_expense_id')->nullable()->after('expense_id');
            $table->string('api_expense_error')->nullable()->after('api_expense_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['api_expense_id', 'api_expense_error']);
        });
    }
};
