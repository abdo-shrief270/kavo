<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Our own reference, echoed by the provider and matched on the way
            // back. Unique per tenant so two tenants can both have order-1001.
            $table->string('reference');

            $table->string('gateway');
            $table->string('rail');
            $table->string('status')->default('pending')->index();

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('EGP');

            $table->string('gateway_reference')->nullable();

            // The code the customer takes to a kiosk. Null on card rails.
            $table->string('payment_reference')->nullable();
            $table->string('redirect_url', 2048)->nullable();

            // Every open intent has one. An offline reference nobody pays has
            // to release the order it is holding, or the catalogue sells out
            // to customers who never paid.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('settled_at')->nullable();

            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['gateway', 'gateway_reference']);
            // Drives the expiry sweep.
            $table->index(['status', 'expires_at']);
        });

        // Append-only history. A payment that ends in the wrong state is a
        // dispute, and "what did the gateway actually tell us, and when" is
        // the only thing that settles one.
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_intent_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('source')->default('gateway');
            $table->string('external_event_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestamps();

            $table->index(['payment_intent_id', 'created_at']);
            // One provider event moves one intent once, even if the provider
            // retries the callback.
            $table->unique(['payment_intent_id', 'external_event_id']);
        });

        // Idempotency for write APIs, not just webhooks. A checkout retried
        // over a flaky mobile connection must not become two orders — which
        // is the normal case in this market, not the edge case.
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('method', 10);
            $table->string('path');
            // Hash of the body: the same key with a different payload is a
            // client bug, and replaying the first response would hide it.
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        $this->protect('payment_intents');
        $this->protect('payment_events');
        $this->protect('idempotency_keys');
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'payment_events', 'payment_intents'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Same two-layer isolation as every other tenant table — declared here
     * because these tables are created after the RLS migration ran.
     */
    private function protect(string $table): void
    {
        $guc = config('kavo.tenancy.guc');

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
            USING (tenant_id = NULLIF(current_setting('{$guc}', true), '')::bigint)
            WITH CHECK (tenant_id = NULLIF(current_setting('{$guc}', true), '')::bigint)
        SQL);
    }
};
