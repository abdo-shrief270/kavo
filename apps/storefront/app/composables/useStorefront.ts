import type { StorefrontConfig } from '~~/server/utils/tenant'

/**
 * The tenant's identity, theme and design tokens.
 *
 * Fetched once per request during SSR and shared through Nuxt's payload —
 * every component that needs it gets the same object rather than issuing its
 * own request.
 */
export function useStorefront() {
  const config = useState<StorefrontConfig | null>('storefront-config', () => null)

  const load = async () => {
    if (config.value) return config.value

    const { data } = await useFetch<StorefrontConfig>('/api/config', {
      key: 'storefront-config-fetch',
      query: { host: useTenantHost() },
    })

    config.value = data.value ?? null

    return config.value
  }

  const tenant = computed(() => config.value?.tenant ?? null)
  const theme = computed(() => config.value?.theme ?? null)
  const settings = computed(() => config.value?.settings ?? {})

  /**
   * Design tokens become CSS custom properties rather than inline styles, so
   * a tenant can restyle the whole storefront without the components knowing
   * anything about theming.
   */
  const cssVariables = computed(() => {
    const tokens = config.value?.design_tokens ?? {}
    const vars: Record<string, string> = {}

    for (const [group, values] of Object.entries(tokens)) {
      for (const [name, value] of Object.entries(values ?? {})) {
        vars[`--kavo-${group}-${name}`] = String(value)
      }
    }

    return vars
  })

  return { config, tenant, theme, settings, cssVariables, load }
}
