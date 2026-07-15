<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('period_ref');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('state')->default('open');
            $table->string('vat_lock_status')->default('unlocked');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('entity_id')->references('id')->on('identity_entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'period_ref']);
            $table->index(['entity_id', 'starts_on', 'ends_on']);
            $table->index(['entity_id', 'state']);
        });

        Schema::create('period_transitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('period_id');
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('reason_code')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->uuid('approver_id')->nullable();
            $table->timestampTz('transitioned_at');
            $table->timestampsTz();

            $table->foreign('period_id')->references('id')->on('accounting_periods')->cascadeOnDelete();
            $table->index(['period_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_transitions');
        Schema::dropIfExists('accounting_periods');
    }
};
