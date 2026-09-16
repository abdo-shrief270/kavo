<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function signOut() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="shell">
    <aside v-if="auth.isAuthenticated" class="shell__nav">
      <div class="shell__brand">Kavo</div>

      <select
        v-if="(auth.user?.tenants.length ?? 0) > 1"
        class="shell__tenant"
        :value="auth.currentTenant?.slug"
        @change="auth.selectTenant(($event.target as HTMLSelectElement).value)"
      >
        <option v-for="t in auth.user?.tenants" :key="t.slug" :value="t.slug">{{ t.name }}</option>
      </select>
      <div v-else class="shell__tenant-name">{{ auth.currentTenant?.name }}</div>

      <nav>
        <RouterLink to="/">Overview</RouterLink>
        <RouterLink to="/domains">Domains</RouterLink>
        <RouterLink to="/webhooks">Webhooks</RouterLink>
      </nav>

      <button class="shell__signout" @click="signOut">Sign out</button>
    </aside>

    <main class="shell__main">
      <RouterView />
    </main>
  </div>
</template>

<style>
:root {
  --bg: #ffffff;
  --fg: #111827;
  --muted: #6b7280;
  --line: #e5e7eb;
  --accent: #111827;
}

@media (prefers-color-scheme: dark) {
  :root { --bg: #0b0f19; --fg: #e5e7eb; --muted: #9ca3af; --line: #1f2937; --accent: #e5e7eb; }
}

* { box-sizing: border-box; }

body {
  margin: 0;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  background: var(--bg);
  color: var(--fg);
}

.shell { display: flex; min-height: 100vh; }

.shell__nav {
  width: 15rem;
  flex-shrink: 0;
  border-right: 1px solid var(--line);
  padding: 1.25rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.shell__brand { font-weight: 650; font-size: 1.125rem; }
.shell__tenant, .shell__tenant-name { font-size: 0.875rem; color: var(--muted); }
.shell__nav nav { display: flex; flex-direction: column; gap: 0.25rem; }
.shell__nav a { color: var(--fg); text-decoration: none; padding: 0.4rem 0.5rem; border-radius: 6px; font-size: 0.925rem; }
.shell__nav a.router-link-active { background: var(--line); }
.shell__signout { margin-top: auto; background: none; border: 1px solid var(--line); color: var(--muted); padding: 0.45rem; border-radius: 6px; cursor: pointer; }
.shell__main { flex: 1; padding: 1.5rem 2rem; max-width: 60rem; }

h1 { font-size: 1.35rem; margin-top: 0; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--line); }
th { color: var(--muted); font-weight: 500; }
label { display: block; font-size: 0.85rem; color: var(--muted); margin-bottom: 0.25rem; }
input, select { width: 100%; padding: 0.5rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); }
button.primary { background: var(--accent); color: var(--bg); border: none; padding: 0.55rem 1rem; border-radius: 6px; cursor: pointer; }
.field { margin-bottom: 0.85rem; }
.error { color: #dc2626; font-size: 0.85rem; }

@media (max-width: 720px) {
  .shell { flex-direction: column; }
  .shell__nav { width: 100%; border-right: none; border-bottom: 1px solid var(--line); }
  .shell__main { padding: 1rem; }
}
</style>
