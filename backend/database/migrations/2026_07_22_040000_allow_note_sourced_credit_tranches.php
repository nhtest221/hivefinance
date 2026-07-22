<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M4-GOV-001 extends M3 CreditTranche ownership to note-originated holds
        // (API Contracts §12.3.7 "Moves undisposed value into immutable M3 customer
        // CreditTranches... no Ledger movement"). A hold is neither a cash movement nor a
        // document application, so it has no natural settlement_allocations row to anchor
        // to — unlike Apply (which reuses the existing 'credit_application' operation,
        // already exempted from the bank-equation CHECK) and Refund (a real cash movement
        // that fits the schema unchanged). This makes source_allocation_id optional and
        // adds an alternate note-owned source reference; at least one source is required.
        Schema::table('settlement_credit_tranches', function (Blueprint $table): void {
            $table->uuid('source_allocation_id')->nullable()->change();
            $table->uuid('source_note_id')->nullable()->after('source_allocation_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE settlement_credit_tranches ADD CONSTRAINT settlement_credit_tranches_source_check CHECK (source_allocation_id IS NOT NULL OR source_note_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE settlement_credit_tranches DROP CONSTRAINT IF EXISTS settlement_credit_tranches_source_check');
        }
        Schema::table('settlement_credit_tranches', function (Blueprint $table): void {
            $table->dropColumn('source_note_id');
        });
        Schema::table('settlement_credit_tranches', function (Blueprint $table): void {
            $table->uuid('source_allocation_id')->nullable(false)->change();
        });
    }
};
