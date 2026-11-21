<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="system-view">
    <div class="page-head">
      <span class="crumbs">Workspace /</span>
      <h1>System</h1>
      <div class="spacer"></div>
      <button class="ghost" @click="loadAll">Refresh</button>
    </div>

    <div class="system-tabs" role="tablist">
      <button v-for="tab in tabs" :key="tab" role="tab" :aria-selected="active === tab" :class="{ active: active === tab }" @click="active = tab">{{ tab }}</button>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <section v-show="active === 'Host'" class="system-grid">
      <article class="stat-card">
        <span>Load 1 / 5 / 15</span>
        <strong>{{ host?.load1 ?? '—' }} / {{ host?.load5 ?? '—' }} / {{ host?.load15 ?? '—' }}</strong>
      </article>
      <article class="stat-card cpu-usage">
        <span>CPU usage</span>
        <strong>{{ cpuUsage }}</strong>
      </article>
      <article class="stat-card">
        <span>Disk free</span>
        <strong>{{ bytes(host?.disk_free_bytes) }} / {{ bytes(host?.disk_total_bytes) }}</strong>
      </article>
      <article class="stat-card">
        <span>Active nodes</span>
        <strong>{{ host?.nodes?.length ?? 0 }}</strong>
      </article>
      <article class="stat-card wide">
        <span>Engines</span>
        <ul class="engine-list">
          <li v-for="engine in host?.engines || []" :key="engine.name">
            <span class="dot" :class="engine.available ? 'ok' : 'neutral'"></span>
            <strong>{{ engine.name }}</strong>
            <small>{{ engine.available ? engine.version : 'not installed' }}</small>
          </li>
        </ul>
      </article>
      <article class="stat-card wide">
        <span>Physical interfaces</span>
        <ul class="engine-list">
          <li v-for="iface in host?.interfaces || []" :key="iface.name">
            <span class="dot" :class="iface.up ? 'ok' : 'neutral'"></span>
            <strong>{{ iface.name }}</strong>
            <small>{{ iface.mac || 'no mac' }} · {{ iface.addresses?.join(', ') || 'no address' }}</small>
          </li>
        </ul>
      </article>
    </section>

    <section v-show="active === 'Settings'" class="panel settings-panel">
      <form @submit.prevent="saveSettings">
        <div class="field-grid">
          <label>Timezone
            <select v-model="settingsForm.timezone">
              <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
            </select>
          </label>
          <label>Console default
            <select v-model="settingsForm.console_default">
              <option value="telnet">telnet</option>
              <option value="vnc">vnc</option>
            </select>
          </label>
          <label>Session TTL (seconds)
            <input v-model.number="settingsForm.session_ttl" type="number" min="300" max="86400" />
          </label>
          <label>Max upload (bytes)
            <input v-model.number="settingsForm.max_upload_bytes" type="number" min="1048576" max="10737418240" />
          </label>
        </div>
        <div class="row">
          <button type="submit" :disabled="!canAdmin || saving">{{ saving ? 'Saving…' : 'Save settings' }}</button>
          <span class="hint">Session and upload limits apply to new requests immediately.</span>
        </div>
      </form>
    </section>

    <section v-show="active === 'Logs'" class="panel">
      <div class="toolbar">
        <label>Source
          <select v-model="logSource" @change="loadLogs">
            <option value="api">api</option>
            <option value="agent">agent</option>
          </select>
        </label>
        <button class="ghost" @click="loadLogs">Refresh</button>
        <span class="hint">{{ logLines.length }} lines</span>
      </div>
      <pre class="log-view">{{ logLines.join('\n') || 'No log lines yet.' }}</pre>
    </section>

    <section v-show="active === 'Backup'" class="system-grid">
      <article class="stat-card wide">
        <span>Backup</span>
        <p class="hint">Includes the SQLite database, labs, custom templates, settings, and image metadata.</p>
        <button :disabled="!canAdmin" @click="downloadBackup">Download backup</button>
      </article>
      <article class="stat-card wide">
        <span>Restore</span>
        <p class="hint">Restores labs, custom templates, settings, and image metadata. Database restore stays manual for safety.</p>
        <input type="file" accept=".zip" aria-label="Backup file" @change="restoreFile = $event.target.files?.[0] || null" />
        <button class="ghost" :disabled="!canAdmin || !restoreFile" @click="restoreBackup">Restore backup</button>
        <p v-if="restoreResult" class="hint mono">{{ JSON.stringify(restoreResult) }}</p>
      </article>
    </section>

    <section v-show="active === 'Tokens'" class="panel">
      <form class="token-form" @submit.prevent="createToken">
        <label>Name <input v-model="tokenName" placeholder="automation" required /></label>
        <label>Scope
          <select v-model="tokenScope">
            <option value="read">read-only</option>
            <option value="write">read and write</option>
          </select>
        </label>
        <label>Expires in days
          <input v-model.number="tokenDays" type="number" min="1" max="365" />
        </label>
        <button type="submit">Create token</button>
      </form>
      <p v-if="newToken" class="token-once">
        Copy this token now; it is shown once:
        <code>{{ newToken }}</code>
      </p>
      <table class="lab-table">
        <thead><tr><th>Name</th><th>Scopes</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
        <tbody>
          <tr v-for="token in tokens" :key="token.id">
            <td>{{ token.name }}</td>
            <td>{{ token.scopes.join(', ') }}</td>
            <td>{{ date(token.created_at) }}</td>
            <td>{{ token.last_used_at ? date(token.last_used_at) : '—' }}</td>
            <td>{{ token.expires_at ? date(token.expires_at) : 'never' }}</td>
            <td><button class="danger" @click="revokeToken(token.id)">Revoke</button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!tokens.length" class="empty">No API tokens.</p>
    </section>

    <section v-show="active === 'Users'" class="panel users-panel">
      <div class="toolbar">
        <button @click="openAddUser">Add user</button>
        <button class="ghost" @click="refreshUsers">Refresh</button>
        <span class="hint">{{ users.length }} users</span>
      </div>
      <table class="lab-table users-table">
        <thead>
          <tr><th>User</th><th>Role</th><th>Expires</th><th>Sessions</th><th>Last activity</th><th>Labs</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="user in users" :key="user.id" :data-user="user.username">
            <td>
              <strong>{{ user.username }}</strong>
              <span v-if="isSelf(user)" class="badge you">(you)</span>
              <small>{{ userDetails(user) }}</small>
            </td>
            <td><span class="badge role">{{ user.role }}</span></td>
            <td>{{ expirationLabel(user.expiration) }}</td>
            <td>{{ user.session_count ?? 0 }}</td>
            <td>{{ user.last_seen_at ? date(user.last_seen_at) : 'never' }}</td>
            <td>{{ user.lab_count ?? 0 }}</td>
            <td class="actions">
              <button class="ghost" @click="openEditUser(user)">Edit</button>
              <button class="ghost" @click="openResetPassword(user)">Reset password</button>
              <button class="ghost" @click="forceLogout(user)">Force logout</button>
              <button class="danger" @click="openDeleteUser(user)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!users.length" class="empty">No users.</p>
    </section>

    <div v-if="addUser.open" class="modal-backdrop" @click.self="closeAddUser">
      <div class="modal users-modal add-user-modal" role="dialog" aria-modal="true" aria-labelledby="add-user-title">
        <h2 id="add-user-title">Add user</h2>
        <template v-if="addUser.generated">
          <p class="hint">The account exists. Give this password to the user; it is shown once and cannot be recovered.</p>
          <p class="token-once" role="status">
            Copy this password now; it is shown once:
            <code>{{ addUser.generated }}</code>
          </p>
          <div class="row"><button type="button" @click="closeAddUser">Close</button></div>
        </template>
        <form v-else @submit.prevent="createUser">
          <label>Username
            <input v-model="addUser.form.username" aria-label="Username" autocomplete="off" required />
          </label>
          <label>Role
            <select v-model="addUser.form.role" aria-label="Role">
              <option value="admin">admin</option>
              <option value="editor">editor</option>
              <option value="user">user</option>
            </select>
          </label>
          <div class="field-grid">
            <label>Email (optional)
              <input v-model="addUser.form.email" type="email" aria-label="Email" autocomplete="off" />
            </label>
            <label>Display name (optional)
              <input v-model="addUser.form.name" aria-label="Display name" autocomplete="off" />
            </label>
          </div>
          <label>Expires (optional)
            <input v-model="addUser.form.expiration" type="datetime-local" aria-label="Expires" />
          </label>
          <p class="hint">Leave the expiration empty for an account that never expires.</p>
          <label>Password (optional)
            <input v-model="addUser.form.password" type="password" aria-label="Password" autocomplete="new-password" />
          </label>
          <p class="hint">Leave the password empty to generate a one-time password, shown once after the account is created.</p>
          <div class="row">
            <button type="button" class="ghost" :disabled="addUser.saving" @click="closeAddUser">Cancel</button>
            <button type="submit" :disabled="addUser.saving || !addUser.form.username.trim()">
              {{ addUser.saving ? 'Creating…' : 'Create user' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="editUser.open" class="modal-backdrop" @click.self="closeEditUser">
      <div class="modal users-modal edit-user-modal" role="dialog" aria-modal="true" aria-labelledby="edit-user-title">
        <h2 id="edit-user-title">Edit {{ editUser.user.username }}</h2>
        <form @submit.prevent="saveUser">
          <div class="field-grid">
            <label>Email
              <input v-model="editUser.form.email" type="email" aria-label="Email" autocomplete="off" />
            </label>
            <label>Display name
              <input v-model="editUser.form.name" aria-label="Display name" autocomplete="off" />
            </label>
          </div>
          <label>Expires
            <input v-model="editUser.form.expiration" type="datetime-local" aria-label="Expires" />
          </label>
          <p class="hint">Empty means never. Expiration is enforced at login and on every session check.</p>
          <label>Role
            <select v-model="editUser.form.role" aria-label="Role" :disabled="editingSelf">
              <option value="admin">admin</option>
              <option value="editor">editor</option>
              <option value="user">user</option>
            </select>
          </label>
          <p v-if="editingSelf" class="hint">You cannot change your own role. The last administrator cannot be demoted.</p>
          <div class="row">
            <button type="button" class="ghost" :disabled="editUser.saving" @click="closeEditUser">Cancel</button>
            <button type="submit" :disabled="editUser.saving">{{ editUser.saving ? 'Saving…' : 'Save' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="resetPassword.open" class="modal-backdrop" @click.self="closeResetPassword">
      <div class="modal users-modal reset-password-modal" role="dialog" aria-modal="true" aria-labelledby="reset-password-title">
        <h2 id="reset-password-title">Reset password for {{ resetPassword.user.username }}</h2>
        <template v-if="resetPassword.generated">
          <p class="hint">All sessions of this user were revoked. Give this password to the user; it is shown once and cannot be recovered.</p>
          <p class="token-once" role="status">
            Copy this password now; it is shown once:
            <code>{{ resetPassword.generated }}</code>
          </p>
          <div class="row"><button type="button" @click="closeResetPassword">Close</button></div>
        </template>
        <form v-else @submit.prevent="resetPasswordFor">
          <label>New password (optional)
            <input v-model="resetPassword.password" type="password" aria-label="New password" autocomplete="new-password" />
          </label>
          <p class="hint">Leave empty to generate a one-time password. Resetting revokes all of the user's sessions.</p>
          <div class="row">
            <button type="button" class="ghost" :disabled="resetPassword.saving" @click="closeResetPassword">Cancel</button>
            <button type="submit" :disabled="resetPassword.saving">
              {{ resetPassword.saving ? 'Resetting…' : 'Reset password' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="deleteUser.open" class="modal-backdrop" @click.self="closeDeleteUser">
      <div class="modal users-modal delete-user-modal" role="dialog" aria-modal="true" aria-labelledby="delete-user-title">
        <h2 id="delete-user-title">Delete {{ deleteUser.user.username }}?</h2>
        <p class="hint">
          The account and its sessions are removed. Their labs are kept and become ownerless (visible to
          administrators) unless you choose to delete them.
        </p>
        <div class="check-row">
          <input id="delete-user-labs" v-model="deleteUser.deleteLabs" type="checkbox" aria-label="Also delete their labs" />
          <label for="delete-user-labs">Also delete their labs</label>
        </div>
        <p class="hint">
          Deleting labs moves them to the 30-day trash. Deletion is refused while any of their labs has
          running nodes.
        </p>
        <div class="row">
          <button type="button" class="ghost" :disabled="deleteUser.saving" @click="closeDeleteUser">Cancel</button>
          <button type="button" class="danger" :disabled="deleteUser.saving" @click="removeUser">
            {{ deleteUser.saving ? 'Deleting…' : 'Delete user' }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'
import { session } from '../stores/session'
import { notify } from '../stores/toasts'

const active = ref('Host')
const host = ref(null)
const settingsForm = ref({ timezone: 'UTC', console_default: 'telnet', session_ttl: 3600, max_upload_bytes: 33554432 })
const saving = ref(false)
const error = ref('')
const logSource = ref('api')
const logLines = ref([])
const tokens = ref([])
const tokenName = ref('')
const tokenScope = ref('read')
const tokenDays = ref(90)
const newToken = ref('')
const restoreFile = ref(null)
const restoreResult = ref(null)
const users = ref([])
const usersLoaded = ref(false)
const addUser = ref(emptyAddUser())
const editUser = ref({ open: false, user: null, saving: false, form: emptyUserForm() })
const resetPassword = ref({ open: false, user: null, saving: false, password: '', generated: '' })
const deleteUser = ref({ open: false, user: null, saving: false, deleteLabs: false })
const canAdmin = computed(() => session.user?.role === 'admin')
const tabs = computed(() => {
  const list = ['Host', 'Settings', 'Logs', 'Backup', 'Tokens']
  if (canAdmin.value) list.push('Users')
  return list
})
const cpuUsage = computed(() => {
  const value = host.value?.cpu_usage_percent
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`
})
const editingSelf = computed(() => !!editUser.value.user && isSelf(editUser.value.user))
const timezones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC']

function emptyUserForm() {
  return { email: '', name: '', expiration: '', role: 'user' }
}

function emptyAddUser() {
  return { open: false, saving: false, generated: '', form: { username: '', role: 'user', email: '', name: '', expiration: '', password: '' } }
}

function bytes(value) {
  const size = Number(value || 0)
  if (size >= 1073741824) return `${(size / 1073741824).toFixed(1)} GiB`
  if (size >= 1048576) return `${(size / 1048576).toFixed(1)} MiB`
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KiB`
  return `${size} B`
}

function date(value) {
  return value ? new Date(value * 1000).toLocaleString() : '—'
}

function isSelf(user) {
  return !!session.user && String(user?.id) === String(session.user.id)
}

function userDetails(user) {
  const parts = [user.name, user.email].filter((part) => typeof part === 'string' && part.trim() !== '')
  return parts.length ? parts.join(' · ') : 'no name or email'
}

function expirationLabel(value) {
  if (value === null || value === undefined || Number(value) < 0) return 'never'
  return date(Number(value))
}

function toDateTimeLocal(value) {
  const seconds = Number(value)
  if (!Number.isFinite(seconds) || seconds < 0) return ''
  const when = new Date(seconds * 1000)
  const pad = (part) => String(part).padStart(2, '0')
  return `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}T${pad(when.getHours())}:${pad(when.getMinutes())}`
}

function toUnixSeconds(value) {
  const milliseconds = new Date(value).getTime()
  return Number.isFinite(milliseconds) ? Math.floor(milliseconds / 1000) : -1
}

function toastError(exception) {
  notify('error', exception?.message || 'Request failed')
}

async function loadHost() {
  host.value = (await api.host()).data
}

async function loadSettings() {
  settingsForm.value = (await api.settings()).data
}

async function loadLogs() {
  logLines.value = (await api.logs(logSource.value)).data.lines
}

async function loadTokens() {
  tokens.value = (await api.tokens()).data
}

async function refreshUsers() {
  if (!canAdmin.value) return
  try {
    users.value = (await api.users()).data || []
    usersLoaded.value = true
    error.value = ''
  } catch (exception) {
    error.value = exception.message
  }
}

async function loadAll() {
  error.value = ''
  try {
    await Promise.all([loadHost(), loadSettings(), loadLogs(), loadTokens()])
  } catch (exception) {
    error.value = exception.message
  }
  if (canAdmin.value && (active.value === 'Users' || usersLoaded.value)) await refreshUsers()
}

async function saveSettings() {
  saving.value = true
  error.value = ''
  try {
    settingsForm.value = (await api.saveSettings(settingsForm.value)).data
    notify('success', 'Settings saved')
  } catch (exception) {
    error.value = exception.message
  } finally {
    saving.value = false
  }
}

async function downloadBackup() {
  try {
    const response = await fetch('/api/v2/system/backup', { credentials: 'same-origin' })
    if (!response.ok) throw new Error(`Backup failed: HTTP ${response.status}`)
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `teralab-backup-${new Date().toISOString().slice(0, 10)}.zip`
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (exception) {
    error.value = exception.message
  }
}

async function restoreBackup() {
  if (!restoreFile.value) return
  try {
    restoreResult.value = (await api.restoreBackup(restoreFile.value)).data
    notify('success', 'Backup restored')
    await loadAll()
  } catch (exception) {
    error.value = exception.message
  }
}

async function createToken() {
  try {
    const data = (await api.createToken({
      name: tokenName.value,
      scopes: tokenScope.value === 'write' ? ['read', 'write'] : ['read'],
      expires_in: tokenDays.value > 0 ? tokenDays.value * 86400 : null
    })).data
    newToken.value = data.token
    tokenName.value = ''
    await loadTokens()
  } catch (exception) {
    error.value = exception.message
  }
}

async function revokeToken(id) {
  try {
    await api.revokeToken(id)
    if (newToken.value) newToken.value = ''
    await loadTokens()
  } catch (exception) {
    error.value = exception.message
  }
}

function openAddUser() {
  addUser.value = { ...emptyAddUser(), open: true }
}

function closeAddUser() {
  if (addUser.value.saving) return
  addUser.value = emptyAddUser()
}

async function createUser() {
  const dialog = addUser.value
  const username = dialog.form.username.trim()
  if (!username || dialog.saving) return
  dialog.saving = true
  try {
    const payload = { username, role: dialog.form.role }
    const email = dialog.form.email.trim()
    const name = dialog.form.name.trim()
    if (email) payload.email = email
    if (name) payload.name = name
    if (dialog.form.expiration) payload.expiration = toUnixSeconds(dialog.form.expiration)
    if (dialog.form.password) payload.password = dialog.form.password
    const data = (await api.createUser(payload)).data || {}
    notify('success', `User ${username} created`)
    dialog.saving = false
    await refreshUsers()
    if (typeof data.password === 'string' && data.password !== '') dialog.generated = data.password
    else closeAddUser()
  } catch (exception) {
    dialog.saving = false
    toastError(exception)
  }
}

function openEditUser(user) {
  editUser.value = {
    open: true,
    user,
    saving: false,
    form: {
      email: user.email || '',
      name: user.name || '',
      expiration: toDateTimeLocal(user.expiration),
      role: user.role
    }
  }
}

function closeEditUser() {
  if (editUser.value.saving) return
  editUser.value = { open: false, user: null, saving: false, form: emptyUserForm() }
}

async function saveUser() {
  const dialog = editUser.value
  if (!dialog.user || dialog.saving) return
  dialog.saving = true
  try {
    const payload = {
      email: dialog.form.email.trim(),
      name: dialog.form.name.trim(),
      expiration: dialog.form.expiration ? toUnixSeconds(dialog.form.expiration) : -1
    }
    if (!editingSelf.value) payload.role = dialog.form.role
    await api.updateUser(dialog.user.id, payload)
    notify('success', `User ${dialog.user.username} updated`)
    dialog.saving = false
    closeEditUser()
    await refreshUsers()
  } catch (exception) {
    dialog.saving = false
    const message = exception?.message || 'Request failed'
    const hint = /admin/i.test(message) && /(role|demote|last)/i.test(message)
      ? ' You cannot change your own role, and the last administrator cannot be demoted.'
      : ''
    notify('error', `${message}${hint}`)
  }
}

function openResetPassword(user) {
  resetPassword.value = { open: true, user, saving: false, password: '', generated: '' }
}

function closeResetPassword() {
  if (resetPassword.value.saving) return
  resetPassword.value = { open: false, user: null, saving: false, password: '', generated: '' }
}

async function resetPasswordFor() {
  const dialog = resetPassword.value
  if (!dialog.user || dialog.saving) return
  dialog.saving = true
  try {
    const payload = {}
    if (dialog.password) payload.password = dialog.password
    const data = (await api.resetUserPassword(dialog.user.id, payload)).data || {}
    notify('success', `Password reset for ${dialog.user.username}`)
    dialog.saving = false
    await refreshUsers()
    if (typeof data.password === 'string' && data.password !== '') dialog.generated = data.password
    else closeResetPassword()
  } catch (exception) {
    dialog.saving = false
    toastError(exception)
  }
}

async function forceLogout(user) {
  if (!window.confirm(`Force logout ${user.username}? All of their sessions will be revoked.`)) return
  try {
    const data = (await api.revokeUserSessions(user.id)).data || {}
    const revoked = Number(data.revoked || 0)
    notify('success', `${revoked} session${revoked === 1 ? '' : 's'} revoked for ${user.username}`)
    await refreshUsers()
  } catch (exception) {
    toastError(exception)
  }
}

function openDeleteUser(user) {
  deleteUser.value = { open: true, user, saving: false, deleteLabs: false }
}

function closeDeleteUser() {
  if (deleteUser.value.saving) return
  deleteUser.value = { open: false, user: null, saving: false, deleteLabs: false }
}

async function removeUser() {
  const dialog = deleteUser.value
  if (!dialog.user || dialog.saving) return
  dialog.saving = true
  try {
    const data = (await api.deleteUser(dialog.user.id, { delete_labs: dialog.deleteLabs })).data || {}
    const trashed = Number(data.labs_trashed || 0)
    notify('success', `User ${dialog.user.username} deleted${trashed ? `; ${trashed} lab(s) moved to trash` : ''}`)
    dialog.saving = false
    closeDeleteUser()
    await refreshUsers()
  } catch (exception) {
    dialog.saving = false
    toastError(exception)
  }
}

watch(active, (tab) => {
  if (tab === 'Users' && canAdmin.value) refreshUsers()
})

onMounted(loadAll)
</script>
