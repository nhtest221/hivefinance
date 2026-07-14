<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->unsignedInteger('event_version')->default(1);
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->uuid('entity_id')->nullable();
            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['processed_at', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
