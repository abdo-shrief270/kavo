<script setup lang="ts">
import { ApiError } from '@kavo/api-client'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminStore } from '../stores/admin'

const admin = useAdminStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true

  try {
    await admin.login(email.value, password.value)

    // Valid credentials are not the same as platform access. Saying so
    // plainly beats bouncing them to an empty console full of 403s.
    if (!admin.isStaff) {
      error.value = 'This account does not have platform access.'
      await admin.logout()

      return
    }

    router.push({ name: 'metrics' })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not sign in.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="auth" @submit.prevent="submit">
    <h1>Kavo platform</h1>
    <p class="hint">Staff access only.</p>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" v-model="email" type="email" required autocomplete="email" />
    </div>

    <div class="field">
      <label for="password">Password</label>
      <input id="password" v-model="password" type="password" required autocomplete="current-password" />
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <button class="primary" type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
  </form>
</template>

<style scoped>
.auth { max-width: 22rem; margin: 4rem auto; }
.hint { color: var(--muted); font-size: 0.875rem; }
</style>
