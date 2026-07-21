<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->string('allocation_number')->nullable();
            $table->string('operation', 32);
            $table->string('party_type', 16);
            $table->uuid('party_id');
            $table->date('settlement_date');
            $table->uuid('bank_account_id')->nullable();
            $table->char('currency', 3);
            $table->decimal('gross_amount', 20, 4)->default(0);
            $table->decimal('bank_amount', 20, 4)->default(0);
            $table->decimal('withholding_amount', 20, 4)->default(0);
            $table->decimal('allocated_amount', 20, 4)->default(0);
            $table->decimal('unapplied_amount', 20, 4)->default(0);
            $table->decimal('functional_gross_amount', 20, 4)->default(0);
            $table->uuid('rate_record_id')->nullable();
            $table->json('exchange_rate_reference')->nullable();
            $table->json('journal_entry_ids');
            $table->string('state', 16)->default('posted');
            $table->uuid('reversal_of_id')->nullable();
            $table->uuid('reversed_by_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('posted_at');
            $table->timestampsTz();
            $table->unique(['entity_id', 'allocation_number']);
            $table->unique('reversal_of_id');
            $table->index(['entity_id', 'party_type', 'party_id', 'settlement_date', 'id'], 'settlement_party_date_idx');
            $table->index(['entity_id', 'operation', 'state', 'settlement_date', 'id'], 'settlement_operation_state_idx');
            $table->index(['entity_id', 'bank_account_id', 'settlement_date'], 'settlement_bank_date_idx');
        });

        Schema::create('settlement_allocation_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('allocation_id');
            $table->string('document_type', 16);
            $table->uuid('document_id');
            $table->string('document_number');
            $table->uuid('document_party_id');
            $table->uuid('credit_tranche_id')->nullable();
            $table->decimal('applied_amount', 20, 4);
            $table->unsignedInteger('expected_version');
            $table->decimal('open_balance_before', 20, 4);
            $table->decimal('open_balance_after', 20, 4);
            $table->unsignedInteger('version_before');
            $table->unsignedInteger('version_after');
            $table->string('status_before', 24);
            $table->string('status_after', 24);
            $table->uuid('document_rate_record_id')->nullable();
            $table->json('realised_fx_result')->nullable();
            $table->timestampsTz();
            $table->foreign('allocation_id')->references('id')->on('settlement_allocations')->cascadeOnDelete();
            $table->index(['entity_id', 'document_type', 'document_id'], 'settlement_document_link_idx');
            $table->index(['entity_id', 'credit_tranche_id']);
        });

        Schema::create('settlement_withholding_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('allocation_id');
            $table->string('withholding_code');
            $table->decimal('amount', 20, 4);
            $table->json('tax_snapshot')->nullable();
            $table->json('configuration_reference')->nullable();
            $table->uuid('account_id');
            $table->timestampsTz();
            $table->foreign('allocation_id')->references('id')->on('settlement_allocations')->cascadeOnDelete();
            $table->index(['entity_id', 'withholding_code']);
        });

        Schema::create('settlement_credit_tranches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('party_type', 16);
            $table->uuid('party_id');
            $table->char('currency', 3);
            $table->decimal('original_amount', 20, 4);
            $table->decimal('remaining_amount', 20, 4);
            $table->decimal('original_functional_amount', 20, 4);
            $table->decimal('remaining_functional_amount', 20, 4);
            $table->uuid('source_rate_record_id')->nullable();
            $table->json('source_exchange_rate_reference')->nullable();
            $table->uuid('source_allocation_id');
            $table->string('source_reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign('source_allocation_id')->references('id')->on('settlement_allocations');
            $table->index(['entity_id', 'party_type', 'party_id', 'currency', 'created_at', 'id'], 'settlement_credit_party_cursor_idx');
        });

        DB::statement('CREATE INDEX settlement_credit_available_idx ON settlement_credit_tranches (entity_id, party_type, party_id, currency) WHERE remaining_amount > 0');

        Schema::create('settlement_credit_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('credit_tranche_id');
            $table->uuid('allocation_id');
            $table->string('operation', 16);
            $table->decimal('amount', 20, 4);
            $table->decimal('functional_amount', 20, 4);
            $table->uuid('source_rate_record_id')->nullable();
            $table->uuid('comparison_rate_record_id')->nullable();
            $table->uuid('document_id')->nullable();
            $table->uuid('reverses_consumption_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->foreign('credit_tranche_id')->references('id')->on('settlement_credit_tranches');
            $table->foreign('allocation_id')->references('id')->on('settlement_allocations');
            $table->index(['entity_id', 'credit_tranche_id', 'occurred_at'], 'settlement_consumption_tranche_idx');
        });

        Schema::table('settlement_credit_consumptions', function (Blueprint $table): void {
            $table->foreign('reverses_consumption_id')->references('id')->on('settlement_credit_consumptions');
        });

        DB::statement('CREATE UNIQUE INDEX settlement_consumption_reversal_unique ON settlement_credit_consumptions (reverses_consumption_id) WHERE reverses_consumption_id IS NOT NULL');

        Schema::create('settlement_party_credit_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('party_type', 16);
            $table->uuid('party_id');
            $table->char('currency', 3);
            $table->decimal('available_balance', 20, 4)->default(0);
            $table->decimal('functional_carrying_balance', 20, 4)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['entity_id', 'party_type', 'party_id', 'currency'], 'settlement_credit_balance_unique');
        });

        if (DB::getDriverName() !== 'sqlite') {
            foreach (['settlement_allocations' => "gross_amount >= 0 AND bank_amount >= 0 AND withholding_amount >= 0 AND allocated_amount >= 0 AND unapplied_amount >= 0 AND (operation IN ('credit_application','reversal') OR gross_amount = bank_amount + withholding_amount) AND gross_amount = allocated_amount + unapplied_amount", 'settlement_credit_tranches' => 'original_amount >= 0 AND remaining_amount >= 0 AND remaining_amount <= original_amount AND original_functional_amount >= 0 AND remaining_functional_amount >= 0 AND remaining_functional_amount <= original_functional_amount', 'settlement_credit_consumptions' => "amount > 0 AND functional_amount >= 0 AND operation IN ('application','refund','restoration')", 'settlement_party_credit_balances' => 'available_balance >= 0 AND functional_carrying_balance >= 0'] as $table => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_money_check CHECK ({$check})");
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_m3_immutable_fact() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Settlement financial facts are immutable'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER protect_settlement_links BEFORE UPDATE OR DELETE ON settlement_allocation_links FOR EACH ROW EXECUTE FUNCTION protect_m3_immutable_fact();
CREATE TRIGGER protect_settlement_withholding BEFORE UPDATE OR DELETE ON settlement_withholding_lines FOR EACH ROW EXECUTE FUNCTION protect_m3_immutable_fact();
CREATE TRIGGER protect_settlement_consumptions BEFORE UPDATE OR DELETE ON settlement_credit_consumptions FOR EACH ROW EXECUTE FUNCTION protect_m3_immutable_fact();
CREATE OR REPLACE FUNCTION protect_m3_allocation() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Posted allocations are immutable'; END IF;
 IF OLD.state <> 'posted' THEN RETURN NEW; END IF;
 IF (to_jsonb(NEW) - 'state' - 'reversed_by_id' - 'version' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'state' - 'reversed_by_id' - 'version' - 'updated_at') THEN RAISE EXCEPTION 'Posted allocations are immutable'; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER protect_settlement_allocation BEFORE UPDATE OR DELETE ON settlement_allocations FOR EACH ROW EXECUTE FUNCTION protect_m3_allocation();
CREATE OR REPLACE FUNCTION protect_m3_credit_tranche() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Credit tranche source facts are immutable'; END IF;
 IF (to_jsonb(NEW) - 'remaining_amount' - 'remaining_functional_amount' - 'version' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'remaining_amount' - 'remaining_functional_amount' - 'version' - 'updated_at') THEN RAISE EXCEPTION 'Credit tranche source facts are immutable'; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER protect_settlement_credit_tranche BEFORE UPDATE OR DELETE ON settlement_credit_tranches FOR EACH ROW EXECUTE FUNCTION protect_m3_credit_tranche();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_m3_credit_tranche() CASCADE; DROP FUNCTION IF EXISTS protect_m3_allocation() CASCADE; DROP FUNCTION IF EXISTS protect_m3_immutable_fact() CASCADE;');
        }
        Schema::dropIfExists('settlement_party_credit_balances');
        Schema::dropIfExists('settlement_credit_consumptions');
        Schema::dropIfExists('settlement_credit_tranches');
        Schema::dropIfExists('settlement_withholding_lines');
        Schema::dropIfExists('settlement_allocation_links');
        Schema::dropIfExists('settlement_allocations');
    }
};
