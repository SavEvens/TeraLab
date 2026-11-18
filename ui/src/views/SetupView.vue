<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="auth-card">
    <h1>Create administrator</h1>
    <p class="hint">This page works only while no administrator exists.</p>
    <form @submit.prevent="submit">
      <label>Username <input v-model="username" autocomplete="username" required /></label>
      <label>Email <input v-model="email" type="email" autocomplete="email" /></label>
      <label>Password <input v-model="password" type="password" autocomplete="new-password" required /></label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button type="submit" :disabled="busy">{{ busy ? 'Creating…' : 'Create administrator' }}</button>
    </form>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { setup } from '../stores/session'
import { notify } from '../stores/toasts'

const username = ref('admin')
const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')
const router = useRouter()

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await setup(username.value, password.value, email.value)
    notify('success', 'Administrator created')
    router.push({ name: 'labs' })
  } catch (exception) {
    error.value = exception.message
  } finally {
    busy.value = false
  }
}
</script>
