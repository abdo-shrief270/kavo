<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Declared partitioned at creation. Retrofitting partitioning onto a
        // large events table means rewriting it; declaring it now is free.
        // A partitioned table's primary key must contain the partition key,
        // hence the composite (id, occurred_at).
        DB::statement(<<<'SQL'
            CREATE TABLE analytics_events (
                id           bigserial    NOT NULL,
                tenant_id    bigint       NULL,
                user_id      bigint       NULL,
                product      varchar(32)  NULL,
                event_name   varchar(128) NOT NULL,
                properties   jsonb        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at  timestamptz  NOT NULL,
                created_at   timestamptz  NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement('CREATE INDEX analytics_events_tenant_time_idx ON analytics_events (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX analytics_events_name_time_idx ON analytics_events (event_name, occurred_at DESC)');

        // Current month plus the next two, so ingestion never lands with no
        // partition. A scheduled command keeps the runway ahead of it.
        foreach ([0, 1, 2] as $offset) {
            $this->createMonthlyPartition(now()->startOfMonth()->addMonths($offset));
        }

        // Anything outside a defined range lands here rather than erroring,
        // so a missed partition run loses observability, not data.
        DB::statement('CREATE TABLE analytics_events_default PARTITION OF analytics_events DEFAULT');

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('auditable');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP TABLE IF EXISTS analytics_events CASCADE');
    }

    private function createMonthlyPartition(Carbon $month): void
    {
        $name = 'analytics_events_'.$month->format('Y_m');
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->startOfMonth()->addMonth()->toDateString();

        DB::statement("CREATE TABLE IF NOT EXISTS {$name} PARTITION OF analytics_events FOR VALUES FROM ('{$start}') TO ('{$end}')");
    }
};
