export interface TenantSummary {
  id: number
  name: string
  slug: string
  product: string
  role?: string
}

export interface AuthUser {
  id: number
  name: string
  email: string
  is_platform_admin: boolean
  active_tenant_id: number | null
  tenants: TenantSummary[]
}

export interface UsageMetric {
  metric: string
  used: number
  remaining: number | null
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

export interface QuotaDetail {
  metric: string
  used: number
  limit: number | null
  remaining: number | null
  overage_behavior: string
}

export interface AppNotification {
  id: string
  type: string
  data: {
    type: string
    metric?: string
    threshold?: number
    used?: number
    limit?: number | null
    at_limit?: boolean
    message?: string
  }
  read_at: string | null
  created_at: string
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: Record<string, string[]> = {},
    readonly quota?: QuotaDetail,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Laravel returns 422 with a field-keyed bag; forms render it directly. */
  get isValidation(): boolean {
    return this.status === 422
  }

  /**
   * 402, not 403. The tenant *may* do this — on a larger plan — so the UI
   * shows an upgrade prompt rather than an error. `quota` carries the detail
   * needed to say which limit and by how much.
   */
  get isQuotaExceeded(): boolean {
    return this.status === 402
  }

  get isUnauthenticated(): boolean {
    return this.status === 401 || this.status === 419
  }
}

/*
 | Commerce. The merchant's view of their own catalogue and order book —
 | richer than the storefront's, which publishes availability as a boolean and
 | never the numbers behind it.
 */

export interface ProductOptionAxis {
  name: string
  values: string[]
}

export interface ProductVariant {
  id: number
  sku: string
  options: Record<string, string>
  option_signature: string
  price_cents: number
  compare_at_price_cents: number | null
  track_inventory: boolean
  /** What is physically there. */
  stock_on_hand: number
  /** What unpaid orders are already holding. */
  stock_reserved: number
  position: number
  is_active: boolean
}

export type ProductStatus = 'draft' | 'active' | 'archived'

export interface Product {
  id: number
  name: string
  slug: string
  description: string | null
  status: ProductStatus
  options: ProductOptionAxis[]
  currency: string
  published_at: string | null
  variants: ProductVariant[]
}

export type OrderStatus = 'pending' | 'awaiting_payment' | 'paid' | 'cancelled' | 'expired' | 'refunded'

export interface OrderItem {
  id: number
  product_variant_id: number | null
  /** Snapshots, frozen when the order was placed. Never read through the variant. */
  product_name: string
  variant_sku: string
  options: Record<string, string>
  unit_price_cents: number
  quantity: number
  total_cents: number
}

export interface Order {
  id: number
  number: number
  status: OrderStatus
  /** Where this order's stock is: reserved, committed to the sale, or given back. */
  inventory_state: 'reserved' | 'committed' | 'released'
  customer_name: string
  customer_email: string
  customer_phone: string
  shipping_address: Record<string, string>
  subtotal_cents: number
  shipping_cents: number
  total_cents: number
  currency: string
  payment_intent_id: number | null
  placed_at: string
  paid_at: string | null
  closed_at: string | null
  items: OrderItem[]
}

/**
 * What the customer still has to do. Null once the payment is settled or its
 * window has closed — a paid order must not still be showing a kiosk code.
 */
export type CustomerAction =
  | { type: 'redirect'; url: string }
  | { type: 'reference'; reference: string; expires_at: string | null }
  | null

export interface PaymentSummary {
  id: number
  reference: string
  gateway: string
  rail: string
  status: string
  amount_cents: number
  currency: string
  payment_reference: string | null
  expires_at: string | null
  settled_at: string | null
  last_error: string | null
}
