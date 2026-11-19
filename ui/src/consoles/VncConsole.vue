<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="vnc-wrap">
    <div class="console-actions">
      <button class="ghost" @click="manualReconnect">Reconnect</button>
      <button class="ghost" @click="pasteClipboard">Clipboard</button>
      <button class="ghost" @click="fullscreen">Fullscreen</button>
      <span class="console-status" :class="statusKind">{{ statusLabel }}</span>
    </div>
    <div ref="host" class="vnc" aria-label="VNC console"></div>
    <p v-if="error" class="error">{{ error }}</p>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { wsUrl } from '../api'
import { notify } from '../stores/toasts'

const props = defineProps({
  wsPath: { type: String, required: true },
  password: { type: String, default: '' }
})
const host = ref(null)
const error = ref('')
const status = ref('connecting')
let rfb
let disposed = false
let attempts = 0
let reconnectTimer = null

const statusLabel = computed(() => status.value)
const statusKind = computed(() => (status.value === 'connected' ? 'ok' : status.value.startsWith('reconnecting') ? 'warn' : 'err'))

function scheduleReconnect() {
  if (disposed) return
  const delay = Math.min(15000, 1000 * 2 ** attempts)
  attempts += 1
  status.value = `reconnecting in ${Math.max(1, Math.round(delay / 1000))}s`
  clearTimeout(reconnectTimer)
  reconnectTimer = window.setTimeout(connect, delay)
}

async function connect() {
  if (disposed) return
  clearTimeout(reconnectTimer)
  rfb?.disconnect()
  error.value = ''
  status.value = 'connecting'
  try {
    const { default: RFB } = await import('@novnc/novnc')
    rfb = new RFB(host.value, wsUrl(props.wsPath), {
      credentials: { password: props.password }
    })
    rfb.scaleViewport = true
    rfb.background = '#050810'
    rfb.addEventListener('connect', () => {
      attempts = 0
      status.value = 'connected'
      notify('info', 'VNC connected')
    })
    rfb.addEventListener('disconnect', (event) => {
      if (disposed) return
      status.value = 'disconnected'
      if (!event.detail.clean) error.value = 'VNC disconnected'
      scheduleReconnect()
    })
    rfb.addEventListener('credentialsrequired', () => rfb.sendCredentials({ password: props.password }))
  } catch (exception) {
    error.value = exception.message
    status.value = 'error'
    notify('error', 'VNC failed to start')
    scheduleReconnect()
  }
}

function manualReconnect() {
  attempts = 0
  connect()
}

async function pasteClipboard() {
  try {
    const text = await navigator.clipboard.readText()
    if (text) rfb?.clipboardPasteFrom(text)
  } catch {
    notify('error', 'Clipboard unavailable')
  }
}

function fullscreen() {
  host.value?.requestFullscreen?.().catch(() => notify('error', 'Fullscreen unavailable'))
}

onMounted(connect)
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(reconnectTimer)
  rfb?.disconnect()
})
</script>
