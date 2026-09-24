<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Catalogue\Enums\ProductStatus;
use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Media\Models\Media;
use App\Shared\Contracts\Entitlements;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue: the first thing in this codebase a tenant can actually sell.
 *
 * Two boundaries carry most of the weight here. A shopper must never reach a
 * draft, and a variant must never mean the same thing as another variant —
 * the second is not cosmetic, because duplicate option combinations split
 * stock between rows and the storefront then sells one while orders decrement
 * the other.
 */
final class CatalogueTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // A tenant with no plan has no products allowance at all — the
        // engine treats an absent feature as "not included", which is right
        // and is not what any of these tests are about.
        $this->tenant = Tenant::factory()->create(['plan_id' => $this->planAllowing(null)->getKey()]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAsTenant($this->tenant);
    }

    private function api(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->json($method, $uri, $body);
    }

    /**
     * A shopper's request, addressed the way a shopper's browser addresses it.
     *
     * The storefront is anonymous and resolves its tenant from the hostname,
     * so hitting the path alone resolves nothing and 404s. No test drove this
     * path before the catalogue needed it.
     */
    private function storefront(string $path): TestResponse
    {
        return $this->getJson('http://'.$this->tenant->slug.'.'.config('kavo.root_domain').'/api/storefront'.$path);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Linen Shirt',
            'description' => 'Breathable, for an Egyptian summer.',
            'status' => 'active',
            'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
            'variants' => [
                ['sku' => 'LIN-S', 'options' => ['Size' => 'S'], 'price_cents' => 89900, 'stock_on_hand' => 5],
                ['sku' => 'LIN-M', 'options' => ['Size' => 'M'], 'price_cents' => 89900, 'stock_on_hand' => 0],
            ],
        ];
    }

    // ------------------------------------------------------------- merchant

    #[Test]
    public function a_merchant_creates_a_product_with_variants(): void
    {
        $response = $this->api('POST', '/api/products', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('product.slug', 'linen-shirt')
            ->assertJsonCount(2, 'product.variants');

        $product = Product::query()->firstOrFail();

        $this->assertSame(ProductStatus::Active, $product->status);
        $this->assertSame(89900, $product->fromPriceCents());
        // One variant has stock, one does not: the product is still buyable.
        $this->assertTrue($product->isInStock());
    }

    /**
     * Slugs are the storefront's URLs, and two products called "Linen Shirt"
     * is an ordinary thing for a merchant to do.
     */
    #[Test]
    public function a_repeated_name_gets_a_distinct_url(): void
    {
        $this->api('POST', '/api/products', $this->payload())->assertStatus(201);
        $second = $this->api('POST', '/api/products', $this->payload([
            'variants' => [['sku' => 'LIN-2', 'options' => ['Size' => 'S'], 'price_cents' => 99900]],
        ]));

        $second->assertStatus(201)->assertJsonPath('product.slug', 'linen-shirt-2');
    }

    /**
     * The defect this guards is quiet: two variants that both mean M/Black
     * each hold their own stock, so the storefront shows one as available
     * while orders draw down the other.
     */
    #[Test]
    public function two_variants_cannot_mean_the_same_thing(): void
    {
        $this->api('POST', '/api/products', $this->payload([
            'variants' => [
                ['sku' => 'A', 'options' => ['Size' => 'M'], 'price_cents' => 1000],
                // Different case and key order, same meaning.
                ['sku' => 'B', 'options' => ['size' => 'm'], 'price_cents' => 1000],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('variants.1.options');

        $this->assertSame(0, Product::query()->count());
    }

    #[Test]
    public function editing_a_product_never_silently_rewrites_stock(): void
    {
        $this->api('POST', '/api/products', $this->payload())->assertStatus(201);
        $product = Product::query()->with('variants')->firstOrFail();

        // Stock moved since the merchant opened the form.
        $product->variants->firstWhere('sku', 'LIN-S')->update(['stock_on_hand' => 3, 'stock_reserved' => 2]);

        // They save the form, which still carries the old number.
        $this->api('PATCH', '/api/products/'.$product->getKey(), [
            'name' => 'Linen Shirt (Relaxed)',
            'variants' => [
                ['sku' => 'LIN-S', 'options' => ['Size' => 'S'], 'price_cents' => 79900, 'stock_on_hand' => 5],
            ],
        ])->assertOk();

        $variant = ProductVariant::query()->where('sku', 'LIN-S')->firstOrFail();

        // Price changed; stock and the reservation did not.
        $this->assertSame(79900, $variant->price_cents);
        $this->assertSame(3, $variant->stock_on_hand);
        $this->assertSame(2, $variant->stock_reserved);
    }

    #[Test]
    public function removing_a_variant_from_the_payload_removes_it(): void
    {
        $this->api('POST', '/api/products', $this->payload())->assertStatus(201);
        $product = Product::query()->firstOrFail();

        $this->api('PATCH', '/api/products/'.$product->getKey(), [
            'variants' => [['sku' => 'LIN-S', 'options' => ['Size' => 'S'], 'price_cents' => 89900]],
        ])->assertOk();

        $this->assertSame(1, ProductVariant::query()->count());
    }

    // ---------------------------------------------------------------- quota

    #[Test]
    public function a_tenant_at_their_product_ceiling_is_asked_to_upgrade(): void
    {
        $this->capProductsAt(1);

        $this->api('POST', '/api/products', $this->payload())->assertStatus(201);

        // 402, not 403: they may do this on a larger plan.
        $this->api('POST', '/api/products', $this->payload([
            'variants' => [['sku' => 'X', 'options' => ['Size' => 'S'], 'price_cents' => 1000]],
        ]))->assertStatus(402);

        $this->assertSame(1, Product::query()->count());
    }

    /**
     * products is a stock metric, not a flow one: a plan saying 200 products
     * means at any one time. Deleting has to give the slot back or a merchant
     * who tidies up is billed for shelves they emptied.
     */
    #[Test]
    public function deleting_a_product_returns_the_allowance(): void
    {
        $this->capProductsAt(1);

        $this->api('POST', '/api/products', $this->payload())->assertStatus(201);
        $product = Product::query()->firstOrFail();

        $this->api('DELETE', '/api/products/'.$product->getKey())->assertOk();

        $this->api('POST', '/api/products', $this->payload([
            'variants' => [['sku' => 'Y', 'options' => ['Size' => 'S'], 'price_cents' => 1000]],
        ]))->assertStatus(201);
    }

    // ----------------------------------------------------------- storefront

    #[Test]
    public function the_storefront_shows_active_products_only(): void
    {
        Product::factory()->published()->create(['name' => 'On Sale']);
        Product::factory()->create(['name' => 'Still Drafting']);

        $response = $this->storefront('/products');

        $response->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'On Sale');
    }

    /**
     * A draft's URL is guessable. 404 rather than 403, because whether a slug
     * exists as someone's unfinished work is not a shopper's business.
     */
    #[Test]
    public function a_draft_cannot_be_reached_by_guessing_its_slug(): void
    {
        $draft = Product::factory()->create(['slug' => 'secret-launch']);

        $this->storefront('/products/secret-launch')
            ->assertStatus(404);

        $draft->update(['status' => ProductStatus::Active]);

        $this->storefront('/products/secret-launch')
            ->assertOk()->assertJsonPath('product.slug', 'secret-launch');
    }

    #[Test]
    public function the_storefront_publishes_availability_but_not_stock_levels(): void
    {
        $product = Product::factory()->published()->create();
        ProductVariant::factory()->for($product)->create(['sku' => 'IN', 'options' => ['Size' => 'S'], 'option_signature' => ProductVariant::signatureFor(['Size' => 'S'])]);
        ProductVariant::factory()->for($product)->outOfStock()->create(['sku' => 'OUT', 'options' => ['Size' => 'M'], 'option_signature' => ProductVariant::signatureFor(['Size' => 'M'])]);

        $response = $this->storefront('/products/'.$product->slug)->assertOk();

        $body = $response->json('product.variants');
        $this->assertSame([true, false], array_column($body, 'in_stock'));

        // How much a competitor has left is not something to publish.
        $response->assertJsonMissing(['stock_on_hand' => 10])
            ->assertJsonMissing(['stock_reserved' => 0]);
    }

    #[Test]
    public function an_inactive_variant_is_not_offered(): void
    {
        $product = Product::factory()->published()->create();
        ProductVariant::factory()->for($product)->create(['sku' => 'LIVE', 'options' => ['Size' => 'S'], 'option_signature' => ProductVariant::signatureFor(['Size' => 'S'])]);
        ProductVariant::factory()->for($product)->create(['sku' => 'HIDDEN', 'options' => ['Size' => 'M'], 'option_signature' => ProductVariant::signatureFor(['Size' => 'M']), 'is_active' => false]);

        $this->storefront('/products/'.$product->slug)
            ->assertOk()->assertJsonCount(1, 'product.variants');
    }

    // ------------------------------------------------------------ integrity

    /**
     * Overselling is refused by the database, not only by the application.
     * Reservation arithmetic under concurrency is exactly where an off-by-one
     * turns into stock that was sold twice.
     */
    #[Test]
    public function the_database_refuses_to_reserve_more_than_exists(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock_on_hand' => 2, 'stock_reserved' => 0]);

        $this->expectException(QueryException::class);

        DB::transaction(fn () => $variant->update(['stock_reserved' => 3]));
    }

    /**
     * product_media is behind RLS and attach() writes no tenant_id by itself,
     * so the pivot has to be stamped where it is written.
     */
    #[Test]
    public function a_product_can_carry_images_from_the_media_library(): void
    {
        $product = Product::factory()->create();
        $image = Media::factory()->create();

        $product->attachImage($image);

        $this->assertCount(1, $product->refresh()->media);
        $this->assertDatabaseHas('product_media', [
            'product_id' => $product->getKey(),
            'media_id' => $image->getKey(),
            'tenant_id' => $this->tenant->getKey(),
        ]);
    }

    private function capProductsAt(int $limit): void
    {
        $this->tenant->update(['plan_id' => $this->planAllowing($limit)->getKey()]);
        app(Entitlements::class)->flush($this->tenant);
    }

    /** @param  int|null  $limit  null grants unlimited products */
    private function planAllowing(?int $limit): Plan
    {
        $plan = Plan::factory()->create();

        PlanFeature::query()->create([
            'plan_id' => $plan->getKey(),
            'feature_key' => 'products',
            'limit_value' => $limit,
            'overage_behavior' => 'block',
        ]);

        return $plan;
    }

    // ---------------------------------------------------- tenant resolution

    /**
     * The API never faces a browser directly: Caddy is in front of it and the
     * storefront's SSR server calls it over the loopback, so the hostname the
     * visitor asked for arrives only in X-Forwarded-Host. Without trusted
     * proxies configured the API sees 127.0.0.1, resolves no tenant, and every
     * storefront serves an empty shop — which is what it did.
     */
    #[Test]
    public function a_forwarded_hostname_from_a_trusted_proxy_resolves_the_tenant(): void
    {
        Product::factory()->published()->create(['name' => 'Forwarded']);

        $this->call(
            'GET',
            '/api/storefront/products',
            server: [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_HOST' => $this->tenant->slug.'.'.config('kavo.root_domain'),
            ],
        )->assertOk()->assertJsonPath('products.0.name', 'Forwarded');
    }

    /**
     * And only from a proxy we put there. A client that can name its own
     * hostname can name any tenant, and hostname is the whole of a
     * storefront's identity.
     */
    #[Test]
    public function a_forwarded_hostname_from_anywhere_else_is_ignored(): void
    {
        Product::factory()->published()->create();

        $this->call(
            'GET',
            '/api/storefront/products',
            server: [
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_HOST' => $this->tenant->slug.'.'.config('kavo.root_domain'),
            ],
        )->assertStatus(404);
    }
}
