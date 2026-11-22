<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="auth-shell">
    <section class="auth-card" :aria-busy="busy">
      <div class="auth-brand">
        <h1>TERALAB</h1>
        <p>Secure Lab Orchestration</p>
      </div>

      <p v-if="error" class="error auth-error" role="alert" data-test="auth-error">{{ error }}</p>

      <section
        v-if="demo.enabled"
        class="auth-panel auth-demo"
        :class="{ 'is-primary': demoPrimary }"
        data-test="demo-panel"
        aria-labelledby="demo-title"
      >
        <h2 id="demo-title">Demo access</h2>
        <p class="hint">
          Explore TeraLab with a temporary guest workspace. No account, password, or
          personal details are required.
        </p>
        <button
          type="button"
          class="demo-enter"
          data-test="enter-demo"
          :disabled="busy || captchaBlocked"
          @click="enterDemo"
        >{{ busyAction === 'guest' ? 'Preparing demo…' : 'Enter demo' }}</button>
      </section>

      <div v-if="turnstile.enabled" class="auth-captcha">
        <div ref="captchaEl" class="cf-turnstile" data-test="turnstile" aria-label="Security check"></div>
        <p v-if="turnstileError" class="error" role="alert" data-test="turnstile-error">{{ turnstileError }}</p>
        <p v-else-if="turnstileStatus === 'loading'" class="hint" role="status">Loading the security check…</p>
      </div>

      <div v-if="demo.enabled" class="auth-divider" aria-hidden="true"><span>or sign in</span></div>

      <form
        class="auth-panel auth-form"
        :class="{ 'is-primary': !demoPrimary }"
        data-test="credentials-panel"
        aria-label="Account sign-in"
        @submit.prevent="submit"
      >
        <h2 v-if="demo.enabled">Account sign-in</h2>
        <label>Username <input v-model="username" autocomplete="username" required /></label>
        <label>Password <input v-model="password" type="password" autocomplete="current-password" required /></label>
        <button type="submit" :disabled="busy || captchaBlocked">{{ busyAction === 'login' ? 'Signing in…' : 'Sign in' }}</button>
      </form>

      <p class="hint">First run? <router-link to="/setup">Create the administrator</router-link>.</p>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { guestLogin, login } from '../stores/session'
import { notify } from '../stores/toasts'
import { loadTurnstile } from '../turnstile'

const username = ref('')
const password = ref('')
const busy = ref(false)
const busyAction = ref('')
const error = ref('')
const demo = ref({ enabled: false, landing: 'login' })
const turnstile = ref({ enabled: false, site_key: '' })
const captchaEl = ref(null)
const turnstileToken = ref('')
const turnstileStatus = ref('idle')
const turnstileError = ref('')
const router = useRouter()
const route = useRoute()

let widgetApi = null
let widgetId = null
let pendingTokenRequest = null

const requestedMode = computed(() => {
  const mode = route.query.mode
  return Array.isArray(mode) ? mode[0] : mode
})
const demoPrimary = computed(() => demo.value.enabled && requestedMode.value === 'demo')
const captchaBlocked = computed(() => turnstile.value.enabled && turnstileStatus.value === 'error')

onMounted(async () => {
  await loadHealth()
  await nextTick()
  if (turnstile.value.enabled) await mountCaptcha()
})

onBeforeUnmount(() => {
  // Release a pending token request so its timer cannot fire after the widget
  // is gone; the promise resolves empty and no request is sent.
  settleTokenRequest('')
  if (widgetId === null || !widgetApi?.remove) return
  try {
    widgetApi.remove(widgetId)
  } catch {
    // The widget may already be gone along with the page.
  }
})

