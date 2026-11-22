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
      <h1>Labs</h1>
      <span class="badge">{{ labs.length }} total</span>
      <div class="spacer"></div>
      <input v-model="search" class="search" placeholder="Search labs" aria-label="Search labs" />
    </div>
    <div class="toolbar">
      <button @click="openCreate">Create project</button>
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
        <tr><th>Name</th><th>Owner</th><th>Revision</th><th>Nodes</th><th>Status</th><th>Lock</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <tr v-for="lab in filteredLabs" :key="lab.id">
          <td>
            <router-link :to="`/labs/${lab.id}`">{{ lab.name }}</router-link>
            <span class="badge format" :title="`Lab file format: ${lab.format || 'unknown'}`">{{ formatLabel(lab.format) }}</span>
            <small>{{ lab.path }}</small>
          </td>
          <td>{{ lab.owner || '—' }}</td>
          <td>{{ lab.revision }}</td>
          <td>{{ lab.nodes }}</td>
          <td>
            <span :class="['state-pill', lab.running ? 'running' : 'stopped']" :data-lab-status="lab.id">
              <span class="state-dot" aria-hidden="true"></span>
              {{ lab.running ? `Running${lab.running_nodes ? ` (${lab.running_nodes})` : ''}` : 'Stopped' }}
            </span>
          </td>
          <td>
            <span v-if="lab.lock" class="lock">locked by {{ lab.lock.username }}</span>
            <span v-else>—</span>
          </td>
          <td class="actions">
            <button
              v-if="lab.running"
              class="ghost stop-lab"
              :disabled="stopping.has(lab.id)"
              @click="stop(lab)"
            >{{ stopping.has(lab.id) ? 'Stopping…' : '■ Stop' }}</button>
            <button class="ghost" @click="duplicate(lab)">Duplicate</button>
            <button class="ghost" @click="move(lab)">Move</button>
            <button class="ghost" @click="exportLab(lab)">Export</button>
            <button class="danger" @click="remove(lab)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!labs.length" class="empty">No labs yet. Create a project, drop a `.unl` file, or import one.</p>
    <p v-else-if="!filteredLabs.length" class="empty">No labs match “{{ search }}”.</p>

    <div v-if="createOpen" class="modal-backdrop" @click.self="closeCreate" @keydown.esc="closeCreate">
      <div class="modal create-project" role="dialog" aria-modal="true" aria-labelledby="create-project-title">
        <h2 id="create-project-title">Create project</h2>
        <form @submit.prevent="create">
          <label>Name
            <input
              ref="nameInput"
              v-model="newName"
              aria-label="Project name"
              placeholder="New project name"
              autocomplete="off"
              required
            />
          </label>
          <label>Folder (optional)
            <input
              v-model="newFolder"
              aria-label="Project folder"
              placeholder="Folder (optional)"
              autocomplete="off"
            />
          </label>
          <p v-if="createError" class="error" role="alert">{{ createError }}</p>
          <div class="row">
            <button type="button" class="ghost" :disabled="creating" @click="closeCreate">Cancel</button>
            <button type="submit" :disabled="creating">{{ creating ? 'Creating…' : 'Create' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="importReport.length" class="modal-backdrop" @click.self="importReport = []">
      <div class="modal import-report" role="dialog" aria-modal="true" aria-label="Import report">
        <h2>Import report</h2>
        <p class="hint">The lab was imported. Review these items before starting it.</p>
        <ul>
          <li v-for="(warning, index) in importReport" :key="index">
            <strong>{{ warning.type }}</strong>
            <span>{{ warning.message }}</span>
          </li>
        </ul>
        <div class="row"><button @click="importReport = []">Close</button></div>
      </div>
    </div>

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
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api'
import { notify } from '../stores/toasts'

const labs = ref([])
const trash = ref([])
const showTrash = ref(false)
const dragging = ref(false)
const search = ref('')
const newName = ref('')
const newFolder = ref('')
const createOpen = ref(false)
const creating = ref(false)
const createError = ref('')
const nameInput = ref(null)
const error = ref('')
const importReport = ref([])
const stopping = ref(new Set())
let refreshTimer = null
const filteredLabs = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return labs.value
  return labs.value.filter((lab) => `${lab.name} ${lab.path} ${lab.owner || ''}`.toLowerCase().includes(needle))
})

async function load() {
  error.value = ''
  try {
    await refreshLabs()
    await loadTrash()
  } catch (exception) {
    error.value = exception.message
  }
}

async function refreshLabs() {
  labs.value = (await api.labs()).data
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

async function openCreate() {
  createError.value = ''
  createOpen.value = true
  await nextTick()
  nameInput.value?.focus()
}

function closeCreate() {
  if (creating.value) return
  createOpen.value = false
  createError.value = ''
  newName.value = ''
  newFolder.value = ''
}

async function create() {
  if (creating.value) return
  if (!newName.value.trim()) {
    createError.value = 'A project name is required.'
    return
  }
  creating.value = true
  createError.value = ''
  try {
    const lab = (await api.createLab({ name: newName.value.trim(), networks: [], nodes: [] })).data
    if (newFolder.value.trim()) await api.moveLab(lab.id, newFolder.value.trim())
    notify('success', 'Lab created')
    creating.value = false
    closeCreate()
    await load()
  } catch (exception) {
    creating.value = false
    createError.value = exception.message
  }
}

function waitForJob(jobID) {
  return new Promise((resolve) => {
    let attempts = 0
    const poll = window.setInterval(async () => {
      attempts += 1
      try {
        const job = (await api.job(jobID)).data
        if (job.state !== 'running' && job.state !== 'queued') {
          window.clearInterval(poll)
          resolve(job)
          return
        }
      } catch {
        window.clearInterval(poll)
        resolve(null)
        return
      }
      if (attempts >= 60) {
        window.clearInterval(poll)
        resolve(null)
      }
    }, 500)
  })
}

async function stop(lab) {
  if (stopping.value.has(lab.id)) return
  stopping.value = new Set(stopping.value).add(lab.id)
  try {
    const result = await api.stopLab(lab.id)
    if (result?.job_id) await waitForJob(result.job_id)
    notify('success', `${lab.name} stopped`)
    await refreshLabs()
  } catch (exception) {
    notify('error', exception.message)
  } finally {
    const next = new Set(stopping.value)
    next.delete(lab.id)
    stopping.value = next
  }
}

async function duplicate(lab) {  try {
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
    const response = await api.importLab(file)
    const warnings = Array.isArray(response?.meta?.warnings) ? response.meta.warnings : []
    importReport.value = warnings
    notify('success', warnings.length ? `Lab imported with ${warnings.length} warning(s)` : 'Lab imported')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

function formatLabel(format) {
  if (format === 'eve-ng') return 'EVE-NG'
  if (format === 'legacy') return 'legacy'
  if (format === 'teralab-2') return 'v2'

  return 'v2'
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

onMounted(() => {
  load()
  refreshTimer = window.setInterval(() => {
    if (!document.hidden && !stopping.value.size) refreshLabs().catch(() => {})
  }, 15000)
})

onBeforeUnmount(() => {
  window.clearInterval(refreshTimer)
})
</script>
