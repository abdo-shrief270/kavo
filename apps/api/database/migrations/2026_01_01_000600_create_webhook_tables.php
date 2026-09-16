<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inbound. Platform-wide rather than tenant-scoped: the tenant is
        // only known after the payload is parsed, which happens in the job.
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            // The idempotency key. Providers retry aggressively, and this
            // unique constraint is what stops a retry becoming a second order.
            $table->string('external_event_id');
            $table->string('event_type')->nullable();
            $table->jsonb('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index(['provider', 'processed_at']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('secret');
            $table->jsonb('event_types')->default('[]');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->jsonb('payload');
            $table->unsignedSmallInteger('attempt')->default(0);
            // pending | delivered | failed | dead_lettered
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_deliveries', 'webhook_subscriptions', 'webhook_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
