<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="editor" :class="{ presentation, 'has-console': consoleOpen }">
    <div class="canvas" @dragover.prevent @drop="dropTemplate">
      <VueFlow
        v-show="!showTable"
        v-model:nodes="nodes"
        v-model:edges="edges"
        :node-types="nodeTypes"
        :min-zoom="0.2"
        :max-zoom="2.5"
        :snap-to-grid="snap"
        :snap-grid="[20, 20]"
        :selection-on-drag="true"
        :pan-on-drag="[1, 2]"
        fit-view-on-init
        @connect="connect"
        @node-click="selectNode"
        @pane-click="selected = null"
        @edges-change="edgesChanged"
        @move-end="saveViewport"
        @viewport-change="() => {}"
      >
        <Background v-if="showGrid" pattern-color="#22346a" :gap="26" />
        <Controls position="bottom-left" />
        <MiniMap position="bottom-right" :pannable="true" :zoomable="true" />
      </VueFlow>

      <div v-if="showTable" class="node-table">
        <table>
          <thead><tr><th>Name</th><th>Type</th><th>Image</th><th>Ports</th></tr></thead>
          <tbody>
            <tr v-for="node in nodes" :key="node.id" @click="selected = node">
              <td>{{ node.data.name }}</td><td>{{ node.data.kind }}</td>
              <td>{{ node.data.image || '—' }}</td><td>{{ node.data.interfaces.length }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="error" class="error banner" role="alert">{{ error }}</div>

      <template v-if="!presentation">
        <div class="ed-topbar">
          <router-link to="/labs" class="ed-back">← Labs</router-link>
          <div class="ed-title">
            <input v-model="labName" class="lab-name" aria-label="Lab name" @input="dirty = true" />
            <span class="badge">rev {{ revision }}</span>
            <span :class="['save-state', { dirty }]">{{ saveLabel }}</span>
          </div>
          <div class="spacer"></div>
          <div class="ed-actions">
            <button class="ghost icon" title="Toggle devices palette" aria-label="Toggle devices palette" @click="paletteOpen = !paletteOpen">▤</button>
            <button class="ghost icon" title="Toggle inspector" aria-label="Toggle inspector" @click="inspectorOpen = !inspectorOpen">▥</button>
            <button class="ghost" :disabled="!canUndo" title="Undo (Ctrl+Z)" @click="undo">↶</button>
            <button class="ghost" :disabled="!canRedo" title="Redo (Ctrl+Y)" @click="redo">↷</button>
            <button @click="save()">Save</button>
            <button class="ghost" @click="run('start')">▶ Start</button>
            <button class="ghost" @click="run('stop')">■ Stop</button>
            <button class="danger" @click="run('wipe')">Wipe</button>
          </div>
        </div>

        <Palette v-show="paletteOpen" class="floating-palette" :templates="templates" @add="addTemplate" />
        <Inspector
          v-show="inspectorOpen"
          class="floating-inspector"
          :node="selected"
          :templates="templates"
          :images="images"
          @update="updateNode"
          @delete="deleteNode"
          @console="openNodeConsole"
          @config="downloadConfig"
          @ports="updatePorts"
          @template="applyTemplate"
        />

        <div class="ed-bottom" role="toolbar" aria-label="Topology tools">
          <button class="ghost" @click="duplicateSelected">Duplicate</button>
          <button class="ghost" @click="align('x')">Align ⇤</button>
          <button class="ghost" @click="align('y')">Align ⇡</button>
          <button class="ghost" @click="fit">Fit</button>
          <button class="ghost" :class="{ active: snap }" @click="snap = !snap">Snap</button>
          <button class="ghost" :class="{ active: showGrid }" @click="showGrid = !showGrid">Grid</button>
          <button class="ghost" @click="showTable = !showTable">{{ showTable ? 'Canvas' : 'Table' }}</button>
          <button class="ghost" @click="validate">Validate</button>
          <button class="ghost" @click="presentation = true">Presentation</button>
        </div>

        <div class="ed-status">
          <span><strong>{{ nodes.length }}</strong> nodes</span>
          <span><strong>{{ networks.length }}</strong> links</span>
          <span>zoom <strong>{{ zoom }}%</strong></span>
          <span v-if="selected">selected <strong>{{ selected.data.name }}</strong></span>
          <span class="spacer"></span>
          <span v-if="conflict" class="err">conflict</span>
          <span v-else-if="problems.length" class="warn">validation issues</span>
          <span v-else-if="dirty" class="warn">unsaved</span>
          <span v-else class="ok">saved</span>
        </div>
      </template>

      <button v-else class="presentation-exit" @click="presentation = false">Exit presentation</button>

      <div v-if="problems.length" class="validation-panel" role="status">
        <strong>Validation</strong>
        <ul><li v-for="problem in problems" :key="problem">{{ problem }}</li></ul>
      </div>
    </div>

    <div v-if="conflict" class="modal-backdrop">
      <div class="modal" role="dialog" aria-modal="true">
        <h2>Save conflict</h2>
        <p>The lab was changed elsewhere (remote revision {{ conflict.revision }}).</p>
        <div class="row">
          <button @click="reload">Reload remote</button>
          <button class="danger" @click="overwrite">Overwrite with mine</button>
          <button class="ghost" @click="cancelConflict">Cancel</button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import Palette from '../components/Palette.vue'
import Inspector from '../components/Inspector.vue'
import LabNode from '../components/LabNode.vue'
import TextNode from '../components/TextNode.vue'
import PictureNode from '../components/PictureNode.vue'
import { api } from '../api'
import { notify, progress, dismiss } from '../stores/toasts'
import { openConsole, consoleState } from '../stores/consoles'
import { session } from '../stores/session'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()
const { screenToFlowCoordinate, fitView, getSelectedNodes, getViewport, setViewport } = useVueFlow()

const nodeTypes = { lab: LabNode, text: TextNode, picture: PictureNode }
const nodes = ref([])
const edges = ref([])
const networks = ref([])
const templates = ref([])
const images = ref([])
const selected = ref(null)
const revision = ref(1)
const labName = ref('')
const dirty = ref(false)
const saving = ref(false)
const savedAt = ref(null)
const error = ref('')
const conflict = ref(null)
const autosavePaused = ref(false)
const presentation = ref(false)
const paletteOpen = ref(true)
const inspectorOpen = ref(true)
const loading = ref(true)
const history = ref([])
const future = ref([])
const edgeNetworks = new Map()
const snap = ref(true)
const showGrid = ref(true)
const showTable = ref(false)
const clipboard = ref(null)
const problems = ref([])
const zoom = computed(() => Math.round(((getViewport.value?.zoom ?? 1)) * 100))
const consoleOpen = computed(() => consoleState.tabs.length > 0)
const canUndo = computed(() => history.value.length > 0)
const canRedo = computed(() => future.value.length > 0)
const saveLabel = computed(() => {
  if (saving.value) return 'Saving…'
  if (dirty.value) return 'Unsaved changes'
  return savedAt.value ? `Saved ${savedAt.value.toLocaleTimeString()}` : 'Saved'
})
let autosaveTimer = null
let suppressWatch = true

function snapshot() {
  return JSON.parse(JSON.stringify({ nodes: nodes.value, networks: networks.value, name: labName.value }))
}

function record() {
  history.value.push(snapshot())
  if (history.value.length > 50) history.value.shift()
  future.value = []
}

function restore(state) {
  nodes.value = JSON.parse(JSON.stringify(state.nodes))
  networks.value = JSON.parse(JSON.stringify(state.networks))
  labName.value = state.name
  rebuildEdges()
  dirty.value = true
}

function undo() {
  if (!history.value.length) return
  future.value.push(snapshot())
  restore(history.value.pop())
}

function redo() {
  if (!future.value.length) return
  history.value.push(snapshot())
  restore(future.value.pop())
}

function nextNetworkId() {
  let max = -1
  for (const network of networks.value) {
    const numeric = Number(network.id)
    if (Number.isInteger(numeric)) max = Math.max(max, numeric)
  }
  return String(max + 1)
}

function buildNode(raw, index) {
  const interfaces = Array.isArray(raw.interfaces) ? raw.interfaces : []
  return {
    id: String(raw.id),
    type: 'lab',
    position: { x: raw.left || 80 + (index % 4) * 220, y: raw.top || 80 + Math.floor(index / 4) * 140 },
    data: {
      id: String(raw.id),
      name: raw.name || raw.id,
      kind: raw.type || 'qemu',
      template: raw.template || '',
      icon: raw.icon || '',
      image: raw.image || '',
      iso: raw.iso || '',
      console: raw.console || 'telnet',
      cpu: raw.cpu || 1,
      ram: raw.ram || 512,
      uuid: raw.uuid || '',
      platform: raw.platform || '',
      nic: raw.nic || '',
      serial: raw.serial || 0,
      config: raw.config || '',
      interfaces: interfaces.map((iface, port) => ({
        id: Number(iface.id ?? port),
        name: iface.name || `eth${port}`,
        type: iface.type || 'ethernet',
        network_id: String(iface.network_id || '')
      }))
    }
  }
}

function rebuildEdges() {
  const byNetwork = new Map()
  for (const node of nodes.value) {
    node.data.interfaces.forEach((iface, index) => {
      if (!iface.network_id) return
      if (!byNetwork.has(iface.network_id)) byNetwork.set(iface.network_id, [])
      byNetwork.get(iface.network_id).push({ node, index })
    })
  }
  const next = []
  edgeNetworks.clear()
  for (const [networkId, ports] of byNetwork.entries()) {
    if (ports.length < 2) continue
    for (let index = 0; index < ports.length - 1; index += 1) {
      const from = ports[index]
      const to = ports[index + 1]
      const id = `e-${networkId}-${from.node.id}-${from.index}-${to.node.id}-${to.index}`
      next.push({
        id,
        source: from.node.id,
        target: to.node.id,
        sourceHandle: `s-${from.index}`,
        targetHandle: `t-${to.index}`,
        data: { networkId },
        animated: false,
        style: { stroke: '#5b8cff', strokeWidth: 2 }
      })
      edgeNetworks.set(id, networkId)
    }
  }
  edges.value = next
}

async function load() {
  loading.value = true
  suppressWatch = true
  try {
    const data = (await api.lab(props.id)).data
    labName.value = data.name
    revision.value = data.revision || 1
    networks.value = (data.network_list || []).map((network) => ({ ...network }))
    nodes.value = (data.node_list || []).map(buildNode)
    rebuildEdges()
    templates.value = (await api.templates()).data
    images.value = (await api.images()).data
    savedAt.value = new Date()
    dirty.value = false
  } catch (exception) {
    error.value = exception.message
  } finally {
    loading.value = false
    await nextTick()
    restoreViewport()
    suppressWatch = false
  }
}

function addTemplate(template, position = null) {
  record()
  const id = String(nodes.value.reduce((max, node) => Math.max(max, Number(node.id) || 0), 0) + 1)
  if (template.kind === 'text' || template.kind === 'picture') {
    nodes.value.push({
      id,
      type: template.kind,
      position: position || { x: 140 + (nodes.value.length % 5) * 210, y: 120 + Math.floor(nodes.value.length / 5) * 130 },
      data: {
        id,
        name: template.kind === 'text' ? 'Text' : 'Picture',
        kind: template.kind,
        image: '',
        console: 'telnet',
        cpu: 1,
        ram: 64,
        uuid: '',
        platform: '',
        interfaces: []
      }
    })
    dirty.value = true
    return
  }
  const defaults = template.defaults || {}
  const ethernet = Math.min(16, Math.max(0, defaults.ethernet ?? (template.kind === 'vpcs' ? 1 : 4)))
  const interfaces = Array.from({ length: ethernet }, (_, index) => ({
    id: index,
    name: `eth${index}`,
    type: 'ethernet',
    network_id: ''
  }))
  const node = {
    id,
    type: 'lab',
    position: position || { x: 120 + (nodes.value.length % 5) * 210, y: 120 + Math.floor(nodes.value.length / 5) * 130 },
    data: {
      id,
      name: `${template.name || template.id}${id}`,
      kind: template.kind || 'qemu',
      template: template.id,
      icon: template.icon || '',
      image: '',
      iso: '',
      console: defaults.console || 'telnet',
      cpu: defaults.cpu || 1,
      ram: defaults.ram || 512,
      uuid: '',
      platform: '',
      nic: defaults.nic || '',
      serial: defaults.serial || 0,
      config: '',
      interfaces
    }
  }
  nodes.value.push(node)
  dirty.value = true
}

function dropTemplate(event) {
  const id = event.dataTransfer.getData('application/x-teralab-template')
  const template = templates.value.find((item) => item.id === id)
  if (!template) return
  const position = screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
  addTemplate(template, position)
}

function connect(params) {
  const source = nodes.value.find((node) => node.id === params.source)
  const target = nodes.value.find((node) => node.id === params.target)
  if (!source || !target) return
  const sourceIndex = Number(String(params.sourceHandle || '').replace('s-', ''))
  const targetIndex = Number(String(params.targetHandle || '').replace('t-', ''))
  const sourcePort = source.data.interfaces[sourceIndex]
  const targetPort = target.data.interfaces[targetIndex]
  if (!sourcePort || !targetPort) return
  record()
  let networkId = targetPort.network_id || sourcePort.network_id
  if (!networkId) {
    networkId = nextNetworkId()
    networks.value.push({ id: networkId, name: `Net-${networkId}`, type: 'bridge' })
  }
  sourcePort.network_id = networkId
  targetPort.network_id = networkId
  rebuildEdges()
  dirty.value = true
}

function edgesChanged(changes) {
  const removed = changes.filter((change) => change.type === 'remove')
  if (!removed.length) return
  record()
  for (const change of removed) {
    const networkId = edgeNetworks.get(change.id)
    if (!networkId) continue
    for (const node of nodes.value) {
      for (const iface of node.data.interfaces) {
        if (iface.network_id === networkId) iface.network_id = ''
      }
    }
  }
  cleanupNetworks()
  rebuildEdges()
  dirty.value = true
}

function cleanupNetworks() {
  const used = new Set()
  for (const node of nodes.value) {
    for (const iface of node.data.interfaces) {
      if (iface.network_id) used.add(iface.network_id)
    }
  }
  networks.value = networks.value.filter((network) => used.has(String(network.id)))
}

function selectNode(event) {
  selected.value = event.node
}

function updateNode({ key, value }) {
  if (!selected.value) return
  selected.value.data[key] = value
  dirty.value = true
}

function applyTemplate(templateId) {
  if (!selected.value) return
  const template = templates.value.find((item) => item.id === templateId)
  if (!template) return
  record()
  const defaults = template.defaults || {}
  selected.value.data.template = template.id
  selected.value.data.icon = template.icon || selected.value.data.icon
  if (template.kind) selected.value.data.kind = template.kind
  if (defaults.cpu) selected.value.data.cpu = defaults.cpu
  if (defaults.ram) selected.value.data.ram = defaults.ram
  if (defaults.console) selected.value.data.console = defaults.console
  if (defaults.nic) selected.value.data.nic = defaults.nic
  if (defaults.serial) selected.value.data.serial = defaults.serial
  if (Number.isInteger(defaults.ethernet)) updatePorts(defaults.ethernet)
  dirty.value = true
}

function updatePorts(count) {
  if (!selected.value) return
  record()
  const interfaces = selected.value.data.interfaces
  while (interfaces.length > count) {
    const removed = interfaces.pop()
    if (removed?.network_id) {
      for (const node of nodes.value) {
        for (const iface of node.data.interfaces) {
          if (iface !== removed && iface.network_id === removed.network_id) iface.network_id = ''
        }
      }
    }
  }
  while (interfaces.length < count) {
    interfaces.push({ id: interfaces.length, name: `eth${interfaces.length}`, type: 'ethernet', network_id: '' })
  }
  cleanupNetworks()
  rebuildEdges()
  dirty.value = true
}

function deleteNode(node) {
  record()
  nodes.value = nodes.value.filter((item) => item.id !== node.id)
  if (selected.value?.id === node.id) selected.value = null
  cleanupNetworks()
  rebuildEdges()
  dirty.value = true
}

function downloadConfig(node) {
  const blob = new Blob([node.data.config || ''], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `${node.data.name || node.id}.cfg`
  anchor.click()
  URL.revokeObjectURL(url)
}

async function openNodeConsole(node) {
  try {
    await openConsole(props.id, { id: node.data.id, name: node.data.name })
  } catch (exception) {
    notify('error', exception.message)
  }
}

function payload() {
  return {
    name: labName.value,
    revision: revision.value,
    nodes: nodes.value.map((node) => ({
      id: node.data.id,
      name: node.data.name,
      type: node.data.kind,
      template: node.data.template,
      icon: node.data.icon,
      image: node.data.image,
      iso: node.data.iso,
      console: node.data.console,
      cpu: node.data.cpu,
      ram: node.data.ram,
      uuid: node.data.uuid,
      platform: node.data.platform,
      nic: node.data.nic || '',
      serial: node.data.serial || 0,
      config: node.data.config || '',
      left: Math.round(node.position.x),
      top: Math.round(node.position.y),
      interfaces: node.data.interfaces.map((iface) => ({
        id: iface.id,
        name: iface.name,
        type: iface.type,
        network_id: iface.network_id
      }))
    })),
    networks: networks.value.map((network) => ({ id: network.id, name: network.name, type: network.type || 'bridge' }))
  }
}

async function save({ silent = false } = {}) {
  if (saving.value) return
  saving.value = true
  error.value = ''
  try {
    const data = (await api.saveLab(props.id, payload())).data
    revision.value = data.revision
    savedAt.value = new Date()
    dirty.value = false
    autosavePaused.value = false
    if (!silent) notify('success', 'Lab saved')
  } catch (exception) {
    if (exception.status === 409) {
      autosavePaused.value = true
      conflict.value = { revision: exception.details?.revision ?? '?' }
    } else {
      error.value = exception.message
      notify('error', exception.message)
      throw exception
    }
  } finally {
    saving.value = false
  }
}

async function reload() {
  conflict.value = null
  autosavePaused.value = false
  await load()
}

function cancelConflict() {
  conflict.value = null
  autosavePaused.value = true
}

async function overwrite() {
  conflict.value = null
  try {
    const remote = (await api.lab(props.id)).data
    revision.value = remote.revision
    await save()
  } catch (exception) {
    error.value = exception.message
  }
}

async function run(action) {
  try {
    await save({ silent: true })
    const result = await api[`${action}Lab`](props.id)
    if (result.job_id) {
      const id = notify('info', `${action} started`, 0)
      const poll = window.setInterval(async () => {
        try {
          const job = (await api.job(result.job_id)).data
          progress(id, job.progress)
          if (job.state !== 'running' && job.state !== 'queued') {
            window.clearInterval(poll)
            dismiss(id)
            notify(job.state === 'succeeded' ? 'success' : 'error', `${action} ${job.state}`)
          }
        } catch {
          window.clearInterval(poll)
          dismiss(id)
        }
      }, 500)
    } else {
      notify('success', `${action} requested`)
    }
  } catch (exception) {
    notify('error', exception.message)
  }
}

function validate() {
  problems.value = []
  const names = new Map()
  for (const node of nodes.value) {
    const name = node.data.name?.trim()
    if (!name) problems.value.push('A node has no name')
    else if (names.has(name)) problems.value.push(`Duplicate node name: ${name}`)
    names.set(name, true)
    if (['qemu', 'iol', 'dynamips'].includes(node.data.kind) && !node.data.image && !node.data.iso) {
      problems.value.push(`${name || node.data.id} has no image`)
    }
    if (node.data.interfaces.length > 16) problems.value.push(`${name || node.data.id} has too many ports`)
  }
  if (problems.value.length) notify('error', problems.value.slice(0, 3).join('; '), 8000)
  else notify('success', 'Topology looks valid')
}

function fit() {
  fitView({ padding: 0.15 })
}

function duplicateSelected() {
  const selectedNodes = getSelectedNodes.value.length ? getSelectedNodes.value : (selected.value ? [selected.value] : [])
  if (!selectedNodes.length) return
  record()
  let next = nodes.value.reduce((max, node) => Math.max(max, Number(node.id) || 0), 0)
  const copies = selectedNodes.map((node) => {
    next += 1
    const copy = JSON.parse(JSON.stringify(node))
    copy.id = String(next)
    copy.data.id = String(next)
    copy.data.name = `${node.data.name}-copy`
    copy.data.interfaces = copy.data.interfaces.map((iface) => ({ ...iface, network_id: '' }))
    copy.position = { x: node.position.x + 40, y: node.position.y + 40 }
    return copy
  })
  nodes.value.push(...copies)
  dirty.value = true
}

function align(axis) {
  const selectedNodes = getSelectedNodes.value
  if (selectedNodes.length < 2) return
  record()
  const anchor = selectedNodes[0].position[axis]
  for (const node of selectedNodes) node.position[axis] = anchor
  dirty.value = true
}

function copySelection() {
  const selectedNodes = getSelectedNodes.value.length ? getSelectedNodes.value : (selected.value ? [selected.value] : [])
  if (!selectedNodes.length) return
  clipboard.value = JSON.parse(JSON.stringify(selectedNodes))
  notify('info', `Copied ${selectedNodes.length} node(s)`)
}

function pasteSelection() {
  if (!clipboard.value?.length) return
  record()
  let next = nodes.value.reduce((max, node) => Math.max(max, Number(node.id) || 0), 0)
  for (const node of clipboard.value) {
    next += 1
    const copy = JSON.parse(JSON.stringify(node))
    copy.id = String(next)
    copy.data.id = String(next)
    copy.data.name = `${node.data.name}-paste`
    copy.data.interfaces = copy.data.interfaces.map((iface) => ({ ...iface, network_id: '' }))
    copy.position = { x: node.position.x + 60, y: node.position.y + 60 }
    nodes.value.push(copy)
  }
  dirty.value = true
}

function saveViewport() {
  try {
    localStorage.setItem(`teralab.view.${session.user?.id || 'anon'}.${props.id}`, JSON.stringify(getViewport.value))
  } catch {}
}

function restoreViewport() {
  try {
    const raw = localStorage.getItem(`teralab.view.${session.user?.id || 'anon'}.${props.id}`)
    if (raw) setViewport(JSON.parse(raw), { duration: 0 })
  } catch {}
}

function keydown(event) {
  const target = event.target
  if (target instanceof HTMLElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return
  if (event.key === 'Escape' && presentation.value) {
    presentation.value = false
    return
  }
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    save()
  } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
    event.preventDefault()
    event.shiftKey ? redo() : undo()
  } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
    event.preventDefault()
    redo()
  } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'c') {
    event.preventDefault()
    copySelection()
  } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v') {
    event.preventDefault()
    pasteSelection()
  } else if ((event.key === 'Delete' || event.key === 'Backspace') && selected.value) {
    event.preventDefault()
    deleteNode(selected.value)
  } else if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key) && selected.value) {
    event.preventDefault()
    const delta = event.shiftKey ? 20 : 5
    if (event.key === 'ArrowUp') selected.value.position.y -= delta
    if (event.key === 'ArrowDown') selected.value.position.y += delta
    if (event.key === 'ArrowLeft') selected.value.position.x -= delta
    if (event.key === 'ArrowRight') selected.value.position.x += delta
    dirty.value = true
  }
}

watch(
  [nodes, networks, labName],
  () => {
    if (suppressWatch) return
    dirty.value = true
    window.clearTimeout(autosaveTimer)
    autosaveTimer = window.setTimeout(() => {
      if (dirty.value && !conflict.value && !autosavePaused.value) save({ silent: true })
    }, 1000)
  },
  { deep: true }
)

onMounted(() => {
  load()
  window.addEventListener('keydown', keydown)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', keydown)
  window.clearTimeout(autosaveTimer)
})
</script>
