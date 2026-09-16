<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plans are platform-owned catalogue, not tenant data.
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('product')->index();
            $table->string('interval')->default('month');
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key');
            // null means unlimited; 0 means explicitly denied.
            $table->unsignedBigInteger('limit_value')->nullable();
            // block | allow_and_bill | soft_warn — a plan property, never
            // hardcoded, so overage policy can differ per plan.
            $table->string('overage_behavior')->default('block');
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status')->default('trialing')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['gateway', 'gateway_reference']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('type')->default('percent');
            $table->unsignedInteger('value')->default(0);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'coupon_id']);
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            // Gateway token only. Raw card data never reaches this database.
            $table->string('gateway_token');
            $table->string('brand')->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedSmallInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'gateway']);
        });
    }

    public function down(): void
    {
        foreach (['payment_methods', 'coupon_redemptions', 'coupons', 'invoice_lines', 'invoices', 'subscriptions', 'plan_features', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
