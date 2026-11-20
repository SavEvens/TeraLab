<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal link-modal" role="dialog" aria-modal="true" aria-label="Add link">
      <div class="picker-head">
        <h2>Add link</h2>
        <button class="ghost" aria-label="Close" @click="$emit('close')">✕</button>
      </div>
      <p class="hint">Pick the interface on each node that this link connects to.</p>

      <div v-if="nodes.length < 2" class="empty">Add at least two engine nodes before creating a link.</div>

      <div v-else class="link-ends">
        <fieldset class="link-end">
          <legend>End A</legend>
          <label>Node
            <select v-model="sourceId" aria-label="Node A" @change="sourceChanged">
              <option v-for="node in nodes" :key="node.id" :value="node.id">{{ node.data.name }}</option>
            </select>
          </label>
          <label>Interface
            <select v-model.number="sourceIndex" aria-label="Interface A">
              <option v-for="(port, index) in sourcePorts" :key="port.id ?? index" :value="index">{{ optionLabel(sourceNode, index) }}</option>
            </select>
          </label>
        </fieldset>

        <button class="ghost swap" type="button" title="Swap ends" aria-label="Swap ends" @click="swap">⇄</button>

        <fieldset class="link-end">
          <legend>End B</legend>
          <label>Node
            <select v-model="targetId" aria-label="Node B" @change="targetChanged">
              <option v-for="node in nodes" :key="node.id" :value="node.id">{{ node.data.name }}</option>
            </select>
          </label>
          <label>Interface
            <select v-model.number="targetIndex" aria-label="Interface B">
              <option v-for="(port, index) in targetPorts" :key="port.id ?? index" :value="index">{{ optionLabel(targetNode, index) }}</option>
            </select>
          </label>
        </fieldset>
      </div>

      <p v-if="nodes.length >= 2" class="link-summary" :class="{ error: problem }">
        {{ problem || summary }}
      </p>

      <div class="row">
        <button :disabled="Boolean(problem)" @click="submit">Create link</button>
        <button class="ghost" @click="$emit('close')">Cancel</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { portLabel } from '../topology'

const props = defineProps({
  nodes: { type: Array, default: () => [] },
  networks: { type: Array, default: () => [] },
  initialSource: { type: String, default: '' },
  initialTarget: { type: String, default: '' }
})
const emit = defineEmits(['create', 'close'])

function freeIndex(node) {
  const interfaces = node?.data.interfaces || []
  const index = interfaces.findIndex((port) => !port.network_id)

  return index >= 0 ? index : 0
}

function nodeById(id) {
  return props.nodes.find((node) => node.id === id) || null
}

const initialSourceId = props.initialSource || props.nodes[0]?.id || ''
const initialTargetId = props.initialTarget || props.nodes.find((node) => node.id !== initialSourceId)?.id || ''
const sourceId = ref(initialSourceId)
const targetId = ref(initialTargetId)
const sourceIndex = ref(freeIndex(nodeById(initialSourceId)))
const targetIndex = ref(freeIndex(nodeById(initialTargetId)))

const sourceNode = computed(() => props.nodes.find((node) => node.id === sourceId.value) || null)
const targetNode = computed(() => props.nodes.find((node) => node.id === targetId.value) || null)
const sourcePorts = computed(() => sourceNode.value?.data.interfaces || [])
const targetPorts = computed(() => targetNode.value?.data.interfaces || [])
const sourcePort = computed(() => sourcePorts.value[sourceIndex.value] || null)
const targetPort = computed(() => targetPorts.value[targetIndex.value] || null)

function sourceChanged() {
  if (sourceId.value === targetId.value) {
    targetId.value = props.nodes.find((node) => node.id !== sourceId.value)?.id || ''
  }
  sourceIndex.value = freeIndex(sourceNode.value)
}

function targetChanged() {
  if (targetId.value === sourceId.value) {
    sourceId.value = props.nodes.find((node) => node.id !== targetId.value)?.id || ''
  }
  targetIndex.value = freeIndex(targetNode.value)
}

function networkName(networkId) {
  return props.networks.find((network) => String(network.id) === String(networkId))?.name || `Net-${networkId}`
}

function peers(networkId) {
  if (!networkId) return []
  const found = []
  for (const node of props.nodes) {
    node.data.interfaces.forEach((port, index) => {
      if (String(port.network_id) === String(networkId)) found.push({ node, index })
    })
  }

  return found
}

function optionLabel(node, index) {
  if (!node) return ''
  const port = node.data.interfaces[index]
  const label = portLabel(node, index)
  if (!port?.network_id) return `${label} · free`
  const others = peers(port.network_id).filter((peer) => !(peer.node.id === node.id && peer.index === index))

  return `${label} · ${networkName(port.network_id)} (${others.length})`
}

const problem = computed(() => {
  if (props.nodes.length < 2) return 'Add at least two engine nodes first.'
  if (!sourceNode.value || !targetNode.value) return 'Pick two nodes.'
  if (sourceNode.value.id === targetNode.value.id) return 'Pick two different nodes.'
  if (!sourcePort.value) return `${sourceNode.value.data.name} has no interfaces.`
  if (!targetPort.value) return `${targetNode.value.data.name} has no interfaces.`
  if (
    sourcePort.value.network_id
    && targetPort.value.network_id
    && String(sourcePort.value.network_id) === String(targetPort.value.network_id)
  ) {
    return 'Those interfaces are already on the same link.'
  }

  return ''
})

const summary = computed(() => {
  if (problem.value || !sourcePort.value || !targetPort.value) return ''
  const sourceNetwork = sourcePort.value.network_id
  const targetNetwork = targetPort.value.network_id
  const ends = `${portLabel(sourceNode.value, sourceIndex.value)} ↔ ${portLabel(targetNode.value, targetIndex.value)}`
  if (sourceNetwork && targetNetwork) {
    return `${ends}: merges ${networkName(targetNetwork)} into ${networkName(sourceNetwork)}.`
  }
  if (sourceNetwork || targetNetwork) {
    return `${ends}: joins ${networkName(sourceNetwork || targetNetwork)}.`
  }

  return `${ends}: creates a new link.`
})

function swap() {
  const previousSource = sourceId.value
  const previousSourceIndex = sourceIndex.value
  sourceId.value = targetId.value
  targetId.value = previousSource
  sourceIndex.value = targetIndex.value
  targetIndex.value = previousSourceIndex
}

function submit() {
  if (problem.value) return
  emit('create', {
    sourceId: sourceId.value,
    sourceIndex: sourceIndex.value,
    targetId: targetId.value,
    targetIndex: targetIndex.value
  })
}
</script>
