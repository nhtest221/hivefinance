<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->unique(['entity_id', 'reversal_of_entry_id'], 'journal_single_reversal_unique');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->char('fx_currency', 3)->nullable()->after('fx_amount');
            $table->decimal('fx_rate', 20, 8)->nullable()->after('rate_record_id');
            $table->date('fx_rate_effective_date')->nullable()->after('fx_rate');
        });

        Schema::create('ledger_account_balance_projections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('entity_id');
            $table->uuid('account_id');
            $table->date('as_of');
            $table->decimal('balance', 20, 4);
            $table->char('currency', 3);
            $table->timestampsTz();
            $table->unique(['entity_id', 'account_id', 'as_of'], 'ledger_balance_projection_unique');
        });

        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('code', 32);
            $table->string('name');
            $table->string('jurisdiction', 32);
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'jurisdiction', 'status']);
        });

        Schema::create('tax_code_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_code_id');
            $table->uuid('entity_id');
            $table->unsignedInteger('version_number');
            $table->string('treatment');
            $table->decimal('rate', 20, 8);
            $table->boolean('recoverable');
            $table->string('calculation_method');
            $table->jsonb('gl_mapping');
            $table->jsonb('return_box_mapping');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('referenced')->default(false);
            $table->timestampsTz();
            $table->foreign('tax_code_id')->references('id')->on('tax_codes')->cascadeOnDelete();
            $table->unique(['tax_code_id', 'version_number']);
            $table->index(['entity_id', 'tax_code_id', 'effective_from']);
        });

        Schema::create('tax_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('jurisdiction', 32);
            $table->string('name');
            $table->jsonb('tax_code_ids');
            $table->jsonb('return_template');
            $table->jsonb('policy');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['entity_id', 'jurisdiction']);
        });

        Schema::create('fx_rate_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->date('effective_date');
            $table->string('source');
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->boolean('referenced')->default(false);
            $table->timestampsTz();
            $table->unique(['entity_id', 'base_currency', 'quote_currency', 'effective_date', 'source'], 'fx_rate_fact_unique');
            $table->index(['entity_id', 'base_currency', 'quote_currency', 'effective_date']);
        });

        Schema::create('fx_revaluation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('period_ref');
            $table->string('status');
            $table->jsonb('figures');
            $table->jsonb('rate_record_ids');
            $table->jsonb('journal_entry_ids');
            $table->string('reversal_status')->default('scheduled');
            $table->string('target_period_ref')->nullable();
            $table->uuid('reversal_run_id')->nullable();
            $table->jsonb('reversal_journal_entry_ids');
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['entity_id', 'period_ref']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_fx_rate_record()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'FX rate records are append-only';
    END IF;
    IF OLD.referenced = false AND NEW.referenced = true
       AND ROW(OLD.entity_id, OLD.base_currency, OLD.quote_currency, OLD.rate, OLD.effective_date, OLD.source, OLD.is_override, OLD.override_reason)
         IS NOT DISTINCT FROM ROW(NEW.entity_id, NEW.base_currency, NEW.quote_currency, NEW.rate, NEW.effective_date, NEW.source, NEW.is_override, NEW.override_reason) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'FX rate records are immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER fx_rate_record_immutable
BEFORE UPDATE OR DELETE ON fx_rate_records
FOR EACH ROW EXECUTE FUNCTION protect_fx_rate_record();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS fx_rate_record_immutable ON fx_rate_records; DROP FUNCTION IF EXISTS protect_fx_rate_record();');
        }
        Schema::dropIfExists('fx_revaluation_runs');
        Schema::dropIfExists('fx_rate_records');
        Schema::dropIfExists('tax_packs');
        Schema::dropIfExists('tax_code_versions');
        Schema::dropIfExists('tax_codes');
        Schema::dropIfExists('ledger_account_balance_projections');
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropColumn(['fx_currency', 'fx_rate', 'fx_rate_effective_date']);
        });
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropUnique('journal_single_reversal_unique'));
        Schema::table('ledger_accounts', fn (Blueprint $table) => $table->dropColumn('description'));
    }
};
