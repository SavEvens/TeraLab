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
        Import lab
        <input type="file" accept=".unl,.yaml,.yml,application/xml,application/yaml,text/yaml" hidden @change="importLab" />
      </label>
      <button class="ghost" @click="load">Refresh</button>
      <button class="ghost" @click="toggleTrash">Trash ({{ trash.length }})</button>
    </div>
    <p v-if="dragging" class="drop-hint">Drop a `.unl`, `.yaml`, or `.yml` file to import it</p>

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
          <td>
            <div class="row-actions">
              <button
                v-if="lab.running"
                class="ghost stop-lab"
                :disabled="stopping.has(lab.id)"
                @click="stop(lab)"
              >{{ stopping.has(lab.id) ? 'Stopping…' : '■ Stop' }}</button>
              <button class="ghost" @click="duplicate(lab)">Duplicate</button>
              <button class="ghost" @click="move(lab)">Move</button>
              <button class="ghost" @click="exportLab(lab)">Export</button>
              <button v-if="canDeleteLab(lab)" class="danger" @click="remove(lab)">Delete</button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!labs.length" class="empty">No labs yet. Create a project, drop a `.unl` file, or import one. You can also create a project from pasted YAML.</p>
    <p v-else-if="!filteredLabs.length" class="empty">No labs match “{{ search }}”.</p>

    <div v-if="createOpen" class="modal-backdrop" @click.self="closeCreate" @keydown.esc="closeCreate">
      <div
        class="modal create-project"
        :class="{ 'yaml-mode': createMode === 'yaml' }"
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-project-title"
      >
        <h2 id="create-project-title">Create project</h2>
        <div class="create-mode" role="tablist" aria-label="Project source">
          <button
            id="create-mode-blank"
            type="button"
            role="tab"
            aria-controls="create-panel-blank"
            :aria-selected="createMode === 'blank'"
            :class="{ active: createMode === 'blank' }"
            @click="setCreateMode('blank')"
          >Blank project</button>
          <button
            id="create-mode-yaml"
            type="button"
            role="tab"
            aria-controls="create-panel-yaml"
            :aria-selected="createMode === 'yaml'"
            :class="{ active: createMode === 'yaml' }"
            @click="setCreateMode('yaml')"
          >From YAML</button>
        </div>
        <form
          v-if="createMode === 'blank'"
          id="create-panel-blank"
          role="tabpanel"
          aria-labelledby="create-mode-blank"
          @submit.prevent="create"
        >
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
        <form
          v-else
          id="create-panel-yaml"
          class="yaml-form"
          role="tabpanel"
          aria-labelledby="create-mode-yaml"
          @submit.prevent="createFromYaml"
        >
          <label>Lab YAML
            <textarea
              ref="yamlInput"
              v-model="yamlText"
              class="yaml-text"
              aria-label="Lab YAML"
              spellcheck="false"
              placeholder="Paste lab YAML (schema 1; see docs/LAB-YAML.md)&#10;name: My lab&#10;nodes:&#10;  R1:&#10;    template: iol"
              @keydown.ctrl.enter.prevent="createFromYaml"
              @keydown.meta.enter.prevent="createFromYaml"
            ></textarea>
          </label>
          <p class="hint">
            The lab name comes from the YAML. Format reference: <code>docs/LAB-YAML.md</code> (schema 1).
            <kbd>Ctrl</kbd>+<kbd>Enter</kbd> creates the lab.
          </p>
          <label>Folder (optional)
            <input
              v-model="newFolder"
              aria-label="Project folder"
              placeholder="Folder (optional)"
              autocomplete="off"
            />
          </label>
          <p v-if="yamlError" class="error" role="alert">{{ yamlError }}</p>
          <div v-if="yamlValidation" class="yaml-validation" aria-live="polite">
            <ul v-if="yamlValidation.errors.length" class="error yaml-errors" role="alert">
              <li v-for="(entry, index) in yamlValidation.errors" :key="index">{{ entry }}</li>
            </ul>
            <p v-else-if="yamlValidation.valid" class="ok">Valid YAML</p>
            <p v-else class="error" role="alert">The YAML is not valid.</p>
            <ul v-if="yamlValidation.warnings.length" class="yaml-warnings" aria-label="YAML warnings">
              <li v-for="(warning, index) in yamlValidation.warnings" :key="index">
                <strong>{{ warning.type }}</strong>
                <span>{{ warning.node ? `${warning.node}: ` : '' }}{{ warning.message }}</span>
              </li>
            </ul>
            <p v-if="yamlCountsLabel" class="yaml-counts">{{ yamlCountsLabel }}</p>
          </div>
          <div class="row">
            <button type="button" class="ghost" :disabled="creating" @click="closeCreate">Cancel</button>
            <button
              type="button"
              class="ghost yaml-validate"
              :disabled="!yamlText.trim() || validating || creating"
              @click="validateYaml"
            >{{ validating ? 'Validating…' : 'Validate' }}</button>
            <button type="submit" :disabled="!yamlText.trim() || creating">
              {{ creating ? 'Creating…' : 'Create from YAML' }}
            </button>
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
            <td>
              <div class="row-actions">
                <button class="ghost" @click="restore(entry)">Restore</button>
                <button class="danger" @click="purge(entry)">Delete forever</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!trash.length" class="empty">Trash is empty.</p>
    </section>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import { session } from '../stores/session'
