<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAdminStore } from './stores/admin'

const admin = useAdminStore()
const router = useRouter()

async function signOut() {
  await admin.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="shell">
    <aside v-if="admin.isStaff" class="shell__nav">
      <div class="shell__brand">
        Kavo <span class="shell__badge">platform</span>
      </div>

      <nav>
        <RouterLink to="/">Metrics</RouterLink>
        <RouterLink to="/tenants">Tenants</RouterLink>
        <RouterLink to="/plans">Plans</RouterLink>
        <RouterLink to="/audit">Audit log</RouterLink>
      </nav>

      <button class="shell__signout" @click="signOut">Sign out</button>
    </aside>

    <main class="shell__main">
      <RouterView />
    </main>
  </div>
</template>

<style>
:root { --bg: #ffffff; --fg: #111827; --muted: #6b7280; --line: #e5e7eb; --accent: #b45309; }

@media (prefers-color-scheme: dark) {
  :root { --bg: #0b0f19; --fg: #e5e7eb; --muted: #9ca3af; --line: #1f2937; --accent: #f59e0b; }
}

* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--fg); }

.shell { display: flex; min-height: 100vh; }
.shell__nav { width: 15rem; flex-shrink: 0; border-right: 1px solid var(--line); padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 1rem; }
.shell__brand { font-weight: 650; }

/* Visually distinct from the merchant dashboard on purpose: it should never
   be ambiguous which console you are looking at. */
.shell__badge { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--accent); border: 1px solid var(--accent); border-radius: 4px; padding: 0.1rem 0.3rem; }

.shell__nav nav { display: flex; flex-direction: column; gap: 0.25rem; }
.shell__nav a { color: var(--fg); text-decoration: none; padding: 0.4rem 0.5rem; border-radius: 6px; font-size: 0.925rem; }
.shell__nav a.router-link-active { background: var(--line); }
.shell__signout { margin-top: auto; background: none; border: 1px solid var(--line); color: var(--muted); padding: 0.45rem; border-radius: 6px; cursor: pointer; }
.shell__main { flex: 1; padding: 1.5rem 2rem; max-width: 66rem; }

h1 { font-size: 1.35rem; margin-top: 0; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--line); }
th { color: var(--muted); font-weight: 500; }
label { display: block; font-size: 0.85rem; color: var(--muted); margin-bottom: 0.25rem; }
input, select { width: 100%; padding: 0.5rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); }
button.primary { background: var(--accent); color: #fff; border: none; padding: 0.55rem 1rem; border-radius: 6px; cursor: pointer; }
.field { margin-bottom: 0.85rem; }
.error { color: #dc2626; font-size: 0.85rem; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-bottom: 1.75rem; }
.card { border: 1px solid var(--line); border-radius: 8px; padding: 0.85rem; }
.card__label { font-size: 0.78rem; color: var(--muted); }
.card__value { font-size: 1.5rem; font-weight: 600; }

@media (max-width: 720px) {
  .shell { flex-direction: column; }
  .shell__nav { width: 100%; border-right: none; border-bottom: 1px solid var(--line); }
  .shell__main { padding: 1rem; }
}
</style>
