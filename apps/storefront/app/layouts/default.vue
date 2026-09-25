<script setup lang="ts">
const { tenant, cssVariables, load } = useStorefront()
const { count, refresh } = useCart()

await load()

// Client-only: the cart token is an httpOnly cookie on this origin, which a
// browser attaches by itself and an SSR render does not. Loading it here
// rather than on each page means the count is right after a navigation, not
// only on the cart page.
onMounted(() => { refresh() })

useHead(() => ({
  titleTemplate: (title?: string) => (title ? `${title} · ${tenant.value?.name ?? 'Kavo'}` : (tenant.value?.name ?? 'Kavo')),
}))
</script>

<template>
  <div class="storefront" :style="cssVariables">
    <header class="storefront__header">
      <NuxtLink to="/" class="storefront__brand">{{ tenant?.name ?? 'Storefront' }}</NuxtLink>

      <NuxtLink to="/cart" class="storefront__basket">
        Basket<span v-if="count" class="storefront__count">{{ count }}</span>
      </NuxtLink>
    </header>

    <main class="storefront__main">
      <slot />
    </main>

    <footer class="storefront__footer">
      <small>Powered by Kavo</small>
    </footer>
  </div>
</template>

<style>
:root {
  --kavo-color-primary: #111827;
  --kavo-color-surface: #ffffff;
  --kavo-color-text: #111827;
}

body {
  margin: 0;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  background: var(--kavo-color-surface);
  color: var(--kavo-color-text);
}

.storefront__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.storefront__header,
.storefront__footer {
  padding: 1rem 1.5rem;
}

.storefront__basket {
  color: inherit;
  text-decoration: none;
  font-weight: 500;
}

.storefront__count {
  display: inline-block;
  margin-left: 0.4rem;
  min-width: 1.4rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--kavo-color-primary);
  color: var(--kavo-color-surface);
  font-size: 0.8rem;
  text-align: center;
}

.storefront__brand {
  font-weight: 650;
  font-size: 1.125rem;
  color: var(--kavo-color-primary);
  text-decoration: none;
}

.storefront__main {
  padding: 0 1.5rem 3rem;
  max-width: 72rem;
  margin: 0 auto;
}
</style>
