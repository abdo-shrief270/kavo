import * as Sentry from '@sentry/vue'
import type { App } from 'vue'
import type { Router } from 'vue-router'

/**
 * Frontend errors go to the same Sentry project as the backend, tagged by
 * surface.
 *
 * One project rather than two: an error that starts in the API and surfaces
 * in the browser is one incident, and splitting it across projects means
 * seeing half of it in each. `surface` is what keeps them separable when you
 * do want them apart.
 */
export function initSentry(app: App, router: Router): void {
  const dsn = import.meta.env.VITE_SENTRY_DSN

  // No DSN is the normal local case, not a misconfiguration. Initialising
  // with an empty DSN would send nothing while still paying the overhead.
  if (!dsn) return

  Sentry.init({
    app,
    dsn,
    environment: import.meta.env.VITE_SENTRY_ENVIRONMENT ?? import.meta.env.MODE,
    // The git SHA, matching the backend's APP_RELEASE — which is what lets
    // one issue be traced to one deploy across both halves.
    release: import.meta.env.VITE_APP_RELEASE,
    integrations: [Sentry.browserTracingIntegration({ router })],
    tracesSampleRate: Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.1),

    // Never send request bodies or form values: a merchant console carries
    // customer data and a platform console carries everyone's.
    sendDefaultPii: false,

    initialScope: { tags: { surface: 'dashboard' } },

    beforeSend(event) {
      // 401/403/402 are answers, not faults. A merchant hitting a plan limit
      // or an expired session is expected behaviour, and reporting it buries
      // the real errors.
      const status = event.contexts?.response?.status_code
      if (status === 401 || status === 402 || status === 403 || status === 419) return null

      return event
    },
  })
}

/** Ties browser errors to the tenant, the same way the backend tags them. */
export function setSentryTenant(tenant: { id: number; slug: string; product: string } | null): void {
  Sentry.getCurrentScope().setTag('tenant_id', tenant ? String(tenant.id) : 'none')
  Sentry.getCurrentScope().setTag('tenant_slug', tenant?.slug ?? 'none')
  Sentry.getCurrentScope().setTag('product', tenant?.product ?? 'platform')
}

/** Id and staff flag only — no email or name. */
export function setSentryUser(user: { id: number; is_platform_admin: boolean } | null): void {
  Sentry.getCurrentScope().setUser(
    user ? { id: String(user.id), is_platform_admin: user.is_platform_admin } : null,
  )
}
