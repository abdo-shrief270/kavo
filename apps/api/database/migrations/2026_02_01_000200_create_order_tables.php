<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carts and orders — the tables that turn a catalogue into revenue.
 *
 * The distinction that drives the whole design is what each one remembers. A
 * cart remembers *intent*: which variant, how many. It stores no price at all,
 * because a price is only a price at the moment it is agreed, and honouring a
 * figure from a cart abandoned three weeks ago is a merchant selling below
 * cost without noticing.
 *
 * An order remembers *the agreement*: the name, the SKU, the options and the
 * price exactly as they were when the customer pressed pay. It keeps them as
 * its own columns rather than as joins, so renaming a product, re-pricing it
 * or deleting it outright cannot rewrite what someone was charged. An order
 * that changes retroactively is not a record, it is a liability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             | The shopper's only credential. The storefront is anonymous and
             | lives on a different origin from the API, so a session cookie
             | would need cross-origin credentials on every request; an opaque
             | token the SSR server holds and forwards does not.
             |
             | Unique per tenant rather than globally: a cart is never handed
             | to a third party, and every lookup already runs inside a
             | resolved tenant.
             */
            $table->string('token', 40);

            $table->string('currency', 3)->default('EGP');

            // Carts are abandoned far more often than they are checked out.
            // Without a horizon this table only ever grows.
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'token']);
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // Adding the same variant twice changes a quantity rather than
            // producing two lines that mean the same thing.
            $table->unique(['cart_id', 'product_variant_id']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             | What the merchant and the customer say out loud. Per tenant and
             | starting near 1000, so it never leaks how many orders the
             | platform as a whole has taken — a shop's first order should not
             | be numbered 84,312.
             */
            $table->unsignedBigInteger('number');

            $table->string('status')->default('pending')->index();

            /*
             | Where the reserved stock currently is. Three states, and the
             | transitions between them are the only thing standing between a
             | reservation and either overselling or stock that is never
             | returned. Held here rather than inferred from the order status
             | so that applying a settlement twice is a no-op rather than a
             | second decrement.
             */
            $table->string('inventory_state')->default('reserved');

            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 32);
            $table->jsonb('shipping_address')->default('{}');

            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('shipping_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->string('currency', 3)->default('EGP');

            // Nullable because the order exists before the gateway is called,
            // and survives the gateway being unreachable. An order we cannot
            // charge is still an order the merchant has to see.
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('placed_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             | nullOnDelete, not cascade. A merchant deleting a product must
             | not erase the history of what was sold — the snapshot columns
             | below are what the line actually means, and the link is only a
             | convenience for "reorder" and for returning stock.
             */
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // The agreement, frozen. Never read through the variant.
            $table->string('product_name');
            $table->string('variant_sku');
            $table->jsonb('options')->default('{}');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->index(['order_id']);
        });

        foreach (['carts', 'cart_items', 'orders', 'order_items'] as $table) {
            $this->protect($table);
        }
    }

    public function down(): void
    {
        foreach (['order_items', 'orders', 'cart_items', 'carts'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** The same two-layer isolation every tenant table gets. */
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
