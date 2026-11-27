<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="templates">
    <div v-if="!embedded" class="page-head">
      <h1>Templates</h1>
      <span class="badge">{{ templates.length }} total</span>
      <div class="spacer"></div>
    </div>
    <div class="toolbar">
      <label v-if="canAdmin" class="button">Import YAML
        <input type="file" accept=".yaml,.yml" hidden @change="importTemplate" />
      </label>
      <button class="ghost" @click="load">Refresh</button>
    </div>
    <p v-if="!canAdmin" class="hint catalogue-readonly" role="note">{{ readOnlyHint }}</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div class="template-layout">
      <aside class="palette">
        <h2>Library</h2>
        <input v-model="search" placeholder="Filter templates" aria-label="Filter templates" />
        <ul>
          <li v-for="template in filtered" :key="template.id" :class="{ active: selected?.id === template.id }" @click="select(template)">
            <strong>{{ template.name || template.id }}</strong>
            <small>{{ template.kind }} · {{ template.source }}</small>
          </li>
        </ul>
      </aside>
      <div class="template-editor">
        <template v-if="selected">
          <div class="toolbar">
            <strong>{{ selected.id }}</strong>
            <span class="badge">{{ selected.source }}</span>
            <button class="ghost" @click="exportTemplate">Export</button>
            <template v-if="canAdmin">
              <button class="ghost" @click="duplicateTemplate">Duplicate</button>
              <button v-if="selected.source === 'custom'" class="danger" @click="removeTemplate">Delete</button>
              <button :disabled="selected.source !== 'custom'" @click="saveTemplate">Save</button>
            </template>
          </div>
          <div class="template-split">
            <textarea v-model="yamlText" spellcheck="false" aria-label="Template YAML" :readonly="!canAdmin" />
            <aside class="template-preview" aria-label="Template preview">
              <dl>
                <dt>id</dt><dd>{{ selected.id }}</dd>
                <dt>kind</dt><dd>{{ selected.kind }}</dd>
                <dt>source</dt><dd>{{ selected.source }}</dd>
                <dt>icon</dt>
                <dd class="template-icon">
                  <img v-if="selectedIconSrc" :src="selectedIconSrc" alt="" />
                  <span>{{ selected.icon || '—' }}</span>
                </dd>
                <dt>defaults</dt>
                <dd><span v-for="(value, key) in (selected.defaults || {})" :key="key">{{ key }}={{ value }} </span><span v-if="!Object.keys(selected.defaults || {}).length">—</span></dd>
                <dt>options</dt>
                <dd><span v-for="(value, key) in (selected.options || {})" :key="key">{{ key }} </span><span v-if="!Object.keys(selected.options || {}).length">—</span></dd>
              </dl>
            </aside>
          </div>
        </template>
        <p v-else class="hint">Select a template to inspect it. YAML import and duplication create custom templates.</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { safeIconSrc } from '../media'
import { session } from '../stores/session'
import { notify } from '../stores/toasts'

defineProps({
  embedded: { type: Boolean, default: false }
})

const canAdmin = computed(() => session.user?.role === 'admin')
const isGuest = computed(() => session.user?.guest === true)
const readOnlyHint = computed(() => isGuest.value
  ? 'Demo accounts can browse the template catalogue. Creating, importing, changing, duplicating, and deleting templates require an administrator account.'
  : 'The template catalogue is read-only for your account. Creating, importing, changing, duplicating, and deleting templates require an administrator account.')

const templates = ref([])
const selected = ref(null)
const yamlText = ref('')
const search = ref('')
const error = ref('')
const selectedIconSrc = computed(() => safeIconSrc(selected.value?.icon))
const filtered = computed(() => {
  const needle = search.value.toLowerCase()
  return templates.value.filter((template) => `${template.id} ${template.name} ${template.kind}`.toLowerCase().includes(needle))
})

async function load() {
  error.value = ''
  try {
    templates.value = (await api.templates()).data
  } catch (exception) {
    error.value = exception.message
  }
}

async function select(template) {
  try {
    const data = (await api.template(template.id)).data
    selected.value = data
    yamlText.value = data.yaml
  } catch (exception) {
    error.value = exception.message
  }
}

async function saveTemplate() {
  try {
    const data = (await api.saveTemplate(selected.value.id, yamlText.value)).data
    selected.value = data
    notify('success', 'Template saved')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function duplicateTemplate() {
  try {
    const data = (await api.duplicateTemplate(selected.value.id)).data
    notify('success', 'Template duplicated')
    await load()
    await select(data)
  } catch (exception) {
    error.value = exception.message
  }
}

async function removeTemplate() {
  if (!window.confirm(`Delete template ${selected.value.id}?`)) return
  try {
    await api.deleteTemplate(selected.value.id)
    selected.value = null
    yamlText.value = ''
    notify('success', 'Template deleted')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function importTemplate(event) {
  const file = event.target.files?.[0]
  if (!file) return
  try {
    const data = (await api.importTemplate(file)).data
    notify('success', 'Template imported')
    await load()
    await select(data)
  } catch (exception) {
    error.value = exception.message
  } finally {
    event.target.value = ''
  }
}

async function exportTemplate() {
  try {
    const yaml = await api.exportTemplate(selected.value.id)
    const blob = new Blob([yaml], { type: 'application/yaml' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${selected.value.id}.yaml`
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (exception) {
    error.value = exception.message
  }
}

onMounted(load)
</script>
