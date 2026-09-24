<script setup lang="ts">
const { tenant, theme, settings } = useStorefront()

useSeoMeta({
  title: 'Home',
  description: () => `Shop ${tenant.value?.name ?? ''}`.trim(),
})
</script>

<template>
  <section>
    <h1>{{ (settings as any)?.hero?.headline ?? tenant?.name }}</h1>

    <p v-if="theme">
      Rendering theme <strong>{{ theme.code }}</strong> v{{ theme.version }}
      with {{ theme.sections.length }} section(s).
    </p>
    <p v-else>
      No published theme yet. Publish one from the merchant dashboard.
    </p>

    <p>
      <NuxtLink to="/products">Browse the shop →</NuxtLink>
    </p>
  </section>
</template>
