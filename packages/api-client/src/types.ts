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
