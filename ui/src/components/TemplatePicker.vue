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

      <p class="hint">Templates are grouped by whether they still need an image.</p>

      <section v-if="ready.length" class="template-group" data-group="ready">
        <h3>Ready to deploy <span class="badge">{{ ready.length }}</span></h3>
        <p class="hint">An image is mapped, or the template supplies a container image.</p>
        <ul class="template-grid">
          <li v-for="template in ready" :key="template.id">
            <img v-if="safeIconSrc(template.icon)" :src="safeIconSrc(template.icon)" alt="" />
            <span v-else class="template-dot" aria-hidden="true"></span>
            <span class="template-copy">
              <strong>{{ template.name || template.id }}</strong>
              <small>{{ detail(template) }}</small>
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
            <img v-if="safeIconSrc(template.icon)" :src="safeIconSrc(template.icon)" alt="" />
            <span v-else class="template-dot" aria-hidden="true"></span>
            <span class="template-copy">
              <strong>{{ template.name || template.id }}</strong>
              <small>{{ template.kind }} · no image</small>
            </span>
            <button class="ghost" @click="add(template)">Add</button>
          </li>
        </ul>
      </section>

      <section v-if="locked.length" class="template-group" data-group="demo-locked">
        <h3>Available on the full platform <span class="badge">{{ locked.length }}</span></h3>
        <p class="hint">The demo includes a starter set of templates. Sign in with a full account to use these.</p>
        <ul class="template-grid">
          <li v-for="template in locked" :key="template.id">
            <img v-if="safeIconSrc(template.icon)" :src="safeIconSrc(template.icon)" alt="" />
            <span v-else class="template-dot" aria-hidden="true"></span>
            <span class="template-copy">
              <strong>{{ template.name || template.id }}</strong>
              <small>{{ template.kind }} · available on the full platform</small>
            </span>
            <button
              disabled
              aria-disabled="true"
              :title="lockedTitle(template)"
              @click="add(template)"
            >Add</button>
          </li>
        </ul>
      </section>

      <p v-if="!ready.length && !needsImage.length && !locked.length" class="empty">No templates match “{{ search }}”.</p>
      <p v-else-if="!ready.length && !needsImage.length" class="empty">No templates included in the demo match “{{ search }}”.</p>

      <div class="picker-special">
        <button class="ghost" @click="add({ id: '__text', name: 'Text', kind: 'text' }, 1)">Add text</button>
        <button class="ghost" @click="add({ id: '__picture', name: 'Picture', kind: 'picture' }, 1)">Add picture</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { safeIconSrc } from '../media'

const props = defineProps({
  templates: { type: Array, default: () => [] },
  images: { type: Array, default: () => [] },
  // Demo guests receive the template ids their account may use. A null
  // allowlist means no filtering (non-guest or health unavailable); an empty
  // array is a real policy: the demo allows no catalogue templates.
  allowedTemplates: { type: Array, default: null }
})
const emit = defineEmits(['add', 'close'])

const search = ref('')
const searchInput = ref(null)
const count = ref(1)

function clampCount(value) {
  return Math.max(1, Math.min(20, Math.trunc(Number(value) || 1)))
}

function add(template, fixedCount = null) {
  // Locked tiles render a disabled button; refuse them defensively as well.
  if (isLocked(template)) return
  const selectedCount = fixedCount ?? clampCount(count.value)
  emit('add', { template, count: selectedCount, image: assignedImage(template) })
}

// Docker templates carry their own container reference (or rely on the
// template default), so they never need an image-store mapping.
function isDocker(template) {
  return template.kind === 'docker'
}

// Templates with mapped images get a usable reference immediately. With more
// than one mapping the first by display name wins so the result is stable;
// the Inspector still lets the user pick another mapped image afterwards.
function assignedImage(template) {
  if (isDocker(template)) return template.options?.docker?.image || ''
  const mapped = imagesFor(template)
  if (!mapped.length) return ''
  const [first] = [...mapped].sort((left, right) => {
    const a = String(left.name || '')
    const b = String(right.name || '')

    return a < b ? -1 : a > b ? 1 : 0
  })

  return first.reference || ''
}

const allowSet = computed(() => {
  if (!Array.isArray(props.allowedTemplates)) return null
  return new Set(props.allowedTemplates.filter(Boolean))
})
const mappedIds = computed(() => new Set(props.images.map((image) => image.template).filter(Boolean)))
const matching = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return props.templates.filter((template) => `${template.id} ${template.name} ${template.kind}`.toLowerCase().includes(needle))
})
const available = computed(() => allowSet.value
  ? matching.value.filter((template) => allowSet.value.has(template.id))
  : matching.value)
const locked = computed(() => allowSet.value
  ? matching.value.filter((template) => !allowSet.value.has(template.id))
  : [])
const ready = computed(() => available.value.filter((template) => isDocker(template) || mappedIds.value.has(template.id)))
const needsImage = computed(() => available.value.filter((template) => !isDocker(template) && !mappedIds.value.has(template.id)))

function imagesFor(template) {
  return props.images.filter((image) => image.template === template.id)
}

// Text and picture nodes are editor primitives, not catalogue templates, so
// the allowlist never applies to them.
function isLocked(template) {
  if (!allowSet.value || !template) return false
  if (template.kind === 'text' || template.kind === 'picture') return false
  return !allowSet.value.has(template.id)
}

function lockedTitle(template) {
  return `“${template.name || template.id}” is available on the full platform. Sign in with a full account to add it.`
}

// Docker tiles name the container reference the template will start; other
// kinds keep listing the mapped image names.
function detail(template) {
  if (isDocker(template)) return `docker · ${template.options?.docker?.image || 'no default image'}`
  return `${template.kind} · ${imagesFor(template).map((image) => image.name).join(', ')}`
}

onMounted(async () => {
  await nextTick()
  searchInput.value?.focus()
})
</script>
