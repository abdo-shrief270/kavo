export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  devtools: { enabled: true },

  modules: [
    // Self-hosts font files, removing an external round trip on first paint.
    // Worth real milliseconds on MENA latency.
    '@nuxt/fonts',
  ],

  runtimeConfig: {
    // Server-side only. The storefront talks to the API over the internal
    // network during SSR, so this never reaches the browser.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000',
    public: {
      // Used by the browser for client-side navigation and Echo.
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
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
    '/account/**': { ssr: false, robots: false },
    '/checkout/**': { ssr: false, robots: false },
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
