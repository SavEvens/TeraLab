<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section v-if="state.tabs.length" class="console-dock">
    <div
      class="console-resize"
      role="separator"
      aria-orientation="horizontal"
      aria-label="Resize console"
      tabindex="0"
      title="Drag to resize the console"
      @pointerdown="startResize"
      @keydown.up.prevent="resizeBy(20)"
      @keydown.down.prevent="resizeBy(-20)"
      @dblclick="resetHeight"
    ></div>
    <div class="tabs" role="tablist">
      <button
        v-for="tab in state.tabs"
        :key="tab.id"
        role="tab"
        :aria-selected="tab.id === state.active"
        :class="{ active: tab.id === state.active }"
        @click="activateConsole(tab.id)"
      >
        <span class="led" :class="tab.kind"></span>{{ tab.name }} <span class="kind">{{ tab.kind }}</span>
      </button>
      <button class="ghost" :aria-label="collapsed ? 'Show consoles' : 'Hide consoles'" @click="collapsed = !collapsed">
        {{ collapsed ? 'Show' : 'Hide' }}
      </button>
      <button class="ghost" aria-label="Close all consoles" @click="closeAll">Close all</button>
    </div>
    <div v-show="!collapsed" class="console-body">
      <template v-for="tab in state.tabs" :key="tab.id">
        <div v-show="tab.id === state.active" class="console-pane">
          <button class="ghost close" aria-label="Close console" @click="closeConsole(tab.id)">×</button>
          <TelnetConsole v-if="tab.kind !== 'vnc'" :ws-path="tab.wsPath" />
          <VncConsole v-else :ws-path="tab.wsPath" :password="tab.password" />
        </div>
      </template>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { consoleState, closeConsole, activateConsole } from '../stores/consoles'
import { session } from '../stores/session'
import TelnetConsole from '../consoles/TelnetConsole.vue'
import VncConsole from '../consoles/VncConsole.vue'

const DEFAULT_HEIGHT = 332
const MIN_HEIGHT = 140
const MAX_RATIO = 0.75
const state = consoleState
const collapsed = ref(false)
let dragging = false
let startY = 0
let startHeight = DEFAULT_HEIGHT

function clampHeight(value) {
  const max = Math.max(MIN_HEIGHT, Math.round(window.innerHeight * MAX_RATIO))
  return Math.min(max, Math.max(MIN_HEIGHT, Math.round(Number(value) || DEFAULT_HEIGHT)))
}

function currentHeight() {
  const raw = getComputedStyle(document.documentElement).getPropertyValue('--console-h')
  const parsed = Number.parseFloat(raw)
  return Number.isFinite(parsed) ? parsed : DEFAULT_HEIGHT
}

function applyHeight(value) {
  document.documentElement.style.setProperty('--console-h', `${clampHeight(value)}px`)
}

function storageKey() {
  const id = session.user?.id || session.user?.username || 'anon'
  return `teralab.console-h.${id}`
}

function persistHeight() {
  try {
    localStorage.setItem(storageKey(), String(Math.round(currentHeight())))
  } catch {}
}

function restoreHeight() {
  try {
    const parsed = Number.parseFloat(localStorage.getItem(storageKey()))
    applyHeight(Number.isFinite(parsed) ? parsed : DEFAULT_HEIGHT)
  } catch {
    applyHeight(DEFAULT_HEIGHT)
  }
}

function startResize(event) {
  if (event.pointerType === 'mouse' && event.button !== 0) return
  dragging = true
  startY = event.clientY
  startHeight = currentHeight()
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', endResize)
  window.addEventListener('pointercancel', endResize)
  event.preventDefault()
}

function onPointerMove(event) {
  if (!dragging) return
  applyHeight(startHeight + (startY - event.clientY))
}

function endResize() {
  if (!dragging) return
  dragging = false
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', endResize)
  window.removeEventListener('pointercancel', endResize)
  persistHeight()
}

function resizeBy(delta) {
  applyHeight(currentHeight() + delta)
  persistHeight()
}

function resetHeight() {
  applyHeight(DEFAULT_HEIGHT)
  persistHeight()
}

function onViewportResize() {
  applyHeight(currentHeight())
}

function closeAll() {
  for (const tab of [...state.tabs]) closeConsole(tab.id)
}

onMounted(() => {
  restoreHeight()
  window.addEventListener('resize', onViewportResize)
})
onBeforeUnmount(() => {
  endResize()
  window.removeEventListener('resize', onViewportResize)
})
watch(() => session.user?.id, (id) => {
  if (id) restoreHeight()
})
</script>
