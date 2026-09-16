<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Platform staff. Gates the super-admin surface, which is a
            // separate SPA on a separate origin from the merchant dashboard.
            $table->boolean('is_platform_admin')->default(false)->index();

            // Last tenant this user worked in. A convenience for resolution
            // order, never an authorisation decision on its own.
            $table->foreignId('active_tenant_id')->nullable();

            $table->string('phone')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_platform_admin', 'active_tenant_id', 'phone', 'last_seen_at']);
        });
    }
};
