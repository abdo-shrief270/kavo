<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Identity\Actions\ProvisionTenant;
use App\Modules\Platform\Notifications\Models\WhatsAppTemplate;
use App\Modules\Platform\Themes\Models\TenantThemeSetting;
use App\Modules\Platform\Themes\Models\Theme;
use App\Shared\Enums\Product;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Database\Seeder;

/**
 * Enough to walk the Phase 0 exit gate end to end: sign up, get a plan, hit a
 * quota, render a theme, send a WhatsApp notification.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPlans();
        $this->seedThemes();
        $this->seedWhatsAppTemplates();
        $this->seedPlatformAdmin();
        $this->seedDemoTenant();
    }

    private function seedPlans(): void
    {
        $definitions = [
            ['starter', 'Starter', 49900, 14, ['orders' => 100, 'products' => 200, 'storage_mb' => 1024, 'whatsapp_messages' => 500, 'multi_branch' => 0]],
            ['growth', 'Growth', 149900, 14, ['orders' => 1000, 'products' => 2000, 'storage_mb' => 10240, 'whatsapp_messages' => 5000, 'multi_branch' => 3]],
            // null means unlimited; 0 means explicitly denied. They are
            // different answers and the engine treats them differently.
            ['scale', 'Scale', 399900, 0, ['orders' => null, 'products' => null, 'storage_mb' => 51200, 'whatsapp_messages' => 25000, 'multi_branch' => null]],
        ];

        foreach ($definitions as $index => [$code, $name, $price, $trial, $features]) {
            $plan = Plan::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'product' => Product::Fashion,
                'interval' => 'month',
                'price_cents' => $price,
                'currency' => 'EGP',
                'trial_days' => $trial,
                'is_active' => true,
                'sort_order' => $index,
            ]);

            foreach ($features as $key => $limit) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->getKey(), 'feature_key' => $key],
                    [
                        'limit_value' => $limit,
                        // Orders block on the entry plan and bill through on
                        // higher ones — overage is a plan property, never
                        // hardcoded in the metering engine.
                        'overage_behavior' => $code === 'starter' ? 'block' : 'allow_and_bill',
                    ]
                );
            }
        }
    }

    private function seedThemes(): void
    {
        Theme::query()->updateOrCreate(['code' => 'default', 'version' => '1.0.0'], [
            'name' => 'Default',
            'product' => Product::Fashion,
            'is_active' => true,
            'manifest' => [
                'sections' => [
                    ['type' => 'hero', 'settings' => ['headline' => 'text', 'image' => 'media']],
                    ['type' => 'product_grid', 'settings' => ['columns' => 'number', 'collection' => 'string']],
                    ['type' => 'rich_text', 'settings' => ['body' => 'html']],
                ],
            ],
        ]);
    }

    private function seedWhatsAppTemplates(): void
    {
        WhatsAppTemplate::query()->updateOrCreate(['code' => 'quota_threshold'], [
            'language' => 'en',
            'body' => 'Heads up — {{workspace}} has used {{percent}}% of its {{metric}} allowance this month.',
            'variables' => ['workspace', 'percent', 'metric'],
            // Approved so the local loop is exercisable. In staging and
            // production this only flips after the provider approves it.
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        WhatsAppTemplate::query()->updateOrCreate(['code' => 'order_placed'], [
            'language' => 'en',
            'body' => 'Thanks {{customer}}! Order {{order}} is confirmed.',
            'variables' => ['customer', 'order'],
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function seedPlatformAdmin(): void
    {
        User::query()->updateOrCreate(['email' => 'admin@kavo.test'], [
            'name' => 'Platform Admin',
            'password' => 'password',
            'is_platform_admin' => true,
        ]);
    }

    private function seedDemoTenant(): void
    {
        $owner = User::query()->updateOrCreate(['email' => 'merchant@kavo.test'], [
            'name' => 'Demo Merchant',
            'password' => 'password',
        ]);

        if ($owner->tenants()->exists()) {
            return;
        }

        $tenant = app(ProvisionTenant::class)->handle($owner, 'Demo Fashion', Product::Fashion, 'growth');

        // Theme settings are tenant-scoped, so they are written inside the
        // tenant's context and pass the same RLS policy as any other write.
        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            app(TenantDatabaseSession::class)->bind($tenant->getKey());

            try {
                TenantThemeSetting::query()->updateOrCreate(['theme_code' => 'default'], [
                    'settings' => ['hero' => ['headline' => 'New season, now live']],
                    'design_tokens' => ['color' => ['primary' => '#7c3aed', 'surface' => '#ffffff']],
                    'is_published' => true,
                ]);
            } finally {
                app(TenantDatabaseSession::class)->clear();
            }
        });
    }
}
