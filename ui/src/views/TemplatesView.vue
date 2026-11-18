<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="templates">
    <div class="toolbar">
      <h1>Templates</h1>
      <label class="button">Import YAML
        <input type="file" accept=".yaml,.yml" hidden @change="importTemplate" />
      </label>
      <button class="ghost" @click="load">Refresh</button>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div class="editor-grid">
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
            <button class="ghost" @click="duplicateTemplate">Duplicate</button>
            <button v-if="selected.source === 'custom'" class="danger" @click="removeTemplate">Delete</button>
            <button :disabled="selected.source !== 'custom'" @click="saveTemplate">Save</button>
          </div>
          <textarea v-model="yamlText" spellcheck="false" aria-label="Template YAML" />
        </template>
        <p v-else class="hint">Select a template to inspect it. YAML import and duplication create custom templates.</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { notify } from '../stores/toasts'

const templates = ref([])
const selected = ref(null)
const yamlText = ref('')
const search = ref('')
const error = ref('')
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
