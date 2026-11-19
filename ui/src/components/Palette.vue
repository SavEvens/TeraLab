<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <aside class="palette" aria-label="Device palette">
    <div class="palette-head">
      <h2>Devices</h2>
      <span class="badge">{{ filtered.length }}</span>
    </div>
    <input v-model="search" placeholder="Filter templates" aria-label="Filter templates" />
    <ul>
      <li
        v-for="template in filtered"
        :key="template.id"
        draggable="true"
        :data-template="template.id"
        :data-kind="template.kind"
        tabindex="0"
        role="button"
        :aria-label="`Add ${template.name || template.id} (${template.kind})`"
        @dragstart="drag($event, template)"
        @dblclick="$emit('add', template)"
        @keydown.enter.prevent="$emit('add', template)"
        @keydown.space.prevent="$emit('add', template)"
      >
        <img v-if="template.icon" :src="`/images/icons/${template.icon}`" alt="" />
        <strong>{{ template.name || template.id }}</strong>
        <small>{{ template.kind }}</small>
      </li>
    </ul>
    <div class="special">
      <button class="ghost" @click="$emit('add', { id: '__text', name: 'Text', kind: 'text' })">Text</button>
      <button class="ghost" @click="$emit('add', { id: '__picture', name: 'Picture', kind: 'picture' })">Picture</button>
    </div>
  </aside>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ templates: { type: Array, default: () => [] } })
const emit = defineEmits(['add'])
const search = ref('')
const filtered = computed(() => {
  const needle = search.value.toLowerCase()
  return props.templates.filter((template) => `${template.id} ${template.name} ${template.kind}`.toLowerCase().includes(needle))
})

function drag(event, template) {
  event.dataTransfer.setData('application/x-teralab-template', template.id)
  event.dataTransfer.effectAllowed = 'copy'
}
</script>
