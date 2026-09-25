<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Modules\Platform\Domains\Models\Domain;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Media\Models\Media;
use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Route-model binding, under the conditions a real request actually has.
 *
 * Every other suite calls actingAsTenant() in setUp, which binds the tenant
 * context before the request is made. A browser does not do that: it sends an
 * HTTP request into a container with nothing bound, and the middleware stack
 * is the only thing that resolves a tenant.
 *
 * That difference hid a break in every single endpoint with a {model} in its
 * path. `tenant` is route middleware, so it ran *after* the api group — and
 * SubstituteBindings is in that group. The binding query ran with no tenant,
 * the global scope refused it exactly as designed, and the merchant got a 500.
 * It took a browser to find, so this suite exists to keep it found.
 *
 * Fixtures are created as the tenant; the context is then torn down so the
 * request starts from nothing, the way one does in production.
 */
final class RouteBindingTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    /**
     * Each case builds its fixture inside the tenant and returns the request
     * that binds it.
     *
     * The method matters. A GET against a DELETE-only route is a 405 answered
     * by the router *before* any binding happens, so three of these cases
     * proved nothing until they were given the verb their route actually has.
     *
     * @return array<string, array{string, callable(self): string, array<string, mixed>}>
     */
    public static function boundRoutes(): array
    {
        return [
            'product' => ['GET', fn (self $case): string => '/api/products/'.$case->make(fn () => Product::factory()->create())->getKey(), []],

            'variant stock' => ['PATCH', function (self $case): string {
                $variant = $case->make(function (): ProductVariant {
                    $product = Product::factory()->create();

                    return ProductVariant::factory()->for($product)->create();
                });

                return '/api/products/'.$variant->product_id.'/variants/'.$variant->getKey().'/stock';
            }, ['adjust' => 1]],

            'order' => ['GET', fn (self $case): string => '/api/orders/'.$case->make(fn () => Order::factory()->create())->getKey(), []],
            'order cancel' => ['POST', fn (self $case): string => '/api/orders/'.$case->make(fn () => Order::factory()->create())->getKey().'/cancel', []],
            'payment' => ['GET', fn (self $case): string => '/api/payments/'.$case->make(fn () => PaymentIntent::factory()->create())->getKey(), []],
            'media' => ['DELETE', fn (self $case): string => '/api/media/'.$case->make(fn () => Media::factory()->create())->getKey(), []],
            'domain verify' => ['POST', fn (self $case): string => '/api/domains/'.$case->make(fn () => Domain::factory()->create())->getKey().'/verify', []],
            'domain' => ['DELETE', fn (self $case): string => '/api/domains/'.$case->make(fn () => Domain::factory()->create())->getKey(), []],
            'webhook subscription' => ['DELETE', fn (self $case): string => '/api/webhooks/subscriptions/'.$case->make(fn () => WebhookSubscription::factory()->create())->getKey(), []],
        ];
    }

    /** Build a fixture inside the tenant, then leave that context behind. */
    public function make(callable $factory): Model
    {
        $record = $this->asTenant($this->tenant, $factory);

        $this->forgetTenant();

        return $record;
    }

    /**
     * The assertion is only "not 500". Whether the route answers 200, 404 or
     * 405 is each endpoint's own business; what must never happen is the
     * binding blowing up before the tenant is known.
     */
    #[Test]
    #[DataProvider('boundRoutes')]
    public function a_bound_model_resolves_with_no_ambient_tenant(string $method, callable $route, array $body): void
    {
        $path = $route($this);

        // Nothing bound, exactly as a browser leaves it.
        $this->forgetTenant();

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->json($method, $path, $body);

        $this->assertNotSame(
            500,
            $response->status(),
            "{$method} {$path} failed before the tenant was resolved: ".$response->json('message', ''),
        );

        // And the router reached the route at all — a 405 would mean this case
        // is asserting nothing, which is how three of them started out.
        $this->assertNotSame(405, $response->status(), "{$method} {$path} is not a route, so this case proves nothing.");
    }
}
