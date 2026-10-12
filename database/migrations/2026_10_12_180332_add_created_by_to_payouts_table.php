<?php

use App\Models\Payout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users');
        });

        /**
         * Backfill the maker of existing payouts from the audit trail written by the Auditable trait.
         * Payouts with no "created" audit entry keep a null maker and cannot be approved.
         */
        DB::table('payouts')
            ->whereNull('created_by')
            ->update([
                'created_by' => DB::table('audit_logs')
                    ->select('audit_logs.user_id')
                    ->where('audit_logs.auditable_type', Payout::class)
                    ->whereColumn('audit_logs.auditable_id', 'payouts.id')
                    ->where('audit_logs.event', 'created')
                    ->orderBy('audit_logs.id')
                    ->limit(1),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
