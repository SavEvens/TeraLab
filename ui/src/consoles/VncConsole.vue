<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="vnc-wrap">
    <div ref="host" class="vnc" aria-label="VNC console"></div>
    <p v-if="error" class="error">{{ error }}</p>
    <button class="ghost reconnect" @click="connect">Reconnect</button>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { wsUrl } from '../api'
import { notify } from '../stores/toasts'

const props = defineProps({
  wsPath: { type: String, required: true },
  password: { type: String, default: '' }
})
const host = ref(null)
const error = ref('')
let rfb
let disposed = false

async function connect() {
  if (disposed) return
  rfb?.disconnect()
  error.value = ''
  try {
    const { default: RFB } = await import('@novnc/novnc')
    rfb = new RFB(host.value, wsUrl(props.wsPath), {
      credentials: { password: props.password }
    })
    rfb.scaleViewport = true
    rfb.background = '#0b1020'
    rfb.addEventListener('connect', () => notify('info', 'VNC connected'))
    rfb.addEventListener('disconnect', (event) => {
      if (!event.detail.clean) error.value = 'VNC disconnected'
    })
    rfb.addEventListener('credentialsrequired', () => rfb.sendCredentials({ password: props.password }))
  } catch (exception) {
    error.value = exception.message
    notify('error', 'VNC failed to start')
  }
}

onMounted(connect)
onBeforeUnmount(() => {
  disposed = true
  rfb?.disconnect()
})
</script>
