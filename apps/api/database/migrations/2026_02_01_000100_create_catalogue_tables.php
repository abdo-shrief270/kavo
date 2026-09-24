<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue: the first thing a tenant has to sell.
 *
 * Products carry the merchandising — name, description, images — and variants
 * carry everything that differs per option combination: price, SKU and stock.
 * Fashion is the reason: one shirt is six rows, and the S/Black one can be
 * out of stock while the M/White one is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // The storefront URL. Unique per tenant, not globally: two
            // merchants may both sell a "linen-shirt".
            $table->string('slug');
            $table->text('description')->nullable();

            // draft | active | archived. Only active is ever shown publicly,
            // so a half-written product cannot leak onto the storefront.
            $table->string('status')->default('draft')->index();

            /*
             | The option axes this product varies on, in display order:
             | [{"name": "Size", "values": ["S","M","L"]}, ...]
             | The product declares the axes; each variant picks one value per
             | axis. Keeping the axes here is what lets the storefront render
             | a size selector without reading every variant first.
             */
            $table->jsonb('options')->default('[]');

            $table->string('currency', 3)->default('EGP');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku');

            // The chosen value per axis: {"Size": "M", "Colour": "Black"}.
            $table->jsonb('options')->default('{}');

            /*
             | A canonical, sorted rendering of `options` — "colour:black|size:m".
             | jsonb cannot be uniquely indexed in a way that survives key
             | ordering, and two variants that both mean M/Black is not a
             | cosmetic problem: stock splits across them and the storefront
             | shows one while orders decrement the other.
             */
            $table->string('option_signature', 255);

            $table->unsignedBigInteger('price_cents');
            // Shown struck through next to the price. Null when not on offer.
            $table->unsignedBigInteger('compare_at_price_cents')->nullable();

            /*
             | Stock is two numbers, not one. on_hand is what is physically
             | there; reserved is what unpaid orders are holding. Available is
             | the difference, and it is what the storefront may sell.
             |
             | This exists now rather than with the cart because the offline
             | payment rail makes it unavoidable: a Fawry reference holds stock
             | for up to 72 hours before any money moves.
             */
            $table->boolean('track_inventory')->default(true);
            $table->unsignedBigInteger('stock_on_hand')->default(0);
            $table->unsignedBigInteger('stock_reserved')->default(0);

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->unique(['product_id', 'option_signature']);
            $table->index(['tenant_id', 'product_id']);
        });

        // Enforced by the database, not by the application. Reserving more
        // than exists is the definition of overselling, and an application
        // that gets it wrong under concurrency should fail loudly here rather
        // than quietly sell stock it does not have.
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT stock_reserved_within_on_hand CHECK (stock_reserved <= stock_on_hand)');

        // Images, reusing the platform Media module rather than a second
        // upload path: media is already metered, isolated and audited.
        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'media_id']);
        });

        foreach (['products', 'product_variants', 'product_media'] as $table) {
            $this->protect($table);
        }
    }

    public function down(): void
    {
        foreach (['product_media', 'product_variants', 'products'] as $table) {
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