async function loadHealth() {
  let flags = null
  try {
    flags = await api.health()
  } catch {
    // A failed probe must not block the credential form. Demo UI stays hidden
    // and a captcha-gated login will fail closed server-side if it is enabled.
    return
  }
  const demoFlag = flags?.demo || {}
  const turnstileFlag = flags?.turnstile || {}
  demo.value = {
    enabled: demoFlag.enabled === true,
    landing: demoFlag.landing === 'demo' ? 'demo' : 'login'
  }
  turnstile.value = {
    enabled: turnstileFlag.enabled === true,
    site_key: typeof turnstileFlag.site_key === 'string' ? turnstileFlag.site_key : ''
  }

  const requested = typeof requestedMode.value === 'string' ? requestedMode.value : ''
  if (demo.value.enabled && !requested && demo.value.landing === 'demo') {
    await router.replace({ query: { ...route.query, mode: 'demo' } })
  }
}

async function mountCaptcha() {
  if (!turnstile.value.site_key) {
    failCaptcha('The security check is not configured. Demo entry and sign-in are unavailable.')
    return
  }
  turnstileStatus.value = 'loading'
  try {
    widgetApi = await loadTurnstile()
    if (!captchaEl.value) throw new Error('Captcha container is not mounted.')
    widgetId = widgetApi.render(captchaEl.value, {
      sitekey: turnstile.value.site_key,
      theme: 'dark',
      callback: (token) => {
        turnstileToken.value = token || ''
        settleTokenRequest(turnstileToken.value)
        if (turnstileStatus.value === 'error') {
          turnstileStatus.value = 'ready'
          turnstileError.value = ''
          error.value = ''
        }
      },
      'expired-callback': () => {
        turnstileToken.value = ''
        settleTokenRequest('')
      },
      'timeout-callback': () => {
        turnstileToken.value = ''
        settleTokenRequest('')
      },
      'error-callback': () => {
        failCaptcha('The security check failed. Reload the page and try again.')
      }
    })
    turnstileStatus.value = 'ready'
  } catch {
    failCaptcha('The security check could not be loaded. Reload the page to try again; demo entry and sign-in stay unavailable until it loads.')
  }
}

function failCaptcha(message) {
  turnstileToken.value = ''
  settleTokenRequest('')
  turnstileStatus.value = 'error'
  turnstileError.value = message
}

function resetCaptcha() {
  turnstileToken.value = ''
  if (widgetId === null || !widgetApi?.reset) return
  try {
    widgetApi.reset(widgetId)
  } catch {
    // A widget that cannot be reset will simply have no usable token.
  }
}

function settleTokenRequest(token) {
  const pending = pendingTokenRequest
  if (!pending) return
  pendingTokenRequest = null
  clearTimeout(pending.timer)
  pending.resolve(token || '')
}

// Token acquisition is deliberately key-type agnostic: Cloudflare decides
// whether a site key renders visible, non-interactive, or execute-on-demand.
// Some widgets deliver a token through the render callback on their own, while
// others stay silent until execute() runs. Driving execute() when a widget
// exists is safe in both cases (a visible widget that is mid-challenge treats
// it as a no-op) and the submitted token always comes from the render callback.
function requestToken(timeoutMs = 15000) {
  if (!turnstile.value.enabled) return Promise.resolve('')
  if (turnstileToken.value) return Promise.resolve(turnstileToken.value)
  if (captchaBlocked.value) return Promise.resolve('')
  if (pendingTokenRequest) return pendingTokenRequest.promise

  // Without an execute-capable widget there is no way to obtain a token, so
  // fail closed immediately rather than waiting for a callback that cannot come.
  if (widgetId === null || typeof widgetApi?.execute !== 'function') return Promise.resolve('')

  let resolveRequest
  const promise = new Promise((resolve) => { resolveRequest = resolve })
  const timer = setTimeout(() => {
    settleTokenRequest('')
    // Let the widget start a fresh challenge on the next attempt.
    resetCaptcha()
  }, timeoutMs)
  pendingTokenRequest = { promise, resolve: resolveRequest, timer }
  try {
    widgetApi.execute(widgetId)
  } catch {
    // A visible widget may already be running its challenge; the render
    // callback still delivers the token when the visitor completes it.
  }
  return promise
}

