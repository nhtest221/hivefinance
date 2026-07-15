<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legal_name');
            $table->char('functional_currency', 3);
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1);
            $table->jsonb('approval_policy')->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();

            $table->unique('legal_name');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status')->default('invited');
            $table->uuid('active_entity_id')->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->boolean('mfa_required')->default(false);
            $table->boolean('mfa_enabled')->default(false);
            $table->jsonb('mfa_config')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();

            $table->foreign('active_entity_id')->references('id')->on('identity_entities')->nullOnDelete();
            $table->index(['status', 'locked_until']);
        });

        Schema::create('identity_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('rank')->default(0);
            $table->timestampsTz();

            $table->foreign('entity_id')->references('id')->on('identity_entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'slug']);
            $table->index('slug');
        });

        Schema::create('identity_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('role_id');
            $table->string('permission');
            $table->timestampsTz();

            $table->foreign('role_id')->references('id')->on('identity_roles')->cascadeOnDelete();
            $table->unique(['role_id', 'permission']);
        });

        Schema::create('identity_entity_user', function (Blueprint $table): void {
            $table->uuid('entity_id');
            $table->uuid('user_id');
            $table->string('status')->default('active');
            $table->timestampsTz();

            $table->primary(['entity_id', 'user_id']);
            $table->foreign('entity_id')->references('id')->on('identity_entities')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'status']);
        });

        Schema::create('identity_role_user', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('user_id');
            $table->uuid('entity_id');
            $table->timestampsTz();

            $table->primary(['role_id', 'user_id', 'entity_id']);
            $table->foreign('role_id')->references('id')->on('identity_roles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('entity_id')->references('id')->on('identity_entities')->cascadeOnDelete();
            $table->index(['user_id', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_role_user');
        Schema::dropIfExists('identity_entity_user');
        Schema::dropIfExists('identity_role_permissions');
        Schema::dropIfExists('identity_roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('identity_entities');
    }
};
