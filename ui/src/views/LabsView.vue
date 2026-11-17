<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="labs">
    <div class="toolbar">
      <h1>Labs</h1>
      <form class="create" @submit.prevent="create">
        <input v-model="newName" placeholder="New lab name" aria-label="New lab name" required />
        <input v-model="newFolder" placeholder="Folder (optional)" aria-label="Folder" />
        <button type="submit">Create</button>
      </form>
      <label class="button">
        Import .unl
        <input type="file" accept=".unl,application/xml" hidden @change="importLab" />
      </label>
      <button class="ghost" @click="load">Refresh</button>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <table class="lab-table">
      <thead>
        <tr><th>Name</th><th>Owner</th><th>Revision</th><th>Nodes</th><th>Lock</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <tr v-for="lab in labs" :key="lab.id">
          <td>
            <router-link :to="`/labs/${lab.id}`">{{ lab.name }}</router-link>
            <small>{{ lab.path }}</small>
          </td>
          <td>{{ lab.owner || '—' }}</td>
          <td>{{ lab.revision }}</td>
          <td>{{ lab.nodes }}</td>
          <td>
            <span v-if="lab.lock" class="lock">locked by {{ lab.lock.username }}</span>
            <span v-else>—</span>
          </td>
          <td class="actions">
            <button class="ghost" @click="duplicate(lab)">Duplicate</button>
            <button class="ghost" @click="move(lab)">Move</button>
            <button class="ghost" @click="exportLab(lab)">Export</button>
            <button class="danger" @click="remove(lab)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!labs.length" class="empty">No labs yet. Create one or import a `.unl` file.</p>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'
import { notify } from '../stores/toasts'

const labs = ref([])
const newName = ref('')
const newFolder = ref('')
const error = ref('')

async function load() {
  error.value = ''
  try {
    labs.value = (await api.labs()).data
  } catch (exception) {
    error.value = exception.message
  }
}

async function create() {
  try {
    const lab = (await api.createLab({ name: newName.value, networks: [], nodes: [] })).data
    newName.value = ''
    if (newFolder.value.trim()) await api.moveLab(lab.id, newFolder.value.trim())
    notify('success', 'Lab created')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function duplicate(lab) {
  try {
    await api.duplicateLab(lab.id)
    notify('success', 'Lab duplicated')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function move(lab) {
  const folder = window.prompt('Move lab to folder (blank for root):', '')
  if (folder === null) return
  try {
    await api.moveLab(lab.id, folder.trim())
    notify('success', 'Lab moved')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function remove(lab) {
  if (!window.confirm(`Delete “${lab.name}”?`)) return
  try {
    await api.deleteLab(lab.id)
    notify('success', 'Lab deleted')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function exportLab(lab) {
  try {
    const xml = await api.exportLab(lab.id)
    const blob = new Blob([xml], { type: 'application/xml' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${lab.name}.unl`
    anchor.click()
    URL.revokeObjectURL(url)
  } catch (exception) {
    error.value = exception.message
  }
}

async function importLab(event) {
  const file = event.target.files?.[0]
  if (!file) return
  try {
    await api.importLab(file)
    notify('success', 'Lab imported')
    await load()
  } catch (exception) {
    error.value = exception.message
  } finally {
    event.target.value = ''
  }
}

onMounted(load)
</script>