// Returns the token to send, or null when the visitor must retry. A null
// result always leaves the request unsent so a gated API call never fires
// without a token.
async function acquireToken() {
  if (!turnstile.value.enabled) return ''
  if (captchaBlocked.value) {
    error.value = turnstileError.value || 'The security check is unavailable. Reload the page to try again.'
    return null
  }
  const token = await requestToken()
  if (token) return token
  if (captchaBlocked.value) {
    error.value = turnstileError.value || 'The security check is unavailable. Reload the page to try again.'
  } else {
    error.value = 'Please complete the security check before continuing.'
  }
  return null
}

function destination() {
  const next = route.query.next
  return typeof next === 'string' && next ? next : { name: 'labs' }
}

async function enterDemo() {
  if (busy.value) return
  error.value = ''
  busy.value = true
  busyAction.value = 'guest'
  try {
    const token = await acquireToken()
    if (token === null) return
    await guestLogin(token)
    resetCaptcha()
    notify('success', 'Entered the demo')
    await router.push(destination())
  } catch (exception) {
    resetCaptcha()
    error.value = exception.message
  } finally {
    busy.value = false
    busyAction.value = ''
  }
}

async function submit() {
  if (busy.value) return
  error.value = ''
  busy.value = true
  busyAction.value = 'login'
  try {
    const token = await acquireToken()
    if (token === null) return
    await login(username.value, password.value, token)
    resetCaptcha()
    notify('success', 'Signed in')
    await router.push(destination())
  } catch (exception) {
    resetCaptcha()
    error.value = exception.message
  } finally {
    busy.value = false
    busyAction.value = ''
  }
}
</script>

<style scoped>
.auth-card {
  width: min(460px, calc(100vw - 32px));
}

.auth-panel {
  border: 1px solid transparent;
  border-radius: 8px;
  padding: 14px;
  transition: border-color 140ms ease, background 140ms ease, box-shadow 140ms ease;
}

.auth-demo {
  display: grid;
  gap: 10px;
  border-color: var(--line);
  background: rgba(61, 243, 255, 0.025);
}

.auth-demo h2 {
  color: var(--muted-2);
}

.auth-demo .hint {
  margin: 0;
}

.auth-demo.is-primary {
  border-color: rgba(255, 62, 200, 0.5);
  background: rgba(255, 62, 200, 0.05);
  box-shadow: 0 0 24px rgba(255, 62, 200, 0.08) inset;
}

.auth-demo.is-primary h2 {
  color: var(--magenta);
}

.auth-form {
  padding-top: 4px;
}

.auth-form.is-primary {
  border-color: rgba(61, 243, 255, 0.18);
  background: rgba(61, 243, 255, 0.02);
}

.auth-form h2 {
  margin-bottom: 2px;
}

.demo-enter {
  width: 100%;
  padding: 12px;
  font-size: 12px;
}

.auth-demo.is-primary .demo-enter {
  background: linear-gradient(180deg, rgba(255, 62, 200, 0.2), rgba(255, 62, 200, 0.08));
  border-color: rgba(255, 62, 200, 0.55);
  color: #ffb5ec;
}

.auth-demo.is-primary .demo-enter:hover:not(:disabled) {
  border-color: var(--magenta);
  box-shadow: var(--glow-magenta);
  color: #ffd9f6;
}

.auth-divider {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 16px 0;
  color: var(--muted-2);
  font: 600 10px/1 var(--mono);
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.auth-divider::before,
.auth-divider::after {
  content: "";
  flex: 1;
  border-top: 1px solid var(--line);
}

.auth-captcha {
  display: grid;
  gap: 8px;
  justify-items: center;
  margin-top: 16px;
}

.auth-captcha .cf-turnstile {
  min-height: 65px;
}

.auth-captcha .hint,
.auth-captcha .error {
  margin: 0;
  text-align: center;
}

.auth-error {
  margin: 0 0 16px;
  padding: 10px 12px;
  border: 1px solid rgba(255, 93, 122, 0.45);
  border-radius: 6px;
  background: rgba(255, 93, 122, 0.08);
}
</style>
