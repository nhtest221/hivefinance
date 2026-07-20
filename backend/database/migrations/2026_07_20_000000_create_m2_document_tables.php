<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('type', 16);
            $table->string('jurisdiction', 16)->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('normalized_tax_identifier')->nullable();
            $table->char('default_currency', 3);
            $table->string('payment_terms');
            $table->json('contact')->nullable();
            $table->json('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->unique(['entity_id', 'jurisdiction', 'normalized_tax_identifier'], 'receivables_customer_tax_unique');
            $table->index(['entity_id', 'status', 'normalized_name', 'id']);
        });

        Schema::create('receivables_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->string('document_number')->nullable();
            $table->uuid('provisional_token')->nullable();
            $table->uuid('customer_id')->index();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('payment_instructions_ref')->nullable();
            $table->uuid('rate_record_id')->nullable();
            $table->json('exchange_rate_reference')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->decimal('open_balance', 20, 4)->default(0);
            $table->string('status', 24)->default('draft');
            $table->uuid('journal_entry_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->unique(['entity_id', 'document_number']);
            $table->index(['entity_id', 'customer_id', 'status']);
            $table->index(['entity_id', 'invoice_date', 'id']);
            $table->index(['entity_id', 'due_date', 'status']);
        });

        Schema::create('receivables_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->uuid('entity_id');
            $table->unsignedInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 20, 4);
            $table->decimal('unit_price', 20, 4);
            $table->uuid('tax_code_id')->nullable();
            $table->json('tax_snapshot')->nullable();
            $table->decimal('line_amount', 20, 4);
            $table->decimal('tax_amount', 20, 4);
            $table->decimal('total_amount', 20, 4);
            $table->timestampsTz();
            $table->foreign('invoice_id')->references('id')->on('receivables_invoices')->cascadeOnDelete();
            $table->unique(['invoice_id', 'line_no']);
        });

        Schema::create('payables_vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('jurisdiction', 16)->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('normalized_tax_identifier')->nullable();
            $table->char('default_currency', 3);
            $table->string('payment_terms');
            $table->json('contact')->nullable();
            $table->json('address')->nullable();
            $table->text('bank_details')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->unique(['entity_id', 'jurisdiction', 'normalized_tax_identifier'], 'payables_vendor_tax_unique');
            $table->index(['entity_id', 'status', 'normalized_name', 'id']);
        });

        Schema::create('payables_bills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->string('document_number')->nullable();
            $table->uuid('provisional_token')->nullable();
            $table->uuid('vendor_id')->index();
            $table->string('vendor_reference')->nullable();
            $table->text('notes')->nullable();
            $table->date('bill_date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->uuid('rate_record_id')->nullable();
            $table->json('exchange_rate_reference')->nullable();
            $table->decimal('ait', 20, 4)->nullable();
            $table->decimal('vds', 20, 4)->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->decimal('open_balance', 20, 4)->default(0);
            $table->string('status', 24)->default('draft');
            $table->uuid('journal_entry_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['entity_id', 'document_number']);
            $table->index(['entity_id', 'vendor_id', 'status']);
            $table->index(['entity_id', 'bill_date', 'id']);
            $table->index(['entity_id', 'due_date', 'status']);
        });

        Schema::create('payables_bill_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('bill_id');
            $table->uuid('entity_id');
            $table->unsignedInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 20, 4);
            $table->decimal('unit_price', 20, 4);
            $table->uuid('expense_account_id');
            $table->uuid('tax_code_id')->nullable();
            $table->json('tax_snapshot')->nullable();
            $table->decimal('line_amount', 20, 4);
            $table->decimal('tax_amount', 20, 4);
            $table->decimal('total_amount', 20, 4);
            $table->timestampsTz();
            $table->foreign('bill_id')->references('id')->on('payables_bills')->cascadeOnDelete();
            $table->unique(['bill_id', 'line_no']);
        });

        Schema::create('payables_bill_sbu_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('bill_id');
            $table->uuid('entity_id');
            $table->string('sbu_code');
            $table->decimal('weight', 8, 4);
            $table->timestampsTz();
            $table->foreign('bill_id')->references('id')->on('payables_bills')->cascadeOnDelete();
            $table->unique(['bill_id', 'sbu_code']);
        });

        Schema::create('payables_expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->date('expense_date');
            $table->text('description');
            $table->uuid('vendor_id')->nullable();
            $table->uuid('category_account_id');
            $table->string('settlement_type', 16);
            $table->uuid('bank_account_id')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->uuid('tax_code_id')->nullable();
            $table->json('tax_snapshot')->nullable();
            $table->decimal('ait', 20, 4)->nullable();
            $table->json('sbu_allocations');
            $table->uuid('rate_record_id')->nullable();
            $table->json('exchange_rate_reference')->nullable();
            $table->uuid('journal_entry_id');
            $table->string('status', 16)->default('recorded');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('recorded_at');
            $table->timestampsTz();
            $table->index(['entity_id', 'expense_date', 'id']);
            $table->index(['entity_id', 'vendor_id']);
            $table->index(['entity_id', 'category_account_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_recognized_m2_document() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Recognized documents are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft'
       AND (to_jsonb(NEW) - 'open_balance' - 'status' - 'version' - 'updated_at')
           IS DISTINCT FROM (to_jsonb(OLD) - 'open_balance' - 'status' - 'version' - 'updated_at') THEN
        RAISE EXCEPTION 'Recognized documents are immutable';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_receivables_invoice BEFORE UPDATE OR DELETE ON receivables_invoices FOR EACH ROW EXECUTE FUNCTION protect_recognized_m2_document();
CREATE TRIGGER protect_payables_bill BEFORE UPDATE OR DELETE ON payables_bills FOR EACH ROW EXECUTE FUNCTION protect_recognized_m2_document();
CREATE OR REPLACE FUNCTION protect_recorded_expense() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Recorded expenses are immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_payables_expense BEFORE UPDATE OR DELETE ON payables_expenses FOR EACH ROW EXECUTE FUNCTION protect_recorded_expense();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_recorded_expense() CASCADE; DROP FUNCTION IF EXISTS protect_recognized_m2_document() CASCADE;');
        }
        Schema::dropIfExists('payables_expenses');
        Schema::dropIfExists('payables_bill_sbu_allocations');
        Schema::dropIfExists('payables_bill_lines');
        Schema::dropIfExists('payables_bills');
        Schema::dropIfExists('payables_vendors');
        Schema::dropIfExists('receivables_invoice_lines');
        Schema::dropIfExists('receivables_invoices');
        Schema::dropIfExists('receivables_customers');
    }
};
