<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="images-view">
    <div class="toolbar">
      <h1>Images</h1>
      <span class="badge">{{ images.length }} stored</span>
      <button class="ghost" @click="load">Refresh</button>
      <button v-if="canAdmin" class="ghost" @click="openAdopt">Adopt images…</button>
    </div>

    <form v-if="canEdit" class="upload" @submit.prevent="upload">
      <label>Kind
        <select v-model="kind" aria-label="Image kind">
          <option value="qemu">qemu (qcow2)</option>
          <option value="iso">iso</option>
          <option value="iol">iol (bin)</option>
          <option value="dynamips">dynamips (bin)</option>
        </select>
      </label>
      <label>Name (optional)
        <input v-model="name" placeholder="base" aria-label="Image name" />
      </label>
      <label>File
        <input ref="fileInput" type="file" aria-label="Image file" required />
      </label>
      <button type="submit" :disabled="uploading">{{ uploading ? `Uploading ${uploadProgress}%` : 'Upload' }}</button>
      <div v-if="uploading" class="progress" role="progressbar" :aria-valuenow="uploadProgress" aria-valuemin="0" aria-valuemax="100">
        <i :style="{ width: uploadProgress + '%' }"></i>
      </div>
    </form>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <table class="lab-table">
      <thead>
        <tr><th>Name</th><th>Reference</th><th>Kind</th><th>Format</th><th>Size</th><th>Template</th><th>SHA-256</th><th>Added</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <tr v-for="image in images" :key="image.id">
          <td>
            {{ image.name }}
            <span v-if="image.external" class="badge linked" title="linked from the legacy addons tree; no copy in the store">linked</span>
            <small>{{ image.path }}</small>
          </td>
          <td><code>{{ image.reference || '—' }}</code></td>
          <td>{{ image.kind }}</td>
          <td>{{ image.format || '—' }}</td>
          <td>{{ bytes(image.size) }}</td>
          <td>
            <select :value="image.template || ''" :disabled="!canEdit" @change="mapTemplate(image, $event.target.value)">
              <option value="">—</option>
              <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.id }}</option>
            </select>
          </td>
          <td><code>{{ (image.sha256 || '').slice(0, 12) }}</code></td>
          <td>{{ new Date(image.created_at * 1000).toLocaleDateString() }}</td>
          <td class="actions">
            <button class="ghost" @click="inspect(image)">Details</button>
            <button v-if="canEdit" class="ghost" @click="openRename(image)">Rename</button>
            <button v-if="canEdit" class="danger" @click="remove(image)">{{ image.external ? 'Unlink' : 'Delete' }}</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!images.length" class="empty">No images yet. Upload a qcow2, ISO, IOL, or Dynamips file.</p>

    <div v-if="details" class="modal-backdrop" @click.self="details = null">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Image details">
        <h2>{{ details.name }}</h2>
        <dl class="template-preview">
          <dt>kind</dt><dd>{{ details.kind }}</dd>
          <dt>reference</dt><dd class="mono">{{ details.reference || '—' }}</dd>
          <dt>path</dt><dd>{{ details.path }}</dd>
          <dt>format</dt><dd>{{ details.format || '—' }}</dd>
          <dt>size</dt><dd>{{ bytes(details.size) }}</dd>
          <dt>virtual size</dt><dd>{{ bytes(details.virtual_size) }}</dd>
          <dt>backing file</dt><dd>{{ details.backing_file || '—' }}</dd>
          <dt>template</dt><dd>{{ details.template || '—' }}</dd>
          <dt>sha256</dt><dd class="mono">{{ details.sha256 }}</dd>
        </dl>
        <div class="row"><button class="ghost" @click="details = null">Close</button></div>
      </div>
    </div>

    <div v-if="renameDialog.open" class="modal-backdrop" @click.self="closeRename">
      <div class="modal rename-modal" role="dialog" aria-modal="true" aria-label="Rename image">
        <h2>Rename image</h2>
        <p class="hint">
          The display name is what lists show. The reference is the single name lab nodes store.
        </p>
        <form @submit.prevent="saveRename">
          <label>Display name
            <input
              v-model="renameDialog.name"
              aria-label="Display name"
              autocomplete="off"
              :aria-invalid="renameNameError ? 'true' : undefined"
              aria-describedby="rename-name-error"
            />
          </label>
          <p v-if="renameNameError" id="rename-name-error" class="error" role="alert">{{ renameNameError }}</p>
          <label>Reference
            <input
              v-model="renameDialog.reference"
              aria-label="Reference"
              autocomplete="off"
              spellcheck="false"
              :aria-invalid="renameReferenceError ? 'true' : undefined"
              aria-describedby="rename-reference-hint rename-reference-error"
            />
          </label>
          <p id="rename-reference-hint" class="hint">The value lab nodes store in the .unl file.</p>
          <p v-if="renameReferenceError" id="rename-reference-error" class="error" role="alert">{{ renameReferenceError }}</p>
          <div class="row">
            <button type="button" class="ghost" :disabled="renameDialog.saving" @click="closeRename">Cancel</button>
            <button type="submit" :disabled="renameDialog.saving || !!renameNameError || !!renameReferenceError">
              {{ renameDialog.saving ? 'Saving…' : 'Save' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="adopt.open" class="modal-backdrop" @click.self="closeAdopt">
      <div class="modal adopt-modal" role="dialog" aria-modal="true" aria-label="Adopt legacy images">
        <h2>Adopt legacy images</h2>
        <p class="hint">
          Register files that already exist under a legacy addons tree. Nothing is copied: TeraLab
          links the file into its own image store and leaves the legacy tree untouched.
        </p>

        <div class="adopt-root">
          <label>Legacy addons root
            <input v-model="adopt.root" aria-label="Legacy addons root" spellcheck="false" />
          </label>
          <button class="ghost" :disabled="!!adopt.busy" @click="scanAddons">
            {{ adopt.busy === 'scan' ? 'Scanning…' : 'Scan' }}
          </button>
        </div>

        <p v-if="error" class="error" role="alert">{{ error }}</p>

        <template v-if="adopt.entries.length">
          <dl class="adopt-summary" aria-label="Scan summary">
            <div><dt>new</dt><dd>{{ adopt.summary.new }}</dd></div>
            <div><dt>existing</dt><dd>{{ adopt.summary.existing }}</dd></div>
            <div><dt>duplicate</dt><dd>{{ adopt.summary.duplicate }}</dd></div>
            <div><dt>unreadable</dt><dd>{{ adopt.summary.unreadable }}</dd></div>
            <div><dt>skipped</dt><dd>{{ adopt.summary.skipped }}</dd></div>
          </dl>
          <p v-for="warning in adopt.warnings" :key="warning" class="hint adopt-warning">{{ warning }}</p>

          <div class="adopt-table-wrap">
            <table class="lab-table adopt-table">
              <thead>
                <tr>
                  <th>
                    <input
                      type="checkbox"
                      aria-label="Select all new images"
                      :checked="allNewSelected"
                      :disabled="!newEntries.length"
                      @change="toggleAll"
                    />
                  </th>
                  <th>File</th><th>Kind</th><th>Size</th><th>Reference</th><th>Template</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in adopt.entries" :key="entry.path">
                  <td>
                    <input
                      type="checkbox"
                      :aria-label="`Include ${entry.path}`"
                      :checked="adopt.selected.has(entry.path)"
                      :disabled="entry.status !== 'new'"
                      @change="toggleEntry(entry.path)"
                    />
                  </td>
                  <td>
                    <span class="mono">{{ entry.path }}</span>
                    <small v-if="entry.warnings?.length">{{ entry.warnings.join(' · ') }}</small>
                  </td>
                  <td>{{ entry.kind }}</td>
                  <td>{{ bytes(entry.size) }}</td>
                  <td><code>{{ entry.reference || '—' }}</code></td>
                  <td>
                    <select
                      :value="adopt.templates[entry.path] || ''"
                      :disabled="entry.status !== 'new'"
                      :aria-label="`Template for ${entry.path}`"
                      @change="adopt.templates[entry.path] = $event.target.value"
                    >
                      <option value="">—</option>
                      <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.id }}</option>
                    </select>
                  </td>
                  <td>
                    <span class="badge" :class="`status-${entry.status}`">{{ entry.status }}</span>
                    <small v-if="entry.error" class="error">{{ entry.error }}</small>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <p v-else-if="adopt.scanned" class="empty">No adoptable files found under this root.</p>

        <div v-if="adopt.results.length" class="adopt-results">
          <h3>Import result</h3>
          <p class="hint">{{ adoptedSummary }}</p>
          <ul>
            <li v-for="result in adopt.results" :key="result.path">
              <span class="badge" :class="`status-${result.status}`">{{ result.status }}</span>
              <span class="mono">{{ result.path }}</span>
              <span v-if="result.error" class="error">{{ result.error }}</span>
            </li>
          </ul>
        </div>

        <div class="row">
          <button class="ghost" :disabled="!!adopt.busy" @click="closeAdopt">Close</button>
          <button :disabled="!!adopt.busy || !adopt.selected.size" @click="importAddons">
            {{ adopt.busy === 'import' ? 'Importing…' : `Import ${adopt.selected.size}` }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api, uploadImageWithProgress } from '../api'
