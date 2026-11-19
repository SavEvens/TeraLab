<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="images-view">
    <div class="toolbar">
      <h1>Images</h1>
      <span class="badge">{{ images.length }} stored</span>
      <button class="ghost" @click="load">Refresh</button>
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
        <tr><th>Name</th><th>Kind</th><th>Format</th><th>Size</th><th>Template</th><th>SHA-256</th><th>Added</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <tr v-for="image in images" :key="image.id">
          <td>{{ image.name }}<small>{{ image.path }}</small></td>
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
            <button v-if="canEdit" class="ghost" @click="rename(image)">Rename</button>
            <button v-if="canEdit" class="danger" @click="remove(image)">Delete</button>
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
const fileInput = ref(null)
const canEdit = computed(() => ['admin', 'editor'].includes(session.user?.role || 'user'))

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

async function rename(image) {
  const value = window.prompt('Image name', image.name)
  if (value === null || !value.trim()) return
  try {
    await api.renameImage(image.id, value.trim())
    notify('success', 'Image renamed')
    await load()
  } catch (exception) {
    error.value = exception.message
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
  if (!window.confirm(`Delete image “${image.name}”?`)) return
  try {
    await api.deleteImage(image.id)
    notify('success', 'Image deleted')
    await load()
  } catch (exception) {
    error.value = exception.message
  }
}

onMounted(load)
</script>
