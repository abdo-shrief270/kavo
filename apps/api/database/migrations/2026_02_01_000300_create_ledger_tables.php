<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform owes each merchant, and what it has paid them.
 *
 * The platform is the merchant of record (ADR 0001 B10): every customer pays
 * into the platform's own Paymob and Fawry accounts, so every settled order
 * creates a debt. Subscriptions and invoices already carry money *from* a
 * tenant; nothing carried it back, which meant the first real order would have
 * left a merchant's takings in the platform's account with no record of whose
 * they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // sale | commission | refund | commission_reversed | payout |
            // payout_reversed | adjustment
            $table->string('type');

            /*
             | Signed. Positive is owed to the merchant, negative is taken off
             | what they are owed. One column rather than debit/credit pair
             | because the balance is then a sum and cannot disagree with
             | itself — there is no second column to get the sign wrong in.
             */
            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('EGP');

            /*
             | What caused this entry, as a slug and an id rather than a real
             | foreign key. The ledger is a financial record and must outlive
             | the thing it refers to; it also must not make the platform
             | depend on a vertical's tables, which is the same asymmetry
             | Deptrac enforces in the code.
             */
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);

            /*
             | One entry per kind of event per source. A retried settlement,
             | a replayed webhook or a double-clicked payout cannot credit the
             | same order twice — the same guarantee payment_events gives the
             | payment rail, for the same reason.
             */
            $table->unique(['tenant_id', 'type', 'source_type', 'source_id']);
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Per tenant, like order numbers and for the same reason: a
            // merchant reconciling their fourth payout should see #4.
            $table->unsignedBigInteger('number');

            // pending | paid | failed
            $table->string('status')->default('pending');

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('EGP');

            // bank_transfer | instapay | wallet | cash
            $table->string('method');
            // Account number, wallet number — whatever the method needs.
            $table->jsonb('destination')->default('{}');

            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        foreach (['ledger_entries', 'payouts'] as $table) {
            $this->protect($table);
        }
    }

    public function down(): void
    {
        foreach (['payouts', 'ledger_entries'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** The same two-layer isolation every tenant table gets. */
    private function protect(string $table): void
    {
        $guc = config('kavo.tenancy.guc');

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
            USING (tenant_id = NULLIF(current_setting('{$guc}', true), '')::bigint)
            WITH CHECK (tenant_id = NULLIF(current_setting('{$guc}', true), '')::bigint)
        SQL);
    }
};
