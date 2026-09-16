<script setup lang="ts">
import { ApiError } from '@kavo/api-client'
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({ name: '', email: '', password: '', workspace: '', product: 'fashion' })
const errors = ref<Record<string, string[]>>({})
const busy = ref(false)

async function submit() {
  errors.value = {}
  busy.value = true

  try {
    await auth.register({ ...form })
    router.push({ name: 'overview' })
  } catch (e) {
    if (e instanceof ApiError && e.isValidation) errors.value = e.errors
    else errors.value = { general: ['Could not create the workspace.'] }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="auth" @submit.prevent="submit">
    <h1>Create a workspace</h1>

    <div class="field">
      <label for="name">Your name</label>
      <input id="name" v-model="form.name" required />
      <p v-if="errors.name" class="error">{{ errors.name[0] }}</p>
    </div>

    <div class="field">
      <label for="workspace">Workspace name</label>
      <input id="workspace" v-model="form.workspace" required />
      <p v-if="errors.workspace" class="error">{{ errors.workspace[0] }}</p>
    </div>

    <div class="field">
      <label for="product">Product</label>
      <select id="product" v-model="form.product">
        <option value="fashion">Fashion</option>
        <option value="courses">Courses</option>
        <option value="beauty">Beauty</option>
        <option value="autoparts">Auto Parts</option>
      </select>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" v-model="form.email" type="email" required autocomplete="email" />
      <p v-if="errors.email" class="error">{{ errors.email[0] }}</p>
    </div>

    <div class="field">
      <label for="password">Password</label>
      <input id="password" v-model="form.password" type="password" required autocomplete="new-password" />
      <p v-if="errors.password" class="error">{{ errors.password[0] }}</p>
    </div>

    <p v-if="errors.general" class="error">{{ errors.general[0] }}</p>

    <button class="primary" type="submit" :disabled="busy">{{ busy ? 'Creating…' : 'Create workspace' }}</button>

    <p><RouterLink to="/login">Already have an account?</RouterLink></p>
  </form>
</template>

<style scoped>
.auth { max-width: 22rem; margin: 3rem auto; }
</style>