import { session } from '../stores/session'
import { notify } from '../stores/toasts'

const images = ref([])
const templates = ref([])
const kind = ref('qemu')
const name = ref('')
const uploading = ref(false)
const uploadProgress = ref(0)
const details = ref(null)
const error = ref('')
const renameDialog = ref({ open: false, image: null, name: '', reference: '', saving: false })
const fileInput = ref(null)
const canEdit = computed(() => ['admin', 'editor'].includes(session.user?.role || 'user'))
const canAdmin = computed(() => session.user?.role === 'admin')

const adopt = ref({
  open: false,
  root: '/opt/unetlab/addons',
  busy: '',
  scanned: false,
  entries: [],
  warnings: [],
  summary: { new: 0, existing: 0, duplicate: 0, unreadable: 0, skipped: 0 },
  selected: new Set(),
  templates: {},
  results: [],
  resultSummary: null
})

const newEntries = computed(() => adopt.value.entries.filter((entry) => entry.status === 'new'))
const allNewSelected = computed(() =>
  newEntries.value.length > 0 && newEntries.value.every((entry) => adopt.value.selected.has(entry.path))
)
const adoptedSummary = computed(() => {
  const summary = adopt.value.resultSummary
  if (!summary) return ''
  return `${summary.imported || 0} imported · ${summary.duplicate || 0} duplicate · ${summary.skipped || 0} skipped · ${summary.failed || 0} failed`
})

