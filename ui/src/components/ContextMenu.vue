<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="context-menu" role="menu" :style="{ left: `${x}px`, top: `${y}px` }" @click.stop @contextmenu.prevent>
    <template v-for="(item, index) in items" :key="`${item.label}-${index}`">
      <div v-if="item.separator" class="context-separator" role="separator"></div>
      <div
        v-else
        class="context-item"
        :class="{ danger: item.danger, disabled: item.disabled, 'has-children': item.children?.length }"
        role="menuitem"
        :aria-disabled="item.disabled ? 'true' : 'false'"
        @click="choose(item)"
      >
        <span>{{ item.label }}</span>
        <span v-if="item.children?.length" class="arrow">▸</span>
        <div v-if="item.children?.length" class="context-submenu" role="menu">
          <div
            v-for="(child, childIndex) in item.children"
            :key="`${child.label}-${childIndex}`"
            class="context-item"
            :class="{ danger: child.danger, disabled: child.disabled }"
            role="menuitem"
            :aria-disabled="child.disabled ? 'true' : 'false'"
            @click.stop="choose(child)"
          >
            <span>{{ child.label }}</span>
            <small v-if="child.hint">{{ child.hint }}</small>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
const props = defineProps({
  x: { type: Number, required: true },
  y: { type: Number, required: true },
  items: { type: Array, default: () => [] }
})
const emit = defineEmits(['close'])

function choose(item) {
  if (item.disabled || item.children?.length) return
  emit('close')
  item.action?.()
}
</script>
