<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="auth-card">
    <h1>Sign in</h1>
    <form @submit.prevent="submit">
      <label>Username <input v-model="username" autocomplete="username" required /></label>
      <label>Password <input v-model="password" type="password" autocomplete="current-password" required /></label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
    </form>
    <p class="hint">First run? <router-link to="/setup">Create the administrator</router-link>.</p>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login } from '../stores/session'
import { notify } from '../stores/toasts'

const username = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')
const router = useRouter()
const route = useRoute()

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await login(username.value, password.value)
    notify('success', 'Signed in')
    router.push(route.query.next || { name: 'labs' })
  } catch (exception) {
    error.value = exception.message
  } finally {
    busy.value = false
  }
}
</script>
