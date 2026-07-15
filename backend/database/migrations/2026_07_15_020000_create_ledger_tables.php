<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->string('normal_balance');
            $table->string('status')->default('active');
            $table->jsonb('bank_attributes')->nullable();
            $table->uuid('parent_account_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('entity_id')->references('id')->on('identity_entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'type']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('period_id')->nullable();
            $table->string('period_ref');
            $table->string('journal_number')->nullable();
            $table->string('entry_type')->default('manual');
            $table->date('entry_date');
            $table->string('state')->default('draft');
            $table->text('narration')->nullable();
            $table->string('reference')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->uuid('reversal_of_entry_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('entity_id')->references('id')->on('identity_entities')->cascadeOnDelete();
            $table->foreign('period_id')->references('id')->on('accounting_periods')->nullOnDelete();
            $table->index(['entity_id', 'period_ref']);
            $table->index(['entity_id', 'state']);
            $table->index(['source_document_id']);
            $table->index(['reversal_of_entry_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreign('reversal_of_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('journal_entry_id');
            $table->uuid('entity_id');
            $table->uuid('account_id');
            $table->unsignedSmallInteger('line_no');
            $table->text('description')->nullable();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->char('currency', 3);
            $table->decimal('fx_amount', 20, 4)->nullable();
            $table->uuid('rate_record_id')->nullable();
            $table->string('sbu_tag')->nullable();
            $table->timestampsTz();

            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['entity_id', 'account_id']);
            $table->index(['account_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_posted_journal_entry_mutation()
RETURNS trigger AS $$
BEGIN
    IF OLD.state = 'posted' THEN
        RAISE EXCEPTION 'posted journal entries are immutable';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_entries_immutable_when_posted
BEFORE UPDATE OR DELETE ON journal_entries
FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_entry_mutation();

CREATE OR REPLACE FUNCTION prevent_posted_journal_line_mutation()
RETURNS trigger AS $$
DECLARE parent_state text;
BEGIN
    SELECT state INTO parent_state FROM journal_entries WHERE id = OLD.journal_entry_id;
    IF parent_state = 'posted' THEN
        RAISE EXCEPTION 'posted journal lines are immutable';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_lines_immutable_when_parent_posted
BEFORE UPDATE OR DELETE ON journal_lines
FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_line_mutation();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS journal_lines_immutable_when_parent_posted ON journal_lines;
DROP FUNCTION IF EXISTS prevent_posted_journal_line_mutation();
DROP TRIGGER IF EXISTS journal_entries_immutable_when_posted ON journal_entries;
DROP FUNCTION IF EXISTS prevent_posted_journal_entry_mutation();
SQL);
        }

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
