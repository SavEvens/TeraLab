<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <aside v-if="node" class="inspector">
    <h2>{{ node.data.name || node.id }}</h2>
    <label>Name <input :value="node.data.name" @input="update('name', $event.target.value)" /></label>
    <label>Type
      <select :value="node.data.kind" @change="update('kind', $event.target.value)">
        <option value="qemu">qemu</option>
        <option value="vpcs">vpcs</option>
        <option value="iol">iol</option>
        <option value="dynamips">dynamips</option>
      </select>
    </label>
    <label>Image <input :value="node.data.image" @input="update('image', $event.target.value)" placeholder="/var/lib/teralab/images/..." /></label>
    <label>Console
      <select :value="node.data.console" @change="update('console', $event.target.value)">
        <option value="telnet">telnet</option>
        <option value="vnc">vnc</option>
      </select>
    </label>
    <label>CPU <input type="number" min="1" :value="node.data.cpu" @input="update('cpu', Number($event.target.value))" /></label>
    <label>RAM (MiB) <input type="number" min="64" :value="node.data.ram" @input="update('ram', Number($event.target.value))" /></label>
    <label>Ethernet
      <input type="number" min="0" max="16" :value="node.data.interfaces.length" @change="updatePorts(Number($event.target.value))" />
    </label>
    <label>Startup config (IOL)
      <textarea :value="node.data.config || ''" rows="6" placeholder="hostname R1&#10;interface Ethernet0/0" @input="update('config', $event.target.value)" />
    </label>
    <div class="row">
      <button @click="$emit('console', node)">Console</button>
      <button class="ghost" :disabled="!node.data.config" @click="$emit('config', node)">Config</button>
      <button class="danger" @click="$emit('delete', node)">Delete</button>
    </div>
  </aside>
  <aside v-else class="inspector">
    <h2>Inspector</h2>
    <p class="hint">Select a node to edit it, or drag a template from the palette.</p>
  </aside>
</template>

<script setup>
const props = defineProps({ node: { type: Object, default: null } })
const emit = defineEmits(['update', 'delete', 'console', 'ports'])

function update(key, value) {
  emit('update', { key, value })
}

function updatePorts(count) {
  emit('ports', Math.max(0, Math.min(16, count)))
}
</script>
