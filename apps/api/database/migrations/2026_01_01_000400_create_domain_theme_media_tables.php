<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            // pending | verifying | active | failed
            $table->string('status')->default('pending')->index();
            $table->string('verification_token');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('ssl_issued_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // Theme definitions are platform catalogue, shared across tenants.
        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('product')->index();
            $table->string('version')->default('1.0.0');
            $table->jsonb('manifest')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::create('tenant_theme_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('theme_code');
            $table->jsonb('settings')->default('{}');
            $table->jsonb('design_tokens')->default('{}');
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'theme_code']);
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('filename');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();
            $table->nullableMorphs('owner');
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'checksum']);
        });
    }

    public function down(): void
    {
        foreach (['media', 'tenant_theme_settings', 'themes', 'domains'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
