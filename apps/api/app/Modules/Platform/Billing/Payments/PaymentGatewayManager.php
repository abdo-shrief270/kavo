<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

use App\Shared\Contracts\PaymentGateway;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Picks the gateway for a rail.
 *
 * Egypt needs two providers rather than one: Paymob has the better API and
 * covers cards, wallets and Apple/Google Pay, while Fawry is how a large
 * share of the market actually pays. Routing by rail keeps callers from
 * knowing either name.
 */
final class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function for(PaymentRail $rail): PaymentGateway
    {
        $configured = config('kavo.payments.rails.'.$rail->value);

        if ($configured === null) {
            throw new RuntimeException("No gateway is configured for the {$rail->value} rail.");
        }

        $gateway = $this->gateway($configured);

        if (! $gateway->supports($rail)) {
            throw new RuntimeException("Gateway [{$configured}] does not support the {$rail->value} rail.");
        }

        return $gateway;
    }

    public function gateway(string $name): PaymentGateway
    {
        return $this->resolved[$name] ??= $this->make($name);
    }

    /** Rails a customer can actually be offered right now. */
    public function availableRails(): array
    {
        return array_values(array_filter(
            PaymentRail::cases(),
            function (PaymentRail $rail): bool {
                try {
                    $this->for($rail);

                    return true;
                } catch (RuntimeException) {
                    return false;
                }
            },
        ));
    }

    private function make(string $name): PaymentGateway
    {
        $class = config('kavo.payments.gateways.'.$name);

        if ($class === null) {
            throw new RuntimeException("Unknown payment gateway [{$name}].");
        }

        return $this->container->make($class);
    }
}
