<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->
<template>
  <div v-if="open" class="modal-backdrop" @click.self="close" @keydown.esc="close">
    <div class="modal yaml-panel" role="dialog" aria-modal="true" aria-labelledby="yaml-panel-title">
      <h2 id="yaml-panel-title">YAML source</h2>
      <p class="hint">This lab was created from YAML. Applying replaces the canvas topology and configs with this source.</p>

      <p class="yaml-marker">
        <strong>{{ filename || 'lab.yaml' }}</strong>
        <span v-if="sha256" class="mono">sha256:{{ shaShort }}</span>
        <span>applied at revision {{ markerRevision }}</span>
      </p>

      <p v-if="stale" class="yaml-stale" role="status">
        The canvas has changed since this YAML was applied; applying replaces those changes.
      </p>
      <p v-if="stale && unchanged" class="yaml-reapply" role="status">
        Applying the stored YAML will replace the canvas changes made since it was applied.
      </p>
      <p v-if="running" class="error yaml-running" role="status">
        Stop and wipe the lab before applying YAML.
      </p>

      <label>YAML source
        <textarea
          ref="sourceInput"
          v-model="text"
          class="yaml-source-text"
          aria-label="Lab YAML source"
          spellcheck="false"
          @keydown.ctrl.enter.prevent="apply"
          @keydown.meta.enter.prevent="apply"
        ></textarea>
      </label>
      <p class="yaml-stats">
        {{ charCount }} characters · {{ lineCount }} lines
        <span v-if="unchanged">· unchanged</span>
      </p>

      <div v-if="validation" class="yaml-validation" aria-live="polite">
        <ul v-if="validation.errors.length" class="error yaml-errors" role="alert">
          <li v-for="(entry, index) in validation.errors" :key="index">{{ entry }}</li>
        </ul>
        <p v-else-if="validation.valid" class="ok">Valid YAML</p>
        <p v-else class="error" role="alert">The YAML is not valid.</p>
        <ul v-if="validation.warnings.length" class="yaml-warnings" aria-label="YAML warnings">
          <li v-for="(warning, index) in validation.warnings" :key="index">
            <strong>{{ warning.type }}</strong>
            <span>{{ warning.node ? `${warning.node}: ` : '' }}{{ warning.message }}</span>
          </li>
        </ul>
        <p v-if="countsLabel" class="yaml-counts">{{ countsLabel }}</p>
      </div>

      <p v-if="validateError" class="error" role="alert">{{ validateError }}</p>
      <p v-if="applyError" class="error yaml-apply-error" role="alert">{{ applyError }}</p>

      <div class="row">
        <button type="button" class="ghost" :disabled="busy" @click="close">Close</button>
        <button
          type="button"
          class="ghost yaml-validate"
          :disabled="!text.trim() || validating || busy"
          @click="validate"
        >{{ validating ? 'Validating…' : 'Validate' }}</button>
        <button
          type="button"
          class="yaml-apply"
          :disabled="applyDisabled"
          :title="applyDisabledReason"
          @click="apply"
        >{{ busy ? 'Applying…' : 'Apply' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { api } from '../api'
import { notify } from '../stores/toasts'

const props = defineProps({
  open: { type: Boolean, default: false },
  labId: { type: String, default: '' },
  source: { type: String, default: '' },
  filename: { type: String, default: 'lab.yaml' },
  sha256: { type: String, default: '' },
  markerRevision: { type: Number, default: 0 },
  revision: { type: Number, default: 0 },
  nodeStates: { type: Object, default: () => ({}) },
  running: { type: Boolean, default: false }
})
const emit = defineEmits(['applied', 'close'])

const text = ref('')
const validation = ref(null)
const validating = ref(false)
const busy = ref(false)
const validateError = ref('')
const applyError = ref('')
const sourceInput = ref(null)

const charCount = computed(() => text.value.length)
const lineCount = computed(() => (text.value ? text.value.split('\n').length : 0))
const unchanged = computed(() => text.value === props.source)
const running = computed(() => props.running || Object.values(props.nodeStates || {}).some((state) => state === 'running'))
const stale = computed(() => Number(props.markerRevision) < Number(props.revision))
const shaShort = computed(() => String(props.sha256 || '').slice(0, 12))
const countsLabel = computed(() => {
  const counts = validation.value?.counts
  if (!counts) return ''
  const nodes = Number.isFinite(Number(counts.nodes)) ? Number(counts.nodes) : 0
  const networks = Number.isFinite(Number(counts.networks)) ? Number(counts.networks) : 0
  const links = Number.isFinite(Number(counts.links)) ? Number(counts.links) : 0
  return `${nodes} nodes · ${networks} networks · ${links} links`
})
const applyDisabled = computed(() => (
  busy.value ||
  !text.value.trim() ||
  running.value ||
  validation.value?.valid === false ||
  (unchanged.value && !stale.value)
))
const applyDisabledReason = computed(() => {
  if (running.value) return 'Stop and wipe the lab before applying YAML.'
  if (!text.value.trim()) return 'The YAML source is empty.'
  if (unchanged.value && !stale.value) return 'The YAML source has not changed.'
  if (validation.value?.valid === false) return 'Fix the validation errors before applying.'
  return ''
})

watch(() => props.open, async (open) => {
  if (!open) return
  text.value = props.source
  validation.value = null
  validateError.value = ''
  applyError.value = ''
  await nextTick()
  sourceInput.value?.focus()
}, { immediate: true })

watch(text, () => {
  validation.value = null
  validateError.value = ''
  applyError.value = ''
})

function close() {
  if (busy.value) return
  emit('close')
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

async function validate() {
  if (!text.value.trim() || validating.value || busy.value) return
  validating.value = true
  validateError.value = ''
  try {
    const response = await api.validateLabYaml({ yaml: text.value, filename: props.filename || 'lab.yaml' })
    validation.value = normalizeValidation(response)
  } catch (exception) {
    validateError.value = exception.message
    validation.value = null
  } finally {
    validating.value = false
  }
}

async function apply() {
  if (applyDisabled.value || busy.value) return
  busy.value = true
  applyError.value = ''
  try {
    const response = await api.applyLabYaml(props.labId, {
      yaml: text.value,
      filename: props.filename || 'lab.yaml',
      revision: Number(props.revision)
    })
    const warnings = Array.isArray(response?.meta?.warnings) ? response.meta.warnings : []
    notify('success', warnings.length
      ? `YAML applied with ${warnings.length} warning(s)`
      : 'YAML applied')
    emit('applied', response?.data || null)
    emit('close')
  } catch (exception) {
    applyError.value = exception.message
  } finally {
    busy.value = false
  }
}
</script>
