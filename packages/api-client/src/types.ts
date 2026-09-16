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

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Laravel returns 422 with a field-keyed bag; forms render it directly. */
  get isValidation(): boolean {
    return this.status === 422
  }

  get isUnauthenticated(): boolean {
    return this.status === 401 || this.status === 419
  }
}
