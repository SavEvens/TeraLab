<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div v-if="open" class="modal-backdrop" @click.self="close">
    <div class="modal password-modal" role="dialog" aria-modal="true" aria-labelledby="password-modal-title">
      <h2 id="password-modal-title">Change password</h2>
      <form @submit.prevent="submit">
        <label>Current password
          <input v-model="current" type="password" aria-label="Current password" autocomplete="current-password" required />
        </label>
        <label>New password
          <input v-model="next" type="password" aria-label="New password" autocomplete="new-password" required minlength="12" />
        </label>
        <label>Confirm new password
          <input v-model="confirm" type="password" aria-label="Confirm new password" autocomplete="new-password" required />
        </label>
        <p class="hint">At least 12 characters, mixing character classes. Your other sessions will be signed out; this session stays signed in.</p>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <div class="row">
          <button type="button" class="ghost" :disabled="busy" @click="close">Cancel</button>
          <button type="submit" :disabled="busy || !current || !next || !confirm">
            {{ busy ? 'Saving…' : 'Change password' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { api } from '../api'
import { notify } from '../stores/toasts'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['close'])

const current = ref('')
const next = ref('')
const confirm = ref('')
const error = ref('')
const busy = ref(false)

watch(() => props.open, (open) => {
  if (open) reset()
})

function reset() {
  current.value = ''
  next.value = ''
  confirm.value = ''
  error.value = ''
}

function close() {
  if (busy.value) return
  reset()
  emit('close')
}

async function submit() {
  if (busy.value) return
  if (next.value !== confirm.value) {
    error.value = 'The new passwords do not match.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    await api.changePassword({ current_password: current.value, new_password: next.value })
    busy.value = false
    notify('success', 'Password changed')
    reset()
    emit('close')
  } catch (exception) {
    error.value = exception.message
  } finally {
    busy.value = false
  }
}
</script>