import { notify } from '../stores/toasts'

const router = useRouter()
const labs = ref([])
const trash = ref([])
const showTrash = ref(false)
const dragging = ref(false)
const search = ref('')
const newName = ref('')
const newFolder = ref('')
const createOpen = ref(false)
const createMode = ref('blank')
const creating = ref(false)
const createError = ref('')
const yamlText = ref('')
const yamlValidation = ref(null)
const validating = ref(false)
const yamlError = ref('')
const nameInput = ref(null)
const yamlInput = ref(null)
const error = ref('')
const importReport = ref([])
const stopping = ref(new Set())
let refreshTimer = null
const yamlCountsLabel = computed(() => {
  const counts = yamlValidation.value?.counts
  if (!counts) return ''
  const nodes = Number.isFinite(Number(counts.nodes)) ? Number(counts.nodes) : 0
  const networks = Number.isFinite(Number(counts.networks)) ? Number(counts.networks) : 0
  const links = Number.isFinite(Number(counts.links)) ? Number(counts.links) : 0
  return `${nodes} nodes · ${networks} networks · ${links} links`
})
const filteredLabs = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return labs.value
  return labs.value.filter((lab) => `${lab.name} ${lab.path} ${lab.owner || ''}`.toLowerCase().includes(needle))
})

// The list does not expose the caller's effective per-lab role, so only offer
// Delete where the API is guaranteed to allow it: global admins and the lab
// owner. Local admins keep the API ability even though this shortcut is hidden.
function canDeleteLab(lab) {
  const user = session.user
  if (!user) return false
  if (user.role === 'admin') return true
  // Lab owner is stored and returned as a user id, not a username.
  return typeof user.id === 'string' && lab.owner === user.id
}

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
  yamlError.value = ''
  createMode.value = 'blank'
  createOpen.value = true
  await nextTick()
  nameInput.value?.focus()
}

function closeCreate() {
  if (creating.value) return
  createOpen.value = false
  createMode.value = 'blank'
  createError.value = ''
  yamlError.value = ''
  yamlText.value = ''
  yamlValidation.value = null
  newName.value = ''
  newFolder.value = ''
}

async function setCreateMode(mode) {
  if (createMode.value === mode || creating.value) return
  createMode.value = mode
  createError.value = ''
  yamlError.value = ''
  await nextTick()
  if (mode === 'yaml') yamlInput.value?.focus()
  else nameInput.value?.focus()
}

async function validateYaml() {
  const yaml = yamlText.value
  if (!yaml.trim() || validating.value || creating.value) return
  validating.value = true
  yamlError.value = ''
  try {
    const response = await api.validateLabYaml({ yaml })
    yamlValidation.value = normalizeValidation(response)
  } catch (exception) {
    yamlError.value = exception.message
    yamlValidation.value = null
  } finally {
    validating.value = false
  }
}

function normalizeValidation(response) {
  const data = response?.data || {}
  return {
    valid: data.valid === true,
    errors: Array.isArray(data.errors) ? data.errors : [],
    warnings: Array.isArray(data.warnings) ? data.warnings : [],
    counts: data.counts && typeof data.counts === 'object' ? data.counts : null
  }
}

async function createFromYaml() {
  if (creating.value) return
  const yaml = yamlText.value
  if (!yaml.trim()) {
    yamlError.value = 'Paste lab YAML before creating the project.'
    return
  }
  creating.value = true
  yamlError.value = ''
  try {
    const file = new File([yaml], 'lab.yaml', { type: 'application/yaml' })
    const response = await api.importLab(file)
    const lab = response?.data
    const warnings = Array.isArray(response?.meta?.warnings) ? response.meta.warnings : []
    if (newFolder.value.trim() && lab?.id) await api.moveLab(lab.id, newFolder.value.trim())
    creating.value = false
    closeCreate()
    notify('success', warnings.length
      ? `Lab created from YAML with ${warnings.length} warning(s)`
      : 'Lab created from YAML')
    if (lab?.id) await router.push({ name: 'editor', params: { id: lab.id } })
    else await load()
  } catch (exception) {
    creating.value = false
    yamlError.value = exception.message
  }
}

watch(yamlText, () => {
  yamlValidation.value = null
  yamlError.value = ''
})

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
    if (lab?.id) await router.push({ name: 'editor', params: { id: lab.id } })
    else await load()
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
  if (!/\.(unl|ya?ml)$/i.test(file.name || '')) {
    error.value = 'Only .unl, .yaml, or .yml files can be imported.'
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
