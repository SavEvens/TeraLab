<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <aside class="inspector" aria-label="Node inspector">
    <div class="palette-head">
      <h2>Inspector</h2>
      <span v-if="node" class="badge">{{ node.data.kind }}</span>
    </div>
    <template v-if="node">
      <label>Name <input :value="node.data.name" @input="update('name', $event.target.value)" /></label>
      <label v-if="templates.length">Apply template
        <select :value="node.data.template" @change="$emit('template', $event.target.value)">
          <option value="">—</option>
          <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.name || template.id }}</option>
        </select>
      </label>
      <div class="field-grid">
        <label>Type
          <select :value="node.data.kind" @change="update('kind', $event.target.value)">
            <option value="qemu">qemu</option>
            <option value="vpcs">vpcs</option>
            <option value="iol">iol</option>
            <option value="dynamips">dynamips</option>
          </select>
        </label>
        <label>Console
          <select :value="node.data.console" @change="update('console', $event.target.value)">
            <option value="telnet">telnet</option>
            <option value="vnc">vnc</option>
          </select>
        </label>
        <label>CPU <input type="number" min="1" :value="node.data.cpu" @input="update('cpu', Number($event.target.value))" /></label>
        <label>RAM (MiB) <input type="number" min="64" :value="node.data.ram" @input="update('ram', Number($event.target.value))" /></label>
        <label>NIC driver
          <select :value="canonicalNic(node.data.nic)" @change="update('nic', $event.target.value)">
            <option value="virtio">virtio</option>
            <option value="vmxnet3">vmxnet3</option>
            <option value="e1000">e1000</option>
            <option value="rtl8139">rtl8139</option>
          </select>
        </label>
        <label>Ethernet
          <input type="number" min="0" max="16" :value="node.data.interfaces.length" @change="updatePorts(Number($event.target.value))" />
        </label>
        <label>Serial ports
          <input type="number" min="0" max="8" :value="node.data.serial || 0" @input="update('serial', Number($event.target.value))" />
        </label>
        <label v-if="node.data.kind === 'iol'">NVRAM (KiB)
          <input type="number" min="64" max="4096" :value="node.data.nvram || 512" @input="update('nvram', Number($event.target.value))" />
        </label>
      </div>
      <label>Image
        <select :value="node.data.image" @change="update('image', $event.target.value)">
          <option value="">—</option>
          <option v-if="node.data.image && !hasNodeImage(node)" :value="node.data.image">
            ⚠ {{ node.data.image }} (not mapped to this template)
          </option>
          <option v-for="image in imagesForNode(node)" :key="image.id" :value="image.reference">
            {{ image.name }} ({{ image.format || image.kind }})
          </option>
        </select>
      </label>
      <label v-if="node.data.kind === 'qemu'">ISO
        <select :value="node.data.iso" @change="update('iso', $event.target.value)">
          <option value="">—</option>
          <option v-if="node.data.iso && !hasReference(node.data.iso, 'iso')" :value="node.data.iso">
            ⚠ {{ node.data.iso }} (unmapped)
          </option>
          <option v-for="image in imagesFor('iso')" :key="image.id" :value="image.reference">
            {{ image.name }} ({{ image.format || image.kind }})
          </option>
        </select>
      </label>
      <label>Startup config (IOL)
        <textarea :value="node.data.config || ''" rows="6" placeholder="hostname R1&#10;interface Ethernet0/0" @input="update('config', $event.target.value)" />
      </label>
      <div class="row">
        <button @click="$emit('console', node)">Console</button>
        <button class="ghost" :disabled="!node.data.config" @click="$emit('config', node)">Config</button>
        <button class="danger" @click="$emit('delete', node)">Delete</button>
      </div>
    </template>
    <p v-else class="hint">Select a node to edit it, or use “+ Add node” to place a template.</p>
  </aside>
</template>

<script setup>
const props = defineProps({
  node: { type: Object, default: null },
  templates: { type: Array, default: () => [] },
  images: { type: Array, default: () => [] }
})
const emit = defineEmits(['update', 'delete', 'console', 'config', 'ports', 'template'])

// The agent only accepts these four drivers. Legacy labs may still carry an
// older alias; show the canonical equivalent without rewriting the stored
// value (any other value is left untouched too).
const LEGACY_NIC_ALIASES = { 'virtio-net-pci': 'virtio', i82559er: 'e1000' }

function canonicalNic(value) {
  const nic = value || 'e1000'

  return LEGACY_NIC_ALIASES[nic] || nic
}

function imagesFor(kind) {
  return props.images.filter((image) => image.kind === kind)
}

// When the node's template has at least one mapped image, the selector offers
// only that set. Templates without a mapping keep the previous behaviour and
// list every image of the node's engine kind.
function imagesForNode(node) {
  const mapped = props.images.filter((image) => image.template && image.template === node.data.template)

  return mapped.length ? mapped : imagesFor(node.data.kind)
}

function hasNodeImage(node) {
  return imagesForNode(node).some((image) => image.reference === node.data.image)
}

function update(key, value) {
  emit('update', { key, value })
}

function updatePorts(count) {
  emit('ports', Math.max(0, Math.min(16, count)))
}
</script>
