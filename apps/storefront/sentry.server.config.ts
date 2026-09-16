import * as Sentry from '@sentry/nuxt'

// Nitro-side errors: a failed SSR render, an API call that timed out during
// server rendering. Without this half, the failures that produce a blank page
// are exactly the ones nobody sees.
if (process.env.NUXT_PUBLIC_SENTRY_DSN) {
  Sentry.init({
    dsn: process.env.NUXT_PUBLIC_SENTRY_DSN,
    environment: process.env.NUXT_PUBLIC_SENTRY_ENVIRONMENT || 'local',
    release: process.env.NUXT_PUBLIC_APP_RELEASE || undefined,
    tracesSampleRate: Number(process.env.NUXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE || 0.1),
    sendDefaultPii: false,
    initialScope: { tags: { surface: 'storefront-ssr' } },
  })
}
