<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useEcho } from '../composables/useEcho'
import { useAuthStore } from '../stores/auth'
import { useNotificationStore } from '../stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationStore()
const open = ref(false)

const channel = computed(() =>
  auth.currentTenant ? `private-tenant.${auth.currentTenant.id}` : null,
)

const { connected, on } = useEcho(
  () => channel.value,
  import.meta.env.VITE_API_BASE ?? 'http://localhost:8000',
)

// The broadcast is a nudge, not the payload: it triggers a refetch so the
// list and the unread count come from the server either way. A dropped socket
// therefore delays the alert rather than losing it.
on('quota.threshold', () => notifications.load())

notifications.load()

// Switching workspace changes the channel and the notifications with it.
watch(() => auth.currentTenant?.id, () => notifications.load())

function relative(iso: string): string {
  const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`

  return `${Math.floor(seconds / 86400)}d ago`
}
</script>

<template>
  <div class="bell">
    <button class="bell__button" :aria-expanded="open" @click="open = !open">
      Alerts
      <span v-if="notifications.unread" class="bell__count" :class="{ 'bell__count--urgent': notifications.atLimit }">
        {{ notifications.unread }}
      </span>
      <!-- Honest about the transport: if the socket is down, alerts still
           arrive on the next load, and the merchant can see why they are
           not instant. -->
      <span class="bell__dot" :class="{ 'bell__dot--live': connected }" :title="connected ? 'Live' : 'Polling'" />
    </button>

    <div v-if="open" class="bell__panel">
      <header>
        <strong>Alerts</strong>
        <button v-if="notifications.unread" @click="notifications.markAllRead()">Mark all read</button>
      </header>

      <p v-if="notifications.loading" class="bell__empty">Loading…</p>
      <p v-else-if="!notifications.items.length" class="bell__empty">Nothing yet.</p>

      <ul v-else>
        <li
          v-for="item in notifications.items"
          :key="item.id"
          :class="{ 'is-unread': !item.read_at, 'is-urgent': item.data.at_limit }"
          @click="!item.read_at && notifications.markRead(item.id)"
        >
          <p>{{ item.data.message ?? item.data.type }}</p>
          <small>{{ relative(item.created_at) }}</small>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.bell { position: relative; }

.bell__button {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  width: 100%;
  padding: 0.45rem 0.5rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--bg);
  color: var(--fg);
  cursor: pointer;
  font-size: 0.9rem;
}

.bell__count {
  background: var(--fg);
  color: var(--bg);
  border-radius: 999px;
  padding: 0 0.4rem;
  font-size: 0.75rem;
}

.bell__count--urgent { background: #dc2626; color: #fff; }

.bell__dot {
  margin-left: auto;
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: var(--muted);
}

.bell__dot--live { background: #16a34a; }

.bell__panel {
  position: absolute;
  z-index: 10;
  top: calc(100% + 0.35rem);
  left: 0;
  right: 0;
  min-width: 17rem;
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 8px;
  box-shadow: 0 8px 24px rgb(0 0 0 / 12%);
  max-height: 20rem;
  overflow-y: auto;
}

.bell__panel header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid var(--line);
  font-size: 0.85rem;
}

.bell__panel header button {
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: 0.8rem;
}

.bell__panel ul { list-style: none; margin: 0; padding: 0; }

.bell__panel li {
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid var(--line);
  cursor: pointer;
}

.bell__panel li p { margin: 0 0 0.2rem; font-size: 0.85rem; }
.bell__panel li small { color: var(--muted); font-size: 0.75rem; }
.bell__panel li.is-unread { background: color-mix(in srgb, var(--line) 45%, transparent); }
.bell__panel li.is-urgent { border-left: 3px solid #dc2626; }
.bell__empty { padding: 0.9rem 0.75rem; margin: 0; color: var(--muted); font-size: 0.85rem; }
</style>
