<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('idempotency.database.connection'))
            ->create(config('idempotency.database.table', 'idempotency_records'), function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('identity_hash', 64)->unique();
                $table->char('key_hash', 64)->index();
                $table->char('scope_hash', 64)->index();
                $table->char('fingerprint', 64);
                $table->string('fingerprint_version', 16);
                $table->string('status', 32)->index();
                $table->string('profile', 64)->index();
                $table->string('owner_token', 128)->nullable();
                $table->unsignedInteger('attempt')->default(1);
                $table->unsignedInteger('replay_count')->default(0);
                $table->string('request_method', 16);
                $table->string('route_signature', 512);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->json('response_headers')->nullable();
                $table->longText('response_body')->nullable();
                $table->string('response_encoding', 32)->nullable();
                $table->char('response_checksum', 64)->nullable();
                $table->unsignedBigInteger('response_size')->nullable();
                $table->string('error_code', 128)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('lease_expires_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
                $table->index(['status', 'expires_at']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('idempotency.database.connection'))
            ->dropIfExists(config('idempotency.database.table', 'idempotency_records'));
    }
};
