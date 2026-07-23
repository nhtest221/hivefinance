<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('report_type', 32);
            $table->string('period_ref', 16)->nullable();
            $table->date('as_of')->nullable();
            $table->date('range_from')->nullable();
            $table->date('range_to')->nullable();
            $table->string('basis', 8);
            $table->string('functional_currency', 3);
            $table->jsonb('filters');
            $table->unsignedInteger('layout_version')->nullable();
            $table->unsignedInteger('classification_version')->nullable();
            $table->unsignedInteger('policy_version')->nullable();
            $table->timestampTz('source_data_watermark');
            $table->jsonb('content');
            $table->string('content_hash', 64);
            $table->uuid('generated_by');
            $table->timestampTz('generated_at');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->string('state', 16);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('superseded_by_report_run_id')->nullable();
            $table->timestampsTz();

            $table->index(['entity_id', 'report_type', 'basis', 'period_ref', 'state'], 'report_runs_period_lookup');
            $table->index(['entity_id', 'report_type', 'basis', 'as_of', 'state'], 'report_runs_as_of_lookup');
        });

        Schema::create('report_layout_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('report_type', 32);
            $table->unsignedInteger('version_number');
            $table->jsonb('sections');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->unique(['entity_id', 'report_type', 'version_number']);
            $table->index(['entity_id', 'report_type', 'effective_from']);
        });

        Schema::create('account_classification_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->unsignedInteger('version_number');
            $table->jsonb('entries');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->unique(['entity_id', 'version_number']);
            $table->index(['entity_id', 'effective_from']);
        });

        Schema::create('ageing_bucket_set_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->unsignedInteger('version_number');
            $table->jsonb('buckets');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->unique(['entity_id', 'version_number']);
            $table->index(['entity_id', 'effective_from']);
        });

        Schema::create('cash_view_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->unsignedInteger('version_number');
            $table->jsonb('policy');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->unique(['entity_id', 'version_number']);
            $table->index(['entity_id', 'effective_from']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_state_check CHECK (state IN ('Generated','PendingApproval','Approved','Rejected','Superseded'))");
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_basis_check CHECK (basis IN ('accrual','cash'))");
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_report_type_check CHECK (report_type IN ('trial_balance','general_ledger','profit_and_loss','balance_sheet','ar_ageing','ap_ageing','tax_summary','fx_revaluation','cash_view'))");
        }

        if (DB::getDriverName() === 'pgsql') {
            // API Contracts §13.4: content/content_hash/source_data_watermark and every
            // reproducibility-key field are set once at Generated and never altered; only
            // lifecycle fields (state, version, reviewed/approved actors and timestamps,
            // supersession linkage, updated_at) may change thereafter.
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_report_run_fact() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'ReportRun facts are immutable and may not be deleted';
    END IF;
    IF NEW.entity_id IS DISTINCT FROM OLD.entity_id
        OR NEW.report_type IS DISTINCT FROM OLD.report_type
        OR NEW.period_ref IS DISTINCT FROM OLD.period_ref
        OR NEW.as_of IS DISTINCT FROM OLD.as_of
        OR NEW.range_from IS DISTINCT FROM OLD.range_from
        OR NEW.range_to IS DISTINCT FROM OLD.range_to
        OR NEW.basis IS DISTINCT FROM OLD.basis
        OR NEW.functional_currency IS DISTINCT FROM OLD.functional_currency
        OR NEW.filters IS DISTINCT FROM OLD.filters
        OR NEW.layout_version IS DISTINCT FROM OLD.layout_version
        OR NEW.classification_version IS DISTINCT FROM OLD.classification_version
        OR NEW.policy_version IS DISTINCT FROM OLD.policy_version
        OR NEW.source_data_watermark IS DISTINCT FROM OLD.source_data_watermark
        OR NEW.content IS DISTINCT FROM OLD.content
        OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
        OR NEW.generated_by IS DISTINCT FROM OLD.generated_by
        OR NEW.generated_at IS DISTINCT FROM OLD.generated_at
    THEN
        RAISE EXCEPTION 'ReportRun content and reproducibility fields are immutable once generated';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_report_runs BEFORE UPDATE OR DELETE ON report_runs FOR EACH ROW EXECUTE FUNCTION protect_report_run_fact();

CREATE OR REPLACE FUNCTION protect_report_config_version() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Reporting configuration versions are append-only; create a new version instead';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER protect_report_layout_versions BEFORE UPDATE OR DELETE ON report_layout_versions FOR EACH ROW EXECUTE FUNCTION protect_report_config_version();
CREATE TRIGGER protect_account_classification_versions BEFORE UPDATE OR DELETE ON account_classification_versions FOR EACH ROW EXECUTE FUNCTION protect_report_config_version();
CREATE TRIGGER protect_ageing_bucket_set_versions BEFORE UPDATE OR DELETE ON ageing_bucket_set_versions FOR EACH ROW EXECUTE FUNCTION protect_report_config_version();
CREATE TRIGGER protect_cash_view_policy_versions BEFORE UPDATE OR DELETE ON cash_view_policy_versions FOR EACH ROW EXECUTE FUNCTION protect_report_config_version();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_report_run_fact() CASCADE;');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_report_config_version() CASCADE;');
        }
        Schema::dropIfExists('cash_view_policy_versions');
        Schema::dropIfExists('ageing_bucket_set_versions');
        Schema::dropIfExists('account_classification_versions');
        Schema::dropIfExists('report_layout_versions');
        Schema::dropIfExists('report_runs');
    }
};
