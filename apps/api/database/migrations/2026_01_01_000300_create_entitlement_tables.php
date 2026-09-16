<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The hot table. Counters increment in Redis and flush here on a
        // schedule; incrementing a row per order would be write contention.
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key');
            $table->date('period_start');
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamp('flushed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'metric_key', 'period_start']);
            $table->index(['tenant_id', 'metric_key', 'period_start'], 'usage_counters_lookup_index');
        });

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->index();
            // global | tenant | product
            $table->string('scope')->default('global');
            $table->string('scope_value')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('rollout_percentage')->default(100);
            $table->timestamps();

            $table->unique(['key', 'scope', 'scope_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('usage_counters');
    }
};
