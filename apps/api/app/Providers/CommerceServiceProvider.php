<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Commerce\Orders\Console\PruneAbandonedCarts;
use App\Modules\Commerce\Orders\Listeners\SettleOrder;
use App\Shared\Events\PaymentSettled;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the first vertical.
 *
 * Separate from PlatformServiceProvider rather than appended to it, because
 * the whole architecture rests on the platform not knowing which verticals
 * exist. A platform provider that registers an orders listener has made the
 * platform depend on commerce in the one place nothing else can see.
 */
final class CommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         | The seam between the payment rails and the catalogue.
         |
         | Billing announces that a payment reached a terminal state and knows
         | nothing about orders; Commerce decides what that means for stock.
         | Both the synchronous card path and a Fawry callback arriving three
         | days later land here, which is why this is one registration rather
         | than a branch in the checkout.
         */
        Event::listen(PaymentSettled::class, [SettleOrder::class, 'handle']);

        $this->registerCommands();
        $this->registerSchedule();
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneAbandonedCarts::class]);
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function (): void {
            // Carts hold no stock, so a missed run costs disk, not
            // correctness. Daily is plenty.
            $this->app->make(Schedule::class)
                ->command('kavo:prune-carts')
                ->dailyAt('03:20')
                ->withoutOverlapping();
        });
    }
}
