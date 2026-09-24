export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  devtools: { enabled: true },

  modules: [
    // Self-hosts font files, removing an external round trip on first paint.
    // Worth real milliseconds on MENA latency.
    '@nuxt/fonts',
    // Covers both halves of SSR: the Nitro server and the browser bundle.
    // Without it a server-side render error never leaves the box.
    '@sentry/nuxt/module',
  ],

  sentry: {
    sourceMapsUploadOptions: {
      // Only uploaded when an auth token is present, so a local or CI build
      // without credentials still succeeds instead of failing at the end.
      enabled: Boolean(process.env.SENTRY_AUTH_TOKEN),
      org: process.env.SENTRY_ORG,
      project: process.env.SENTRY_PROJECT,
    },
  },

  runtimeConfig: {
    // Server-side only. The storefront talks to the API over the internal
    // network during SSR, so this never reaches the browser.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000',
    // Proves to the API that a call came from this server rather than from a
    // browser whose headers a proxy forwarded. Server-side only, by
    // definition: a secret in the browser bundle is not a secret.
    internalToken: process.env.NUXT_INTERNAL_TOKEN || '',
    public: {
      // Used by the browser for client-side navigation and Echo.
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
      sentry: {
        dsn: process.env.NUXT_PUBLIC_SENTRY_DSN || '',
        environment: process.env.NUXT_PUBLIC_SENTRY_ENVIRONMENT || 'local',
        release: process.env.NUXT_PUBLIC_APP_RELEASE || '',
        tracesSampleRate: Number(process.env.NUXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE || 0.1),
      },
      reverb: {
        key: process.env.NUXT_PUBLIC_REVERB_KEY || '',
        host: process.env.NUXT_PUBLIC_REVERB_HOST || 'localhost',
        port: Number(process.env.NUXT_PUBLIC_REVERB_PORT || 8080),
        scheme: process.env.NUXT_PUBLIC_REVERB_SCHEME || 'http',
      },
    },
  },

  /*
   * Rendering strategy per route is the single biggest lever on storefront
   * speed. Product and category pages are read-mostly and SEO-critical, so
   * they are cached and revalidated in the background; anything personal is
   * never cached and never prerendered.
   */
  routeRules: {
    '/': { swr: 300 },
    '/collections/**': { swr: 600 },
    '/products/**': { swr: 600 },
    // Personal and stateful. Caching these would serve one shopper's cart
    // to another.
    '/cart': { ssr: false },
    // X-Robots-Tag rather than a `robots` route rule: that key belongs to
    // @nuxtjs/robots, which is not installed, so it was silently ignored and
    // these pages were indexable after all. The header needs no module and
    // works on a client-rendered route, where a meta tag would depend on the
    // crawler executing JavaScript.
    '/account/**': { ssr: false, headers: { 'X-Robots-Tag': 'noindex, nofollow' } },
    '/checkout/**': { ssr: false, headers: { 'X-Robots-Tag': 'noindex, nofollow' } },
  },

  nitro: {
    compressPublicAssets: true,
  },

  app: {
    head: {
      htmlAttrs: { lang: 'en' },
      meta: [{ name: 'viewport', content: 'width=device-width, initial-scale=1' }],
    },
  },

  typescript: { strict: true },
})
