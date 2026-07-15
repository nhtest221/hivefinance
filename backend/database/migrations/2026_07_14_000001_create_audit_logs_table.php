<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('occurred_at');
            $table->uuid('actor_id')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('module');
            $table->string('action');
            $table->string('record_type');
            $table->uuid('record_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['module', 'action']);
            $table->index(['record_type', 'record_id']);
            $table->index(['entity_id', 'occurred_at']);
            $table->index('actor_id');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