function bytes(value) {
  const size = Number(value || 0)
  if (size >= 1073741824) return `${(size / 1073741824).toFixed(2)} GiB`
  if (size >= 1048576) return `${(size / 1048576).toFixed(1)} MiB`
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KiB`
  return `${size} B`
}

async function load() {
  error.value = ''
  try {
    images.value = (await api.images()).data
    templates.value = (await api.templates()).data
  } catch (exception) {
    error.value = exception.message
  }
}

async function upload() {
  const file = fileInput.value?.files?.[0]
  if (!file) return
  uploading.value = true
  uploadProgress.value = 0
  error.value = ''
  try {
    const form = new FormData()
    form.append('kind', kind.value)
    if (name.value.trim()) form.append('name', name.value.trim())
    form.append('file', file)
    await uploadImageWithProgress(form, (value) => { uploadProgress.value = value })
    notify('success', 'Image uploaded')
    name.value = ''
    fileInput.value.value = ''
    await load()
  } catch (exception) {
    error.value = exception.message
  } finally {
    uploading.value = false
  }
}

function inspect(image) {
  details.value = image
}

// The API validates with PHP's strlen(), which counts UTF-8 bytes, not code
// points. Use the same measure so multi-byte names cannot pass here and fail
// server-side.
function utf8Length(value) {
  return new TextEncoder().encode(value).length
}

// Display name rules mirror ImageStore::rename(): non-empty after trimming,
// at most 128 UTF-8 bytes, no control characters.
const renameNameError = computed(() => {
  if (!renameDialog.value.open) return ''
  const name = renameDialog.value.name.trim()
  if (!name) return 'Display name is required.'
  if (utf8Length(name) > 128) return 'Display name must be 128 characters or fewer.'
  if (/[\x00-\x1f]/.test(name)) return 'Display name cannot contain control characters.'

  return ''
})

// Reference rules follow ImageReference::isPortable() (a single path segment,
// no slashes or "..", at most 128 UTF-8 bytes) but are stricter on purpose:
// interior whitespace and control characters are rejected too, so every value
// stays on the portable [A-Za-z0-9._-] character set. isPortable() only trims
// edges and would accept such values.
const renameReferenceError = computed(() => {
  if (!renameDialog.value.open) return ''
  const reference = renameDialog.value.reference.trim()
  if (!reference) return 'Reference is required.'
  if (/\s/.test(reference)) return 'Reference cannot contain spaces.'
  if (reference.includes('/') || reference.includes('\\')) return 'Reference must be a single name, without slashes.'
  if (reference.includes('..')) return 'Reference cannot contain “..”.'
  if (utf8Length(reference) > 128) return 'Reference must be 128 characters or fewer.'
  if (/[\x00-\x1f]/.test(reference)) return 'Reference cannot contain control characters.'

  return ''
})

function openRename(image) {
  error.value = ''
  renameDialog.value = {
    open: true,
    image,
    name: image.name || '',
    reference: image.reference || '',
    saving: false
  }
}

function closeRename() {
  if (renameDialog.value.saving) return
  renameDialog.value = { ...renameDialog.value, open: false }
}

async function saveRename() {
  const dialog = renameDialog.value
  if (!dialog.open || dialog.saving) return
  if (renameNameError.value || renameReferenceError.value) return
  const image = dialog.image
  const name = dialog.name.trim()
  const reference = dialog.reference.trim()
  const nameChanged = name !== (image.name || '')
  const referenceChanged = reference !== (image.reference || '')
  if (!nameChanged && !referenceChanged) {
    closeRename()
    return
  }

  dialog.saving = true
  let nameApplied = false
  try {
    // The two existing helpers share the PATCH endpoint; only send what
    // actually changed so an untouched field is never rewritten.
    if (nameChanged) {
      await api.renameImage(image.id, name)
      nameApplied = true
    }
    if (referenceChanged) await api.setImageReference(image.id, reference)
    dialog.saving = false
    notify('success', 'Image updated')
    closeRename()
    await load()
  } catch (exception) {
    dialog.saving = false
    notify('error', exception.message)
    if (nameApplied) {
      // The display name is already stored. Keep the dialog open with the
      // reference the user typed, but refresh the row behind it so a
      // half-applied rename is visible.
      dialog.image = { ...image, name }
      await load()
    }
  }
}

async function mapTemplate(image, template) {
  if (!template) return
  try {
    await api.mapImageTemplate(image.id, template)
    notify('success', 'Template mapped')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

async function remove(image) {
  const message = image.external
    ? `Unlink image “${image.name}”? The legacy file on disk is not deleted.`
    : `Delete image “${image.name}”?`
  if (!window.confirm(message)) return
  try {
    await api.deleteImage(image.id)
    notify('success', image.external ? 'Image unlinked' : 'Image deleted')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

function openAdopt() {
  error.value = ''
  adopt.value.open = true
}

function closeAdopt() {
  adopt.value.open = false
}

async function scanAddons() {
  const root = adopt.value.root.trim()
  if (!root) {
    error.value = 'Enter the legacy addons root to scan.'
    return
  }
  adopt.value.busy = 'scan'
  error.value = ''
  adopt.value.results = []
  adopt.value.resultSummary = null
  try {
    const data = (await api.importLegacyImages({ root, dry_run: true })).data || {}
    adopt.value.entries = Array.isArray(data.entries) ? data.entries : []
    adopt.value.warnings = Array.isArray(data.warnings) ? data.warnings : []
    adopt.value.summary = data.summary || { new: 0, existing: 0, duplicate: 0, unreadable: 0, skipped: 0 }
    adopt.value.scanned = true
    const choices = {}
    for (const entry of adopt.value.entries) {
      choices[entry.path] = entry.template || entry.template_suggestion || ''
    }
    adopt.value.templates = choices
    adopt.value.selected = new Set(newEntries.value.map((entry) => entry.path))
  } catch (exception) {
    error.value = exception.message
  } finally {
    adopt.value.busy = ''
  }
}

function toggleEntry(path) {
  const next = new Set(adopt.value.selected)
  if (next.has(path)) next.delete(path)
  else next.add(path)
  adopt.value.selected = next
}

function toggleAll(event) {
  const next = new Set(adopt.value.selected)
  for (const entry of newEntries.value) {
    if (event.target.checked) next.add(entry.path)
    else next.delete(entry.path)
  }
  adopt.value.selected = next
}

async function importAddons() {
  const files = [...adopt.value.selected]
  if (!files.length) return
  const overrides = {}
  for (const path of files) {
    const template = adopt.value.templates[path]
    if (template) overrides[path] = template
  }
  adopt.value.busy = 'import'
  error.value = ''
  try {
    const data = (await api.importLegacyImages({
      root: adopt.value.root.trim(),
      dry_run: false,
      files,
      templates: overrides
    })).data || {}
    adopt.value.results = Array.isArray(data.results) ? data.results : []
    adopt.value.resultSummary = data.summary || null
    adopt.value.selected = new Set()
    await load()
    const summary = data.summary || {}
    notify(
      summary.failed ? 'error' : 'success',
      `Adopted ${summary.imported || 0} image(s)${summary.failed ? `, ${summary.failed} failed` : ''}`
    )
  } catch (exception) {
    error.value = exception.message
  } finally {
    adopt.value.busy = ''
  }
}

onMounted(load)
</script>
