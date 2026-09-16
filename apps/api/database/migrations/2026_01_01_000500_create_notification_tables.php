<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's native table, used by the database (in-app) channel.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('event_type');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'channel', 'event_type'], 'notification_preferences_unique');
        });

        // WhatsApp requires pre-approved templates for business-initiated
        // messages outside the 24-hour window. Modelling approval state means
        // an unapproved send is rejected here rather than failing silently
        // at the provider.
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('language', 10)->default('en');
            $table->text('body');
            $table->jsonb('variables')->default('[]');
            // pending | approved | rejected | disabled
            $table->string('approval_status')->default('pending')->index();
            $table->string('provider_template_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->index();
            $table->string('event_type');
            $table->string('recipient');
            $table->string('template_code')->nullable();
            // queued | sent | delivered | read | failed
            $table->string('status')->default('queued')->index();
            // Provider message id, for reconciling status callbacks.
            $table->string('provider_message_id')->nullable()->index();
            $table->jsonb('payload')->default('{}');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'whatsapp_templates', 'notification_preferences', 'notifications'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
