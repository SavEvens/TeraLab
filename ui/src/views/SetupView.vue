<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="auth-shell">
    <section class="auth-card">
      <div class="auth-brand">
        <h1>TERALAB</h1>
        <p>First Run Setup</p>
      </div>
      <h2>Create administrator</h2>
      <p class="hint">This page works only while no administrator exists and requires the one-time setup token.</p>
      <form @submit.prevent="submit">
      <label>Setup token
        <input
          v-model="setupToken"
          type="password"
          aria-label="Setup token"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          required
        />
      </label>
      <p class="hint">The installer writes the token to <code>/etc/teralab/setup.token</code> on the server. It is used once and never stored by this page.</p>
      <label>Username <input v-model="username" autocomplete="username" required /></label>
      <label>Email <input v-model="email" type="email" autocomplete="email" /></label>
      <label>Password <input v-model="password" type="password" autocomplete="new-password" required /></label>
      <p v-if="error" class="error" role="alert" data-test="setup-error">{{ error }}</p>
      <p v-if="alreadyCompleted" class="hint" data-test="setup-login-link">
        <router-link to="/login">Go to sign in</router-link>
      </p>
      <button type="submit" :disabled="busy">{{ busy ? 'Creating…' : 'Create administrator' }}</button>
      </form>
      <TurnstileTestKeyNotice v-if="turnstileTestKeys" class="auth-warning" />
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import { setup } from '../stores/session'
import { notify } from '../stores/toasts'
import TurnstileTestKeyNotice from '../components/TurnstileTestKeyNotice.vue'

const username = ref('admin')
const email = ref('')
const password = ref('')
const setupToken = ref('')
const busy = ref(false)
const error = ref('')
const alreadyCompleted = ref(false)
const turnstileTestKeys = ref(false)
const router = useRouter()

onMounted(async () => {
  try {
    const flags = await api.health()
    turnstileTestKeys.value = flags?.turnstile?.test_keys === true
  } catch {
    // Health is advisory here: never block setup because the probe failed.
    turnstileTestKeys.value = false
  }
})

// Surface the server's decision with actionable context instead of a raw
// message: 401/403 mean the operator token is wrong or missing, 409 means the
// first administrator already exists.
function setupError(exception) {
  const status = exception?.status
  if (status === 401 || status === 403) {
    return 'The setup token is missing or invalid. Enter the current token from /etc/teralab/setup.token.'
  }
  if (status === 409) {
    alreadyCompleted.value = true
    return 'Setup has already been completed. Sign in with the administrator account instead.'
  }
  if (status === 429) {
    return 'Too many setup attempts. Wait a minute and try again.'
  }
  const message = typeof exception?.message === 'string' && exception.message ? exception.message : ''
  return message ? `Setup failed: ${message}` : 'Setup failed. Check the server log and try again.'
}

async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  alreadyCompleted.value = false
  try {
    await setup(username.value, password.value, email.value, setupToken.value)
    // The token is single-use and intentionally not cached anywhere.
    setupToken.value = ''
    notify('success', 'Administrator created')
    router.push({ name: 'labs' })
  } catch (exception) {
    error.value = setupError(exception)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.auth-warning {
  margin-top: 16px;
}

.auth-card code {
  color: var(--muted-2);
}
</style>
