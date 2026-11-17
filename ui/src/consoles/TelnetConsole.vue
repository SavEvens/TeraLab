<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="terminal-wrap">
    <div ref="host" class="terminal" aria-label="Telnet console"></div>
    <button class="ghost reconnect" @click="connect">Reconnect</button>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { wsUrl } from '../api'
import { notify } from '../stores/toasts'

const props = defineProps({ wsPath: { type: String, required: true } })
const host = ref(null)
let terminal
let fit
let socket
let observer
let disposed = false

function connect() {
  if (disposed) return
  socket?.close()
  socket = new WebSocket(wsUrl(props.wsPath))
  socket.binaryType = 'arraybuffer'
  socket.onopen = () => {
    terminal.writeln('\x1b[32m[connected]\x1b[0m')
    fit.fit()
    socket.send(JSON.stringify({ type: 'resize', cols: terminal.cols, rows: terminal.rows }))
  }
  socket.onmessage = (event) => {
    terminal.write(typeof event.data === 'string' ? event.data : new Uint8Array(event.data))
  }
  socket.onclose = () => {
    if (!disposed) terminal.writeln('\r\n\x1b[33m[disconnected]\x1b[0m')
  }
  socket.onerror = () => notify('error', 'Console connection failed')
}

onMounted(() => {
  terminal = new Terminal({
    cursorBlink: true,
    fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
    fontSize: 13,
    theme: { background: '#0b1020', foreground: '#d7e0ff' }
  })
  fit = new FitAddon()
  terminal.loadAddon(fit)
  terminal.open(host.value)
  fit.fit()
  terminal.onData((data) => {
    if (socket?.readyState === WebSocket.OPEN) socket.send(data)
  })
  terminal.onResize(({ cols, rows }) => {
    if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: 'resize', cols, rows }))
  })
  observer = new ResizeObserver(() => fit?.fit())
  observer.observe(host.value)
  connect()
})

onBeforeUnmount(() => {
  disposed = true
  observer?.disconnect()
  socket?.close()
  terminal?.dispose()
})
</script>
