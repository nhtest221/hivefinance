<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_calendars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->unique();
            $table->date('year_start');
            $table->jsonb('period_defs');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('numbering_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('fiscal_year');
            $table->string('series_prefix');
            $table->unsignedBigInteger('current_value')->default(0);
            $table->boolean('gapless')->default(true);
            $table->string('reset_policy');
            $table->timestampsTz();
            $table->unique(['entity_id', 'fiscal_year', 'series_prefix'], 'numbering_sequence_scope_unique');
        });

        Schema::create('numbering_voided_numbers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sequence_id');
            $table->unsignedBigInteger('value');
            $table->timestampsTz();
            $table->unique(['sequence_id', 'value']);
            $table->foreign('sequence_id')->references('id')->on('numbering_sequences')->restrictOnDelete();
        });

        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuid('entity_id');
            $table->string('operation');
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body');
            $table->timestampsTz();
            $table->unique(['actor_id', 'entity_id', 'operation', 'idempotency_key'], 'idempotency_operation_unique');
        });

        Schema::create('outbox_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->string('consumer');
            $table->timestampTz('consumed_at');
            $table->jsonb('effect')->nullable();
            $table->unique(['event_id', 'consumer']);
            $table->foreign('event_id')->references('id')->on('outbox_messages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_consumptions');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('numbering_voided_numbers');
        Schema::dropIfExists('numbering_sequences');
        Schema::dropIfExists('fiscal_calendars');
    }
};
