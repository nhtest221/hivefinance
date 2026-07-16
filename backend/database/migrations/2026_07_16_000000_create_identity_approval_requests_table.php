<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('maker_id');
            $table->uuid('approver_id')->nullable();
            $table->enum('status', ['pending', 'approved'])->default('pending');
            $table->string('command_type');
            $table->unsignedInteger('command_schema_version');
            $table->uuid('resource_id')->nullable();
            $table->string('required_approval_capability');
            $table->text('encrypted_payload');
            $table->char('payload_hash', 64);
            $table->uuid('originating_idempotency_key');
            $table->string('originating_operation');
            $table->char('originating_request_hash', 64);
            $table->uuid('originating_correlation_id');
            $table->uuid('causation_id');
            $table->uuid('approval_requested_event_id')->nullable();
            $table->unsignedInteger('original_if_match')->nullable();
            $table->unsignedSmallInteger('command_result_status')->nullable();
            $table->jsonb('command_result_body')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('submitted_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('retained_until')->nullable();
            $table->timestampsTz();

            $table->foreign('entity_id')->references('id')->on('identity_entities')->restrictOnDelete();
            $table->foreign('maker_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approver_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['maker_id', 'entity_id', 'originating_operation', 'originating_idempotency_key'],
                'identity_approval_origin_unique',
            );
            $table->index(['entity_id', 'status', 'submitted_at'], 'identity_approval_queue_index');
            $table->index(['resource_id', 'command_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_approval_requests');
    }
};
