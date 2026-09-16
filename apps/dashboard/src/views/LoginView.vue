<script setup lang="ts">
import { ApiError } from '@kavo/api-client'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true

  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'overview' })
  } catch (e) {
    // The API returns one generic message for both bad email and bad
    // password, so this must not try to be more specific than it is.
    error.value = e instanceof ApiError ? e.message : 'Could not sign in.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="auth" @submit.prevent="submit">
    <h1>Sign in</h1>

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

    <p><RouterLink to="/register">Create a workspace</RouterLink></p>
  </form>
</template>

<style scoped>
.auth { max-width: 22rem; margin: 4rem auto; }
</style>
