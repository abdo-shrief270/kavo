import * as Sentry from '@sentry/nuxt'

const config = useRuntimeConfig()

// No DSN is the normal local case. Initialising with an empty one would pay
// the overhead and send nothing.
if (config.public.sentry.dsn) {
  Sentry.init({
    dsn: config.public.sentry.dsn,
    environment: config.public.sentry.environment,
    // Matches the backend's APP_RELEASE, so one issue traces to one deploy
    // across the whole stack.
    release: config.public.sentry.release,
    tracesSampleRate: config.public.sentry.tracesSampleRate,

    // The storefront is anonymous shoppers. There is nothing here we need to
    // identify, and plenty we should not collect.
    sendDefaultPii: false,

    initialScope: { tags: { surface: 'storefront' } },
  })
}
