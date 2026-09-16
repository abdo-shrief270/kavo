<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Splits the merchant's reference from the one the gateway sees.
 *
 * `reference` is the merchant's own (order-1001) and is unique only per
 * tenant — deliberately, because two tenants may both have an order-1001.
 * That made it unusable for resolving a settlement: a provider callback
 * carries the reference and nothing else, so looking a tenant up by it alone
 * could return the wrong one and settle a stranger's payment.
 *
 * `public_reference` is platform-generated, opaque and globally unique. It is
 * what goes to the gateway and what comes back, so a callback resolves to
 * exactly one tenant or to none.
 *
 * Additive, so it is safe alongside code that has not deployed yet. The unique
 * index is built with a lock rather than concurrently, which is fine on a table
 * this size and would need splitting into its own migration if it ever is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->string('public_reference', 64)->nullable()->after('reference');
        });

        // Backfill before the unique index, so existing rows do not collide.
        DB::table('payment_intents')->whereNull('public_reference')->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('payment_intents')->where('id', $row->id)
                        ->update(['public_reference' => 'kv_'.Str::lower((string) Str::ulid())]);
                }
            });

        Schema::table('payment_intents', function (Blueprint $table): void {
            // Globally unique: this is the whole point.
            $table->unique('public_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropUnique(['public_reference']);
            $table->dropColumn('public_reference');
        });
    }
};
