<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section
    class="labs"
    :class="{ dragging }"
    @dragover.prevent="dragging = true"
    @dragleave.prevent="dragging = false"
    @drop.prevent="dropImport"
  >
    <div class="page-head">
      <span class="crumbs">Workspace /</span>
      <h1>Labs</h1>
      <span class="badge">{{ labs.length }} total</span>
      <div class="spacer"></div>
      <input v-model="search" class="search" placeholder="Search labs" aria-label="Search labs" />
    </div>
    <div class="toolbar">
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
      <button class="ghost" @click="toggleTrash">Trash ({{ trash.length }})</button>
    </div>
    <p v-if="dragging" class="drop-hint">Drop a `.unl` file to import it</p>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <table class="lab-table">
      <thead>
        <tr><th>Name</th><th>Owner</th><th>Revision</th><th>Nodes</th><th>Lock</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <tr v-for="lab in filteredLabs" :key="lab.id">
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
    <p v-if="!labs.length" class="empty">No labs yet. Create one, drop a `.unl` file, or import one.</p>
    <p v-else-if="!filteredLabs.length" class="empty">No labs match “{{ search }}”.</p>

    <section v-if="showTrash" class="trash">
      <h2>Trash</h2>
      <p class="hint">Deleted labs are kept for 30 days and can be restored.</p>
      <table class="lab-table">
        <thead><tr><th>Name</th><th>Original path</th><th>Deleted</th><th>Expires</th><th>Actions</th></tr></thead>
        <tbody>
          <tr v-for="entry in trash" :key="entry.trash_token">
            <td>{{ entry.name }}</td>
            <td>{{ entry.original_path || '—' }}</td>
            <td>{{ new Date(entry.deleted_at * 1000).toLocaleString() }}</td>
            <td>{{ new Date(entry.expires_at * 1000).toLocaleDateString() }}</td>
            <td class="actions">
              <button class="ghost" @click="restore(entry)">Restore</button>
              <button class="danger" @click="purge(entry)">Delete forever</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!trash.length" class="empty">Trash is empty.</p>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { notify } from '../stores/toasts'

const labs = ref([])
const trash = ref([])
const showTrash = ref(false)
const dragging = ref(false)
const search = ref('')
const newName = ref('')
const newFolder = ref('')
const error = ref('')
const filteredLabs = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return labs.value
  return labs.value.filter((lab) => `${lab.name} ${lab.path} ${lab.owner || ''}`.toLowerCase().includes(needle))
})

async function load() {
  error.value = ''
  try {
    labs.value = (await api.labs()).data
    await loadTrash()
  } catch (exception) {
    error.value = exception.message
  }
}

async function loadTrash() {
  try {
    trash.value = (await api.trash()).data
  } catch {
    trash.value = []
  }
}

async function toggleTrash() {
  showTrash.value = !showTrash.value
  if (showTrash.value) await loadTrash()
}

async function restore(entry) {
  try {
    await api.restoreLab(entry.id)
    notify('success', 'Lab restored')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function purge(entry) {
  if (!window.confirm(`Permanently delete “${entry.name}”?`)) return
  try {
    await api.purgeLab(entry.id)
    notify('success', 'Lab deleted permanently')
    await load()
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
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (exception) {
    error.value = exception.message
  }
}

async function importFile(file) {
  if (!file) return
  try {
    await api.importLab(file)
    notify('success', 'Lab imported')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function importLab(event) {
  const file = event.target.files?.[0]
  await importFile(file)
  event.target.value = ''
}

async function dropImport(event) {
  dragging.value = false
  const file = event.dataTransfer?.files?.[0]
  if (!file) return
  if (!file.name.endsWith('.unl')) {
    error.value = 'Only .unl files can be imported.'
    return
  }
  await importFile(file)
}

onMounted(load)
</script>
