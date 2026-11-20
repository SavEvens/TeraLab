<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="terminal-wrap">
    <div class="console-actions">
      <button class="ghost" @click="manualReconnect">Reconnect</button>
      <button class="ghost" @click="copySelection">Copy</button>
      <button class="ghost" @click="pasteClipboard">Paste</button>
      <span class="console-status" :class="statusKind">{{ statusLabel }}</span>
    </div>
    <div ref="host" class="terminal" aria-label="Telnet console"></div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { wsUrl } from '../api'
import { notify } from '../stores/toasts'

const props = defineProps({ wsPath: { type: String, required: true } })
const host = ref(null)
const status = ref('connecting')
let terminal
let fit
let socket
let observer
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

function connect() {
  if (disposed) return
  clearTimeout(reconnectTimer)
  socket?.close()
  status.value = 'connecting'
  socket = new WebSocket(wsUrl(props.wsPath))
  socket.binaryType = 'arraybuffer'
  socket.onopen = () => {
    attempts = 0
    status.value = 'connected'
    terminal.writeln('\x1b[32m[connected]\x1b[0m')
    fit.fit()
  }
  socket.onmessage = (event) => {
    terminal.write(typeof event.data === 'string' ? event.data : new Uint8Array(event.data))
  }
  socket.onclose = () => {
    if (disposed) return
    status.value = 'disconnected'
    terminal.writeln('\r\n\x1b[33m[disconnected]\x1b[0m')
    scheduleReconnect()
  }
  socket.onerror = () => {
    status.value = 'error'
    notify('error', 'Console connection failed')
  }
}

function manualReconnect() {
  attempts = 0
  connect()
}

async function copySelection() {
  const text = terminal?.getSelection() || ''
  if (!text) {
    notify('info', 'Select console text first')
    return
  }
  try {
    await navigator.clipboard.writeText(text)
    notify('success', 'Copied')
  } catch {
    notify('error', 'Clipboard unavailable')
  }
}

async function pasteClipboard() {
  try {
    const text = await navigator.clipboard.readText()
    if (text && socket?.readyState === WebSocket.OPEN) terminal.paste(text)
  } catch {
    notify('error', 'Clipboard unavailable')
  }
}

onMounted(() => {
  terminal = new Terminal({
    cursorBlink: true,
    fontFamily: 'JetBrains Mono, ui-monospace, SFMono-Regular, Menlo, monospace',
    fontSize: 13,
    theme: { background: '#050810', foreground: '#d7e0ff', cursor: '#3df3ff', selectionBackground: 'rgba(61,243,255,.25)' }
  })
  fit = new FitAddon()
  terminal.loadAddon(fit)
  terminal.open(host.value)
  fit.fit()
  terminal.attachCustomKeyEventHandler((event) => {
    if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'c') {
      copySelection()
      return false
    }
    if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'v') {
      pasteClipboard()
      return false
    }
    return true
  })
  terminal.onData((data) => {
    if (socket?.readyState === WebSocket.OPEN) socket.send(data)
  })
  observer = new ResizeObserver(() => fit?.fit())
  observer.observe(host.value)
  connect()
})

onBeforeUnmount(() => {
  disposed = true
  clearTimeout(reconnectTimer)
  observer?.disconnect()
  socket?.close()
  terminal?.dispose()
})
</script>
