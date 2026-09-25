<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Models;

use App\Modules\Commerce\Orders\Enums\InventoryState;
use App\Modules\Commerce\Orders\Enums\OrderStatus;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Shared\Enums\PaymentStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The agreement. What was bought, by whom, for how much, and whether it has
 * been paid for.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $number
 * @property OrderStatus $status
 * @property InventoryState $inventory_state
 * @property string $customer_name
 * @property string $customer_email
 * @property string $customer_phone
 * @property array $shipping_address
 * @property int $subtotal_cents
 * @property int $shipping_cents
 * @property int $total_cents
 * @property string $currency
 * @property ?int $payment_intent_id
 * @property Carbon $placed_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $closed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read ?PaymentIntent $paymentIntent
 */
final class Order extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * Numbering starts here rather than at 1.
     *
     * A shop's first order reading #1 tells every customer they are the first
     * customer, and one reading #84,312 tells them how much the platform as a
     * whole has sold. #1001 says neither.
     */
    public const FIRST_NUMBER = 1000;

    protected $fillable = [
        'tenant_id', 'number', 'status', 'inventory_state',
        'customer_name', 'customer_email', 'customer_phone', 'shipping_address',
        'subtotal_cents', 'shipping_cents', 'total_cents', 'currency',
        'payment_intent_id', 'placed_at', 'paid_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'inventory_state' => InventoryState::class,
            'shipping_address' => 'array',
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** What the merchant quotes and what the gateway echoes back. */
    public function reference(): string
    {
        return 'ORD-'.$this->number;
    }

    /**
     * The order an intent was raised for, if any.
     *
     * The link lives in the intent's metadata, written when the intent is
     * created, because the intent exists before the order can point at it —
     * and a card rail settles synchronously, so a settlement can arrive while
     * the order's payment_intent_id column is still null. Resolving through
     * metadata is the only direction that is populated at every moment a
     * settlement can appear.
     */
    public static function forIntent(PaymentIntent $intent): ?self
    {
        $orderId = $intent->metadata['order_id'] ?? null;

        return $orderId === null
            ? null
            : self::query()->where('tenant_id', $intent->tenant_id)->find($orderId);
    }

    /**
     * How a payment outcome reads as an order outcome.
     *
     * One place, so the storefront, the settlement listener and the merchant
     * dashboard cannot each decide differently what an expired reference means.
     */
    public static function statusForPayment(PaymentStatus $payment): OrderStatus
    {
        return match ($payment) {
            PaymentStatus::Succeeded => OrderStatus::Paid,
            PaymentStatus::Expired => OrderStatus::Expired,
            PaymentStatus::Failed => OrderStatus::Cancelled,
            PaymentStatus::Refunded => OrderStatus::Refunded,
            // A reference issued or a redirect outstanding: placed, unpaid,
            // and holding its stock until one of the above arrives.
            default => OrderStatus::AwaitingPayment,
        };
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'number' => $this->number,
            'reference' => $this->reference(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'shipping_cents' => $this->shipping_cents,
            'total_cents' => $this->total_cents,
            'placed_at' => $this->placed_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'items' => $this->items->map(fn (OrderItem $item): array => $item->summary())->all(),
        ];
    }
}
