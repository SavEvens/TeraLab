<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal template-picker" role="dialog" aria-modal="true" aria-label="Add node">
      <div class="picker-head">
        <h2>Add node</h2>
        <input ref="searchInput" v-model="search" placeholder="Filter templates" aria-label="Filter templates" />
        <label class="picker-count">Count
          <input v-model.number="count" type="number" min="1" max="20" step="1" aria-label="Count" @change="count = clampCount(count)" />
        </label>
        <button class="ghost" aria-label="Close" @click="$emit('close')">✕</button>
      </div>

      <p class="hint">Templates are grouped by whether an image is already mapped to them.</p>

      <section v-if="ready.length" class="template-group" data-group="ready">
        <h3>Ready to deploy <span class="badge">{{ ready.length }}</span></h3>
        <p class="hint">An image is mapped to these templates.</p>
        <ul class="template-grid">
          <li v-for="template in ready" :key="template.id">
            <img v-if="template.icon" :src="`/images/icons/${template.icon}`" alt="" />
            <span v-else class="template-dot" aria-hidden="true"></span>
            <span class="template-copy">
              <strong>{{ template.name || template.id }}</strong>
              <small>{{ template.kind }} · {{ imagesFor(template).map((image) => image.name).join(', ') }}</small>
            </span>
            <button @click="add(template)">Add</button>
          </li>
        </ul>
      </section>

      <section v-if="needsImage.length" class="template-group" data-group="needs-image">
        <h3>Needs an image <span class="badge">{{ needsImage.length }}</span></h3>
        <p class="hint">No image is mapped yet. Add the node now and map an image on the Images page.</p>
        <ul class="template-grid">
          <li v-for="template in needsImage" :key="template.id">
            <img v-if="template.icon" :src="`/images/icons/${template.icon}`" alt="" />
            <span v-else class="template-dot" aria-hidden="true"></span>
            <span class="template-copy">
              <strong>{{ template.name || template.id }}</strong>
              <small>{{ template.kind }} · no image</small>
            </span>
            <button class="ghost" @click="add(template)">Add</button>
          </li>
        </ul>
      </section>

      <p v-if="!ready.length && !needsImage.length" class="empty">No templates match “{{ search }}”.</p>

      <div class="picker-special">
        <button class="ghost" @click="add({ id: '__text', name: 'Text', kind: 'text' }, 1)">Add text</button>
        <button class="ghost" @click="add({ id: '__picture', name: 'Picture', kind: 'picture' }, 1)">Add picture</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'

const props = defineProps({
  templates: { type: Array, default: () => [] },
  images: { type: Array, default: () => [] }
})
const emit = defineEmits(['add', 'close'])

const search = ref('')
const searchInput = ref(null)
const count = ref(1)

function clampCount(value) {
  return Math.max(1, Math.min(20, Math.trunc(Number(value) || 1)))
}

function add(template, fixedCount = null) {
  const selectedCount = fixedCount ?? clampCount(count.value)
  emit('add', { template, count: selectedCount })
}

const mappedIds = computed(() => new Set(props.images.map((image) => image.template).filter(Boolean)))
const matching = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return props.templates.filter((template) => `${template.id} ${template.name} ${template.kind}`.toLowerCase().includes(needle))
})
const ready = computed(() => matching.value.filter((template) => mappedIds.value.has(template.id)))
const needsImage = computed(() => matching.value.filter((template) => !mappedIds.value.has(template.id)))

function imagesFor(template) {
  return props.images.filter((image) => image.template === template.id)
}

onMounted(async () => {
  await nextTick()
  searchInput.value?.focus()
})
</script>
