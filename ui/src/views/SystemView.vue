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
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { session } from '../stores/session'
import { notify } from '../stores/toasts'

const tabs = ['Host', 'Settings', 'Logs', 'Backup', 'Tokens']
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
const canAdmin = computed(() => session.user?.role === 'admin')
const cpuUsage = computed(() => {
  const value = host.value?.cpu_usage_percent
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`
})
const timezones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC']

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

async function loadAll() {
  error.value = ''
  try {
    await Promise.all([loadHost(), loadSettings(), loadLogs(), loadTokens()])
  } catch (exception) {
    error.value = exception.message
  }
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

onMounted(loadAll)
</script>
