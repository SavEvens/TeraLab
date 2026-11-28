<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="lab-node" :class="[data.kind, data.state]">
    <div class="node-icon">
      <img v-if="iconSrc" :src="iconSrc" alt="" />
      <svg v-else viewBox="0 0 32 32" aria-hidden="true">
        <template v-if="data.kind === 'vpcs'">
          <rect x="5" y="7" width="22" height="14" rx="1.5" />
          <path d="M12 25h8M16 21v4" />
        </template>
        <template v-else-if="data.kind === 'iol' || data.kind === 'dynamips'">
          <circle cx="16" cy="16" r="9" />
          <path d="M7 16h18M16 7c3 3 3 15 0 18M16 7c-3 3-3 15 0 18" />
        </template>
        <template v-else-if="data.kind === 'docker'">
          <path d="M6 14h20v4a7 7 0 0 1-7 7h-6a7 7 0 0 1-7-7z" />
          <path d="M10 14V9h4v5M16 14V6h4v8" />
        </template>
        <template v-else-if="data.kind === 'cloud'">
          <path d="M9 22a5 5 0 0 1 .5-10A7 7 0 0 1 23 14a4 4 0 0 1-1 8z" />
        </template>
        <template v-else>
          <rect x="7" y="8" width="18" height="13" rx="1.5" />
          <path d="M12 25h8M16 21v4" />
        </template>
      </svg>
    </div>
    <span class="node-led" :title="data.state || 'stopped'"></span>
    <span class="node-label" :title="`${data.name || data.id} · ${data.kind} · ${data.interfaces.length} port(s)`">
      {{ data.name || data.id }}
    </span>
    <Handle id="node-t" type="target" :position="Position.Left" class="node-handle target" />
    <Handle id="node-s" type="source" :position="Position.Right" class="node-handle source" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { safeIconSrc } from '../media'

const props = defineProps({ data: { type: Object, required: true } })
const iconSrc = computed(() => safeIconSrc(props.data.icon))
</script>
