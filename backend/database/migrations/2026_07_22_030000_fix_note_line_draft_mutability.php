<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Database Design "credit_note_line": "Unique(note, source line); immutable after
        // post." The trigger installed in 2026_07_22_010000 used the blanket
        // protect_m4a_note_fact() function shared with the append-only disposition/
        // application/reversal tables, which unconditionally blocks UPDATE/DELETE — that is
        // correct for those (they only ever exist once posted) but wrong for lines, which
        // API Contracts §12.2 requires stay editable while the note is Draft. This replaces
        // only the *_lines triggers with a parent-state-aware function; dispositions/
        // applications/reversals keep the original unconditional protection unchanged.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_m4a_note_line_fact() RETURNS trigger AS $$
DECLARE
    parent_state text;
BEGIN
    IF TG_TABLE_NAME = 'receivables_credit_note_lines' THEN
        SELECT state INTO parent_state FROM receivables_credit_notes WHERE id = OLD.credit_note_id;
    ELSE
        SELECT state INTO parent_state FROM payables_debit_notes WHERE id = OLD.debit_note_id;
    END IF;
    IF parent_state IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Posted note lines are immutable';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS protect_receivables_credit_note_lines ON receivables_credit_note_lines;
CREATE TRIGGER protect_receivables_credit_note_lines BEFORE UPDATE OR DELETE ON receivables_credit_note_lines FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_line_fact();

DROP TRIGGER IF EXISTS protect_payables_debit_note_lines ON payables_debit_note_lines;
CREATE TRIGGER protect_payables_debit_note_lines BEFORE UPDATE OR DELETE ON payables_debit_note_lines FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_line_fact();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS protect_receivables_credit_note_lines ON receivables_credit_note_lines;
CREATE TRIGGER protect_receivables_credit_note_lines BEFORE UPDATE OR DELETE ON receivables_credit_note_lines FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();

DROP TRIGGER IF EXISTS protect_payables_debit_note_lines ON payables_debit_note_lines;
CREATE TRIGGER protect_payables_debit_note_lines BEFORE UPDATE OR DELETE ON payables_debit_note_lines FOR EACH ROW EXECUTE FUNCTION protect_m4a_note_fact();

DROP FUNCTION IF EXISTS protect_m4a_note_line_fact() CASCADE;
SQL);
    }
};
