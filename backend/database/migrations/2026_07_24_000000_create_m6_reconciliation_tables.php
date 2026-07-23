<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('ledger_account_id');
            $table->char('currency', 3);
            $table->string('display_name');
            $table->string('masked_bank_identifier')->nullable();
            $table->boolean('reconciliation_enabled')->default(true);
            $table->jsonb('column_mapping')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['entity_id', 'ledger_account_id']);
            $table->index(['entity_id', 'reconciliation_enabled']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('reconciliation_account_id');
            $table->string('period_ref', 16);
            $table->decimal('opening_balance', 20, 4);
            $table->decimal('closing_balance', 20, 4);
            $table->string('state', 32);
            $table->timestampTz('source_data_watermark')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->uuid('opened_by');
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['reconciliation_account_id', 'period_ref']);
            $table->index(['entity_id', 'state']);
        });

        Schema::create('reconciliation_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reconciliation_id');
            $table->uuid('reconciliation_account_id');
            $table->string('file_hash', 64);
            $table->jsonb('column_mapping')->nullable();
            $table->uuid('imported_by');
            $table->timestampTz('imported_at');
            $table->unsignedInteger('line_count');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['reconciliation_account_id', 'file_hash']);
            $table->index(['reconciliation_id']);
        });

        Schema::create('reconciliation_statement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reconciliation_id');
            $table->uuid('reconciliation_account_id');
            $table->uuid('import_batch_id');
            $table->string('source_line_identity');
            $table->date('transaction_date');
            $table->string('narration');
            $table->string('normalized_narration');
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3);
            $table->string('external_bank_reference')->nullable();
            $table->string('status', 16);
            $table->jsonb('matched_allocation_ids')->nullable();
            $table->uuid('resolved_by_journal_entry_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['reconciliation_id', 'status']);
            $table->index(['import_batch_id']);
        });

        Schema::create('reconciliation_match_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('statement_line_id');
            $table->unsignedInteger('rank');
            $table->decimal('total_amount', 20, 4);
            $table->char('currency', 3);
            $table->boolean('reference_match')->default(false);
            $table->boolean('superseded')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['statement_line_id', 'superseded']);
        });

        Schema::create('reconciliation_match_suggestion_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('suggestion_id');
            $table->uuid('allocation_id');

            $table->index(['suggestion_id']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE bank_reconciliations ADD CONSTRAINT bank_reconciliations_state_check CHECK (state IN ('Draft','InProgress','PendingCompletionApproval','Completed','Reopened'))");
            DB::statement("ALTER TABLE reconciliation_statement_lines ADD CONSTRAINT reconciliation_statement_lines_status_check CHECK (status IN ('Unreconciled','Suggested','Matched','Reconciled','Unexplained'))");
        }

        // API Contracts §14.5: duplicate identity is null-safe on external_bank_reference and
        // scoped to the whole ReconciliationAccount's history, not just the current batch/import.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX reconciliation_statement_lines_duplicate_identity ON reconciliation_statement_lines (reconciliation_account_id, transaction_date, amount, currency, normalized_narration, COALESCE(external_bank_reference, ''))");
        }

        if (DB::getDriverName() === 'pgsql') {
            // API Contracts §14.9: opening/closing balance and reproducibility fields are the
            // bank statement's own declared facts, fixed once the batch leaves Draft. Rows are
            // never deleted once real work has started. Reopen only ever changes
            // state/version/reopened_by/reopened_at/updated_at and (on a later re-completion)
            // completed_by/completed_at/source_data_watermark/content_hash — never the balances.
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_bank_reconciliation_fact() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'BankReconciliation facts are immutable and may not be deleted';
    END IF;
    IF OLD.state <> 'Draft' THEN
        IF NEW.entity_id IS DISTINCT FROM OLD.entity_id
            OR NEW.reconciliation_account_id IS DISTINCT FROM OLD.reconciliation_account_id
            OR NEW.period_ref IS DISTINCT FROM OLD.period_ref
            OR NEW.opening_balance IS DISTINCT FROM OLD.opening_balance
            OR NEW.closing_balance IS DISTINCT FROM OLD.closing_balance
        THEN
            RAISE EXCEPTION 'BankReconciliation statement facts are immutable once the batch leaves Draft';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_bank_reconciliations BEFORE UPDATE OR DELETE ON bank_reconciliations FOR EACH ROW EXECUTE FUNCTION protect_bank_reconciliation_fact();

-- API Contracts §14.7/§14.9: a Reconciled line is immutable — Reopen never reverts a
-- Reconciled line's own status, so once reached it never changes again.
CREATE OR REPLACE FUNCTION protect_reconciliation_statement_line_fact() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'Reconciled' THEN
        RAISE EXCEPTION 'A Reconciled statement line is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_reconciliation_statement_lines BEFORE UPDATE OR DELETE ON reconciliation_statement_lines FOR EACH ROW EXECUTE FUNCTION protect_reconciliation_statement_line_fact();

-- API Contracts §14.5: import batches (file hash, source-line identity) are append-only.
CREATE OR REPLACE FUNCTION protect_reconciliation_import_batch() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Reconciliation import batches are append-only';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_reconciliation_import_batches BEFORE UPDATE OR DELETE ON reconciliation_import_batches FOR EACH ROW EXECUTE FUNCTION protect_reconciliation_import_batch();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_bank_reconciliation_fact() CASCADE;');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_reconciliation_statement_line_fact() CASCADE;');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_reconciliation_import_batch() CASCADE;');
        }
        Schema::dropIfExists('reconciliation_match_suggestion_allocations');
        Schema::dropIfExists('reconciliation_match_suggestions');
        Schema::dropIfExists('reconciliation_statement_lines');
        Schema::dropIfExists('reconciliation_import_batches');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('reconciliation_accounts');
    }
};
