<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="lab-node" :class="[data.kind, data.state]">
    <div class="node-icon">
      <img v-if="data.icon" :src="`/images/icons/${data.icon}`" alt="" />
      <span v-else aria-hidden="true">{{ glyph }}</span>
    </div>
    <header>
      <strong>{{ data.name || data.id }}</strong>
      <small>{{ data.kind }} · {{ data.interfaces.length }}p</small>
    </header>
    <span class="node-led" :title="data.state || 'stopped'"></span>
    <Handle
      v-for="(iface, index) in data.interfaces"
      :id="`t-${index}`"
      :key="`t-${index}`"
      type="target"
      :position="Position.Left"
      :style="{ top: handleTop(index, data.interfaces.length) }"
    />
    <Handle
      v-for="(iface, index) in data.interfaces"
      :id="`s-${index}`"
      :key="`s-${index}`"
      type="source"
      :position="Position.Right"
      :style="{ top: handleTop(index, data.interfaces.length) }"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'

const props = defineProps({ data: { type: Object, required: true } })
const glyph = computed(() => ({ qemu: '▣', vpcs: '▤', iol: '▥', dynamips: '▦', docker: '◈' }[props.data.kind] || '◇'))

function handleTop(index, total) {
  return `${((index + 1) / (total + 1)) * 100}%`
}
</script>
