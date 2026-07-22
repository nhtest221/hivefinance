<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Database Design §"receivables"/"payables": credit_note/debit_note mirror each
        // other exactly except for direction (party column, source document column/table,
        // and the source document's own party column).
        foreach ([
            'receivables' => ['credit_note', 'invoice', 'customer_id', 'source_invoice_id'],
            'payables' => ['debit_note', 'bill', 'vendor_id', 'source_bill_id'],
        ] as $schema => [$noteKind, $sourceKind, $partyColumn, $sourceColumn]) {
            $notesTable = $schema.'_'.$noteKind.'s';
            $sourceTable = $schema.'_'.$sourceKind.'s';

            Schema::create($notesTable, function (Blueprint $table) use ($partyColumn, $sourceColumn, $sourceTable): void {
                $table->uuid('id')->primary();
                $table->uuid('entity_id')->index();
                $table->string('document_number')->nullable();
                $table->uuid('provisional_token')->nullable();
                $table->uuid($partyColumn)->index();
                $table->uuid($sourceColumn)->index();
                $table->unsignedInteger('source_document_expected_version');
                $table->date('note_date');
                $table->char('currency', 3);
                $table->string('reason_code');
                $table->text('narrative')->nullable();
                $table->uuid('source_rate_record_id')->nullable();
                $table->json('source_exchange_rate_reference')->nullable();
                // Draft-stage computed total (Database Design: "proposed_total"), distinct
                // from the immutable posted_amount set only once at /post.
                $table->decimal('proposed_total', 20, 4)->nullable();
                $table->decimal('posted_amount', 20, 4)->default(0);
                $table->decimal('applied_amount', 20, 4)->default(0);
                $table->decimal('refunded_amount', 20, 4)->default(0);
                $table->decimal('held_remaining_amount', 20, 4)->default(0);
                $table->decimal('undisposed_amount', 20, 4)->default(0);
                $table->string('state', 16)->default('draft');
                $table->string('period_ref')->nullable();
                $table->json('journal_entry_ids')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->uuid('created_by');
                $table->timestampsTz();

                $table->foreign($sourceColumn)->references('id')->on($sourceTable);
                $table->unique(['entity_id', 'document_number']);
                $table->index(['entity_id', $partyColumn, 'state']);
                $table->index(['entity_id', $sourceColumn]);
                $table->index(['entity_id', 'note_date', 'id']);
            });

            Schema::create($schema.'_'.$noteKind.'_lines', function (Blueprint $table) use ($notesTable, $noteKind): void {
                $table->uuid('id')->primary();
                $table->uuid($noteKind.'_id');
                $table->uuid('entity_id');
                $table->uuid('source_line_id');
                $table->unsignedInteger('line_no');
                $table->text('description')->nullable();
                $table->decimal('net_amount', 20, 4);
                $table->json('tax_snapshot')->nullable();
                $table->decimal('tax_amount', 20, 4)->default(0);
                $table->decimal('total_amount', 20, 4);
                $table->timestampsTz();

                $table->foreign($noteKind.'_id')->references('id')->on($notesTable)->cascadeOnDelete();
                $table->unique([$noteKind.'_id', 'source_line_id']);
            });

            Schema::create($schema.'_'.$noteKind.'_dispositions', function (Blueprint $table) use ($notesTable, $noteKind): void {
                $table->uuid('id')->primary();
                $table->uuid($noteKind.'_id');
                $table->uuid('entity_id');
                $table->string('operation', 16);
                // Transaction-currency value; presented as `transferred_amount` in API
                // responses/events for hold operations specifically (API Contracts §12.2),
                // `amount` here is the single DB fact both operations share.
                $table->decimal('amount', 20, 4);
                $table->decimal('functional_amount', 20, 4);
                $table->date('occurred_on');
                $table->uuid('actor_id');
                $table->uuid('correlation_id')->nullable();
                $table->uuid('causation_id')->nullable();
                $table->uuid('settlement_allocation_id')->nullable();
                $table->json('credit_tranche_ids')->nullable();
                $table->uuid('reverses_disposition_id')->nullable();
                $table->timestampTz('created_at');

                $table->foreign($noteKind.'_id')->references('id')->on($notesTable)->cascadeOnDelete();
                $table->index(['entity_id', $noteKind.'_id', 'created_at']);
            });

            $dispositionsTable = $schema.'_'.$noteKind.'_dispositions';
            Schema::create($schema.'_'.$noteKind.'_applications', function (Blueprint $table) use ($notesTable, $noteKind, $sourceTable, $dispositionsTable): void {
                $table->uuid('id')->primary();
                $table->uuid($noteKind.'_id');
                $table->uuid('disposition_id');
                $table->uuid('entity_id');
                $table->uuid('target_document_id');
                $table->decimal('amount', 20, 4);
                $table->decimal('functional_amount', 20, 4);
                $table->unsignedInteger('target_version_before');
                $table->unsignedInteger('target_version_after');
                $table->uuid('source_rate_record_id')->nullable();
                $table->uuid('comparison_rate_record_id')->nullable();
                $table->uuid('reversal_of_id')->nullable();
                $table->timestampsTz();

                $table->foreign($noteKind.'_id')->references('id')->on($notesTable)->cascadeOnDelete();
                $table->foreign('disposition_id')->references('id')->on($dispositionsTable);
                $table->foreign('target_document_id')->references('id')->on($sourceTable);
                // Database Design: "unique effective application identity".
                $table->unique(['disposition_id', 'target_document_id']);
                $table->index(['entity_id', 'target_document_id']);
            });

            Schema::create($schema.'_'.$noteKind.'_reversals', function (Blueprint $table) use ($notesTable, $noteKind): void {
                $table->uuid('id')->primary();
                $table->uuid($noteKind.'_id')->unique();
                $table->uuid('entity_id');
                $table->date('reversal_date');
                $table->string('reason_code');
                $table->text('narrative');
                $table->string('impact_graph_hash');
                $table->json('journal_entry_ids')->nullable();
                $table->uuid('actor_id');
                $table->timestampTz('reversed_at');
                $table->timestampsTz();

                $table->foreign($noteKind.'_id')->references('id')->on($notesTable);
            });

            if (DB::getDriverName() !== 'sqlite') {
                DB::statement("ALTER TABLE {$notesTable} ADD CONSTRAINT {$notesTable}_state_check CHECK (state IN ('draft','posted','reversed'))");
                DB::statement("ALTER TABLE {$notesTable} ADD CONSTRAINT {$notesTable}_amounts_check CHECK (posted_amount >= 0 AND applied_amount >= 0 AND refunded_amount >= 0 AND held_remaining_amount >= 0 AND undisposed_amount >= 0 AND (state <> 'posted' OR posted_amount = applied_amount + refunded_amount + held_remaining_amount + undisposed_amount))");
                DB::statement("ALTER TABLE {$schema}_{$noteKind}_dispositions ADD CONSTRAINT {$schema}_{$noteKind}_dispositions_check CHECK (amount > 0 AND operation IN ('hold','apply','refund','restoration'))");
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_m4a_note_fact() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'M4 note facts are immutable'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER protect_receivables_credit_note_lines BEFORE UPDATE OR DELETE ON receivables_credit_note_lines FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_receivables_credit_note_dispositions BEFORE UPDATE OR DELETE ON receivables_credit_note_dispositions FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_receivables_credit_note_applications BEFORE UPDATE OR DELETE ON receivables_credit_note_applications FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_receivables_credit_note_reversals BEFORE UPDATE OR DELETE ON receivables_credit_note_reversals FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_payables_debit_note_lines BEFORE UPDATE OR DELETE ON payables_debit_note_lines FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_payables_debit_note_dispositions BEFORE UPDATE OR DELETE ON payables_debit_note_dispositions FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_payables_debit_note_applications BEFORE UPDATE OR DELETE ON payables_debit_note_applications FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE TRIGGER protect_payables_debit_note_reversals BEFORE UPDATE OR DELETE ON payables_debit_note_reversals FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();
CREATE OR REPLACE FUNCTION protect_posted_m4a_note() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND OLD.state <> 'draft' THEN RAISE EXCEPTION 'Posted notes are immutable'; END IF;
    IF TG_OP = 'UPDATE' AND OLD.state <> 'draft'
       AND (to_jsonb(NEW) - 'applied_amount' - 'refunded_amount' - 'held_remaining_amount' - 'undisposed_amount' - 'state' - 'version' - 'updated_at')
           IS DISTINCT FROM (to_jsonb(OLD) - 'applied_amount' - 'refunded_amount' - 'held_remaining_amount' - 'undisposed_amount' - 'state' - 'version' - 'updated_at') THEN
        RAISE EXCEPTION 'Posted notes are immutable';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_receivables_credit_note BEFORE UPDATE OR DELETE ON receivables_credit_notes FOR EACH ROW EXECUTE FUNCTION protect_posted_m4a_note();
CREATE TRIGGER protect_payables_debit_note BEFORE UPDATE OR DELETE ON payables_debit_notes FOR EACH ROW EXECUTE FUNCTION protect_posted_m4a_note();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_posted_m4a_note() CASCADE; DROP FUNCTION IF EXISTS protect_m4a_note_fact() CASCADE;');
        }
        foreach (['receivables' => 'credit_note', 'payables' => 'debit_note'] as $schema => $noteKind) {
            Schema::dropIfExists($schema.'_'.$noteKind.'_reversals');
            Schema::dropIfExists($schema.'_'.$noteKind.'_applications');
            Schema::dropIfExists($schema.'_'.$noteKind.'_dispositions');
            Schema::dropIfExists($schema.'_'.$noteKind.'_lines');
            Schema::dropIfExists($schema.'_'.$noteKind.'s');
        }
    }
};
