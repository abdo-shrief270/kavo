<script setup lang="ts">
const { tenant, cssVariables, load } = useStorefront()

await load()

useHead(() => ({
  titleTemplate: (title?: string) => (title ? `${title} · ${tenant.value?.name ?? 'Kavo'}` : (tenant.value?.name ?? 'Kavo')),
}))
</script>

<template>
  <div class="storefront" :style="cssVariables">
    <header class="storefront__header">
      <NuxtLink to="/" class="storefront__brand">{{ tenant?.name ?? 'Storefront' }}</NuxtLink>
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

.storefront__header,
.storefront__footer {
  padding: 1rem 1.5rem;
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
