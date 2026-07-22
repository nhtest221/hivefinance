<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M4-GOV-001: exact period states are `Open`, `SoftClosed`, `HardClosed`, `Reopened`.
        // Pre-M4 code used a provisional lowercase representation ('open', 'soft_closed');
        // this migration adopts the frozen casing as the M4 lifecycle is implemented.
        DB::table('accounting_periods')->where('state', 'open')->update(['state' => 'Open']);
        DB::table('accounting_periods')->where('state', 'soft_closed')->update(['state' => 'SoftClosed']);
        DB::table('accounting_periods')->where('state', 'hard_closed')->update(['state' => 'HardClosed']);
        DB::table('accounting_periods')->where('vat_lock_status', 'open')->update(['vat_lock_status' => 'unlocked']);

        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->string('state')->default('Open')->change();
            $table->boolean('reclose_required')->default(false)->after('vat_lock_status');
            $table->string('close_evidence_set_hash')->nullable()->after('reclose_required');
            $table->timestampTz('hard_closed_at')->nullable()->after('close_evidence_set_hash');
            $table->uuid('hard_closed_by')->nullable()->after('hard_closed_at');
        });

        Schema::table('period_transitions', function (Blueprint $table): void {
            $table->text('narrative')->nullable()->after('reason_code');
            $table->string('vat_status_before')->nullable()->after('narrative');
            $table->string('vat_status_after')->nullable()->after('vat_status_before');
            $table->unsignedInteger('version_before')->nullable()->after('vat_status_after');
            $table->unsignedInteger('version_after')->nullable()->after('version_before');
            $table->uuid('approval_id')->nullable()->after('approver_id');
            $table->uuid('correlation_id')->nullable()->after('approval_id');
            $table->uuid('causation_id')->nullable()->after('correlation_id');
            $table->boolean('reclose_required')->default(false)->after('causation_id');
        });

        Schema::create('period_close_gate_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('period_id');
            $table->uuid('close_attempt_id');
            $table->string('gate_type', 64);
            $table->string('status', 16);
            $table->string('source_context', 32)->nullable();
            $table->string('source_reference')->nullable();
            $table->timestampTz('produced_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->unsignedInteger('evidence_version')->nullable();
            $table->string('evidence_hash')->nullable();
            $table->string('accepted_set_hash')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('period_id')->references('id')->on('accounting_periods')->cascadeOnDelete();
            $table->unique(['period_id', 'close_attempt_id', 'gate_type'], 'period_gate_attempt_unique');
            $table->index(['entity_id', 'period_id', 'gate_type']);
        });

        Schema::create('period_late_adjustment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('original_period_id');
            $table->uuid('posting_period_id');
            $table->uuid('source_document_id')->nullable();
            $table->uuid('correction_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->string('reason_code');
            $table->string('tax_snapshot_hash')->nullable();
            $table->string('rate_record_hash')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('original_period_id')->references('id')->on('accounting_periods');
            $table->foreign('posting_period_id')->references('id')->on('accounting_periods');
            $table->index(['entity_id', 'original_period_id']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_state_check CHECK (state IN ('Open','SoftClosed','HardClosed','Reopened'))");
            DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_vat_check CHECK (vat_lock_status IN ('unlocked','locked','unlocked_for_approved_adjustments'))");
            DB::statement("ALTER TABLE period_close_gate_evidence ADD CONSTRAINT period_close_gate_evidence_status_check CHECK (status IN ('satisfied','unmet'))");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_m4_period_fact() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Period transition and close-gate facts are immutable'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER protect_period_transitions BEFORE UPDATE OR DELETE ON period_transitions FOR EACH ROW EXECUTE FUNCTION protect_m4_period_fact();
CREATE TRIGGER protect_period_close_gate_evidence BEFORE UPDATE OR DELETE ON period_close_gate_evidence FOR EACH ROW EXECUTE FUNCTION protect_m4_period_fact();
CREATE TRIGGER protect_period_late_adjustment_links BEFORE UPDATE OR DELETE ON period_late_adjustment_links FOR EACH ROW EXECUTE FUNCTION protect_m4_period_fact();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_m4_period_fact() CASCADE;');
        }
        Schema::dropIfExists('period_late_adjustment_links');
        Schema::dropIfExists('period_close_gate_evidence');

        Schema::table('period_transitions', function (Blueprint $table): void {
            $table->dropColumn(['narrative', 'vat_status_before', 'vat_status_after', 'version_before', 'version_after', 'approval_id', 'correlation_id', 'causation_id', 'reclose_required']);
        });

        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->dropColumn(['reclose_required', 'close_evidence_set_hash', 'hard_closed_at', 'hard_closed_by']);
        });
    }
};
