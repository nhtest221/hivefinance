<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026_07_20_000000_create_m2_document_tables protects receivables_invoices,
     * payables_bills, and payables_expenses, but never protected the invoice/bill line
     * and SBU-allocation tables — those stayed freely UPDATE/DELETE-able at the DB level
     * even after the parent document left 'draft', identical to the gap
     * 2026_07_22_030000_fix_note_line_draft_mutability closed for M4A note lines. This adds
     * the same parent-state-aware pattern here: mutable while the parent is 'draft',
     * immutable once it is not.
     *
     * 2026_07_16_100000_create_m1_ledger_valuation_tables protected fx_rate_records and
     * tax_code_versions but never fx_revaluation_runs, which is posted immediately at
     * creation (no draft phase) and should be immutable from that point on except for the
     * fields its own lifecycle legitimately updates afterward: journal_entry_ids (set in a
     * second UPDATE within the same creation transaction, since the run needs its own id
     * before the journal it references can be posted) and the reversal fields FxService::
     * reverseRevaluation() sets (status, reversal_status, reversal_run_id,
     * reversal_journal_entry_ids, reversed_at, version).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_m2_document_line_fact() RETURNS trigger AS $$
DECLARE
    parent_status text;
BEGIN
    IF TG_TABLE_NAME = 'receivables_invoice_lines' THEN
        SELECT status INTO parent_status FROM receivables_invoices WHERE id = OLD.invoice_id;
    ELSE
        SELECT status INTO parent_status FROM payables_bills WHERE id = OLD.bill_id;
    END IF;
    IF parent_status IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Recognized document lines are immutable';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER protect_receivables_invoice_lines BEFORE UPDATE OR DELETE ON receivables_invoice_lines FOR EACH ROW EXECUTE FUNCTION protect_m2_document_line_fact();
CREATE TRIGGER protect_payables_bill_lines BEFORE UPDATE OR DELETE ON payables_bill_lines FOR EACH ROW EXECUTE FUNCTION protect_m2_document_line_fact();
CREATE TRIGGER protect_payables_bill_sbu_allocations BEFORE UPDATE OR DELETE ON payables_bill_sbu_allocations FOR EACH ROW EXECUTE FUNCTION protect_m2_document_line_fact();

CREATE OR REPLACE FUNCTION protect_fx_revaluation_run() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'FX revaluation runs are immutable';
    END IF;
    IF (to_jsonb(NEW) - 'status' - 'journal_entry_ids' - 'reversal_status' - 'reversal_run_id' - 'reversal_journal_entry_ids' - 'reversed_at' - 'version' - 'updated_at')
       IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'journal_entry_ids' - 'reversal_status' - 'reversal_run_id' - 'reversal_journal_entry_ids' - 'reversed_at' - 'version' - 'updated_at') THEN
        RAISE EXCEPTION 'FX revaluation runs are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_fx_revaluation_run BEFORE UPDATE OR DELETE ON fx_revaluation_runs FOR EACH ROW EXECUTE FUNCTION protect_fx_revaluation_run();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS protect_fx_revaluation_run ON fx_revaluation_runs;
DROP FUNCTION IF EXISTS protect_fx_revaluation_run() CASCADE;
DROP TRIGGER IF EXISTS protect_payables_bill_sbu_allocations ON payables_bill_sbu_allocations;
DROP TRIGGER IF EXISTS protect_payables_bill_lines ON payables_bill_lines;
DROP TRIGGER IF EXISTS protect_receivables_invoice_lines ON receivables_invoice_lines;
DROP FUNCTION IF EXISTS protect_m2_document_line_fact() CASCADE;
SQL);
    }
};
