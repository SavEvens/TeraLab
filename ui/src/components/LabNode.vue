<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="lab-node" :class="[data.kind, data.state]">
    <header>
      <img v-if="data.icon" :src="`/images/icons/${data.icon}`" alt="" />
      {{ data.name || data.id }}
    </header>
    <small>{{ data.kind }} · {{ data.interfaces.length }} port(s)</small>
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
import { Handle, Position } from '@vue-flow/core'

defineProps({ data: { type: Object, required: true } })

function handleTop(index, total) {
  return `${((index + 1) / (total + 1)) * 100}%`
}
</script>
