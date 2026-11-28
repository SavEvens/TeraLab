<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="system-view">
    <div class="page-head">
      <h1>System</h1>
      <div class="spacer"></div>
      <button class="ghost" @click="loadAll">Refresh</button>
    </div>

    <div class="system-tabs" role="tablist">
      <button v-for="tab in tabs" :key="tab" role="tab" :aria-selected="active === tab" :class="{ active: active === tab }" @click="selectTab(tab)">{{ tab }}</button>
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
      <div class="account-block">
        <h2>Account</h2>
        <p class="hint">Change the password for your own account.</p>
        <div class="row">
          <button type="button" @click="passwordOpen = true">Change password</button>
        </div>
      </div>
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

        <h2>Retention and limits</h2>
        <p class="hint">
          Retention and backup caps are stored settings. The maintenance action removes expired rows and
          expired trash; backup restore rejects archives over these caps.
        </p>
        <div class="field-grid">
          <label>Login attempt retention (days)
            <input
              v-model.number="settingsForm.retention_login_attempts_days"
              type="number"
              step="1"
              :min="limitBounds.retention_login_attempts_days.min"
              :max="limitBounds.retention_login_attempts_days.max"
              aria-label="Login attempt retention (days)"
            />
          </label>
          <label>Audit log retention (days)
            <input
              v-model.number="settingsForm.retention_audit_log_days"
              type="number"
              step="1"
              :min="limitBounds.retention_audit_log_days.min"
              :max="limitBounds.retention_audit_log_days.max"
              aria-label="Audit log retention (days)"
            />
          </label>
          <label>Finished job retention (days)
            <input
              v-model.number="settingsForm.retention_jobs_days"
              type="number"
              step="1"
              :min="limitBounds.retention_jobs_days.min"
              :max="limitBounds.retention_jobs_days.max"
              aria-label="Finished job retention (days)"
            />
          </label>
          <label>Max finished jobs kept
            <input
              v-model.number="settingsForm.retention_jobs_max_rows"
              type="number"
              step="100"
              :min="limitBounds.retention_jobs_max_rows.min"
              :max="limitBounds.retention_jobs_max_rows.max"
              aria-label="Max finished jobs kept"
            />
          </label>
          <label>Maintenance interval (seconds)
            <input
              v-model.number="settingsForm.maintenance_interval_seconds"
              type="number"
              step="60"
              :min="limitBounds.maintenance_interval_seconds.min"
              :max="limitBounds.maintenance_interval_seconds.max"
              aria-label="Maintenance interval (seconds)"
            />
          </label>
          <label>Max backup archive (bytes)
            <input
              v-model.number="settingsForm.backup_max_archive_bytes"
              type="number"
              step="1048576"
              :min="limitBounds.backup_max_archive_bytes.min"
              :max="limitBounds.backup_max_archive_bytes.max"
              aria-label="Max backup archive (bytes)"
            />
            <small class="hint">{{ bytes(settingsForm.backup_max_archive_bytes) }}</small>
          </label>
          <label>Max backup entries
            <input
              v-model.number="settingsForm.backup_max_entries"
              type="number"
              step="1"
              :min="limitBounds.backup_max_entries.min"
              :max="limitBounds.backup_max_entries.max"
              aria-label="Max backup entries"
            />
          </label>
          <label>Max uncompressed backup (bytes)
            <input
              v-model.number="settingsForm.backup_max_uncompressed_bytes"
              type="number"
              step="1048576"
              :min="limitBounds.backup_max_uncompressed_bytes.min"
              :max="limitBounds.backup_max_uncompressed_bytes.max"
              aria-label="Max uncompressed backup (bytes)"
            />
            <small class="hint">{{ bytes(settingsForm.backup_max_uncompressed_bytes) }}</small>
          </label>
          <label>Max backup database (bytes)
            <input
              v-model.number="settingsForm.backup_max_database_bytes"
              type="number"
              step="1048576"
              :min="limitBounds.backup_max_database_bytes.min"
              :max="limitBounds.backup_max_database_bytes.max"
              aria-label="Max backup database (bytes)"
            />
            <small class="hint">{{ bytes(settingsForm.backup_max_database_bytes) }}</small>
          </label>
        </div>
        <div class="row">
          <button type="submit" :disabled="!canAdmin || !settingsLoaded || saving || !limitSettingsValid">{{ saving ? 'Saving…' : 'Save settings' }}</button>
          <span class="hint">Session and upload limits apply to new requests immediately.</span>
        </div>
      </form>

      <div v-if="canAdmin" class="account-block maintenance-block">
        <h2>Maintenance</h2>
        <p class="hint">
          Removes expired sessions, old login attempts, audit rows, finished jobs, excess snapshots, and
          expired trash. The scheduler runs it about every {{ settingsForm.maintenance_interval_seconds }} seconds.
        </p>
        <div class="row">
          <button
            type="button"
            data-test="run-maintenance"
            :disabled="maintenanceRunning"
            @click="runMaintenance"
          >
            {{ maintenanceRunning ? 'Running…' : 'Run maintenance' }}
          </button>
          <span class="hint">Runs immediately and reports what it removed.</span>
        </div>
      </div>
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
        <p class="hint" data-test="backup-limits">
          Restore limits: archive up to {{ bytes(settingsForm.backup_max_archive_bytes) }},
          {{ settingsForm.backup_max_entries }} entries,
          {{ bytes(settingsForm.backup_max_uncompressed_bytes) }} uncompressed, and a database up to
          {{ bytes(settingsForm.backup_max_database_bytes) }}.
        </p>
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

    <section v-show="active === 'Demo'" class="panel settings-panel demo-panel">
      <form @submit.prevent="saveDemoSettings">
        <h2>Demo access</h2>
        <div class="field-grid">
          <label>Demo mode
            <select v-model.number="settingsForm.demo_enabled" aria-label="Demo mode">
              <option :value="1">Enabled</option>
              <option :value="0">Disabled</option>
            </select>
          </label>
          <label>Landing for signed-out visitors
            <select v-model="settingsForm.demo_landing" aria-label="Landing page for signed-out visitors">
              <option value="login">Login page</option>
              <option value="demo">Demo page</option>
            </select>
          </label>
        </div>
        <p class="hint">Guests get ephemeral identities; no visitor account is created.</p>

        <h2>Guest limits</h2>
        <div class="field-grid">
          <label>Max guest sessions
            <input v-model.number="settingsForm.demo_max_guests" type="number" min="1" max="1000" aria-label="Max guest sessions" />
          </label>
          <label>Guest lifetime (hours)
            <input v-model.number="settingsForm.demo_guest_ttl_hours" type="number" min="1" max="720" aria-label="Guest lifetime (hours)" />
          </label>
          <label>Guest idle timeout (hours)
            <input v-model.number="settingsForm.demo_guest_idle_hours" type="number" min="1" max="72" aria-label="Guest idle timeout (hours)" />
          </label>
          <label>Max nodes per guest lab
            <input v-model.number="settingsForm.demo_max_nodes_per_lab" type="number" min="1" max="16" aria-label="Max nodes per guest lab" />
          </label>
          <label>Max RAM per node (MB)
            <input v-model.number="settingsForm.demo_max_ram_per_node" type="number" min="64" max="4096" aria-label="Max RAM per node (MB)" />
          </label>
          <label>Host memory floor (MB)
            <input v-model.number="settingsForm.demo_memory_floor_mb" type="number" min="256" max="65536" aria-label="Host memory floor (MB)" />
          </label>
          <label>Idle lab wipe (minutes)
            <input v-model.number="settingsForm.demo_idle_wipe_minutes" type="number" min="15" max="1440" aria-label="Idle lab wipe (minutes)" />
          </label>
          <label>Guest sweep interval (seconds)
            <input v-model.number="settingsForm.demo_sweep_interval" type="number" min="60" max="3600" aria-label="Guest sweep interval (seconds)" />
          </label>
          <label>Max labs per guest
            <input
              v-model.number="settingsForm.demo_max_labs_per_guest"
              type="number"
              step="1"
              :min="demoCapBounds.demo_max_labs_per_guest.min"
              :max="demoCapBounds.demo_max_labs_per_guest.max"
              aria-label="Max labs per guest"
            />
          </label>
          <label>Max lab size per guest (bytes)
            <input
              v-model.number="settingsForm.demo_max_lab_bytes_per_guest"
              type="number"
              step="1048576"
              :min="demoCapBounds.demo_max_lab_bytes_per_guest.min"
              :max="demoCapBounds.demo_max_lab_bytes_per_guest.max"
              aria-label="Max lab size per guest (bytes)"
            />
            <small class="hint">{{ bytes(settingsForm.demo_max_lab_bytes_per_guest) }}</small>
          </label>
          <label>Max snapshots per lab
            <input
              v-model.number="settingsForm.demo_max_snapshots_per_lab"
              type="number"
              step="1"
              :min="demoCapBounds.demo_max_snapshots_per_lab.min"
              :max="demoCapBounds.demo_max_snapshots_per_lab.max"
              aria-label="Max snapshots per lab"
            />
          </label>
          <label>Max tokens per user
            <input
              v-model.number="settingsForm.demo_max_tokens_per_user"
              type="number"
              step="1"
              :min="demoCapBounds.demo_max_tokens_per_user.min"
              :max="demoCapBounds.demo_max_tokens_per_user.max"
              aria-label="Max tokens per user"
            />
          </label>
        </div>
        <p class="hint">
          Guests always get the smallest caps. Lab bytes are checked against the guest cap before a lab is
          written, and snapshots and API tokens are capped per lab and per user.
        </p>

        <h2>Allowed templates</h2>
        <p class="hint">Guests can only add nodes from the selected templates.</p>
        <div class="demo-templates" role="group" aria-label="Allowed templates">
          <div v-for="template in demoTemplates" :key="template.id" class="check-row">
            <input
              :id="`demo-template-${template.id}`"
              v-model="demoTemplatesSelected"
              type="checkbox"
              :value="template.id"
            />
            <label :for="`demo-template-${template.id}`">{{ template.name }}</label>
          </div>
        </div>
        <p v-if="demoTemplatesError" class="error">{{ demoTemplatesError }}</p>
        <p v-else-if="!demoTemplates.length" class="hint">No templates available.</p>

        <h2>Turnstile</h2>
        <div class="field-grid">
          <label>Turnstile
            <select v-model.number="settingsForm.turnstile_enabled" aria-label="Turnstile">
              <option :value="1">Enabled</option>
              <option :value="0">Disabled</option>
            </select>
          </label>
          <label>Site key
            <input v-model="settingsForm.turnstile_site_key" aria-label="Turnstile site key" autocomplete="off" maxlength="128" />
          </label>
          <label>Hostname
            <input
              v-model="settingsForm.turnstile_hostname"
              aria-label="Turnstile hostname"
              autocomplete="off"
              maxlength="255"
              placeholder="optional"
            />
          </label>
          <label>Secret key
            <span class="secret-field">
              <input
                v-model="turnstileSecret"
                type="password"
                aria-label="Turnstile secret key"
                autocomplete="new-password"
                placeholder="leave blank to keep"
                maxlength="128"
              />
              <span v-if="secretConfigured" class="badge">configured</span>
            </span>
          </label>
        </div>
        <p v-if="secretConfigured" class="hint">A secret is configured. Leave the field empty to keep it; typing a value replaces it.</p>
        <p class="turnstile-warning" role="note">
          The shipped defaults are Cloudflare's public testing keys. Replace the site and secret keys before
          exposing the demo publicly.
        </p>
        <TurnstileTestKeyNotice v-if="turnstileTestKeys" />

        <div class="row">
          <button type="submit" :disabled="!canAdmin || !settingsLoaded || saving || !demoCapsValid">{{ saving ? 'Saving…' : 'Save demo settings' }}</button>
          <span class="hint">
            The guest cap and Turnstile apply now; the node/template/RAM limits and idle/sweep
            policy are stored settings and are enforced in the next demo steps.
          </span>
        </div>
      </form>

      <section class="demo-status" data-test="demo-status" aria-labelledby="demo-status-title">
        <h2 id="demo-status-title">Sweeper</h2>
        <p class="hint">Guests and guest labs are reclaimed lazily; the sweeper also enforces the host memory floor.</p>
        <p v-if="demoStatusError" class="error" role="alert">{{ demoStatusError }}</p>
        <div class="demo-status-grid">
          <article class="stat-card">
            <span>Last sweep</span>
            <strong data-test="demo-last-sweep">{{ lastSweepLabel }}</strong>
          </article>
          <article class="stat-card">
            <span>Available memory</span>
            <strong data-test="demo-memory">{{ memoryLabel }}</strong>
          </article>
          <article class="stat-card">
            <span>Active guests</span>
            <strong data-test="demo-guests">{{ demoStatus?.guests_active ?? '—' }}</strong>
          </article>
          <article class="stat-card">
            <span>Demo nodes running</span>
            <strong data-test="demo-nodes">{{ demoStatus?.demo_nodes_running ?? '—' }}</strong>
          </article>
          <article class="stat-card">
            <span>Guest labs</span>
            <strong data-test="demo-guest-labs">{{ demoStatus?.guest_labs ?? '—' }}</strong>
          </article>
          <article class="stat-card">
            <span>Guest lab bytes</span>
            <strong data-test="demo-guest-bytes">{{ guestLabBytesLabel }}</strong>
          </article>
          <article class="stat-card">
            <span>Guest snapshots</span>
            <strong data-test="demo-guest-snapshots">{{ demoStatus?.guest_snapshots ?? '—' }}</strong>
          </article>
        </div>
        <div class="row">
          <button type="button" data-test="demo-sweep" :disabled="!canAdmin || sweeping" @click="sweepNow">
            {{ sweeping ? 'Sweeping…' : 'Sweep now' }}
          </button>
          <span class="hint">Runs immediately. Expired guests, idle guest labs, and newest guest nodes over the memory floor are reclaimed.</span>
        </div>
      </section>
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
            <td>
              <div class="row-actions">
                <button class="ghost" @click="openEditUser(user)">Edit</button>
                <button class="ghost" @click="openResetPassword(user)">Reset password</button>
                <button class="ghost" @click="forceLogout(user)">Force logout</button>
                <button class="danger" @click="openDeleteUser(user)">Delete</button>
              </div>
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
          <label>Expires on (optional)
            <span class="expiry-field">
              <input v-model="addUser.form.expiration" type="date" aria-label="Expires on" />
              <button type="button" class="ghost" aria-label="Clear expiration" @click="addUser.form.expiration = ''">Clear</button>
            </span>
          </label>
          <p class="hint">Empty means never. The account expires at the end of the selected day in local time.</p>
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
          <label>Expires on
            <span class="expiry-field">
              <input v-model="editUser.form.expiration" type="date" aria-label="Expires on" />
              <button type="button" class="ghost" aria-label="Clear expiration" @click="editUser.form.expiration = ''">Clear</button>
            </span>
          </label>
          <p class="hint">Empty means never. The account expires at the end of the selected day in local time.</p>
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

    <section v-show="active === 'About'" class="system-about">
      <AboutView embedded />
    </section>

    <PasswordChangeDialog :open="passwordOpen" @close="passwordOpen = false" />
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { session } from '../stores/session'
import { notify } from '../stores/toasts'
import PasswordChangeDialog from '../components/PasswordChangeDialog.vue'
import TurnstileTestKeyNotice from '../components/TurnstileTestKeyNotice.vue'
import AboutView from './AboutView.vue'

const DEMO_INT_KEYS = [
  'demo_enabled', 'demo_max_guests', 'demo_guest_ttl_hours', 'demo_guest_idle_hours',
  'demo_max_nodes_per_lab', 'demo_max_ram_per_node', 'demo_memory_floor_mb',
  'demo_idle_wipe_minutes', 'demo_sweep_interval', 'turnstile_enabled'
]
// Mirrors SettingsService::INT_BOUNDS. The UI never sends a value outside these
// ranges; the API remains the authority on the final value.
const demoCapBounds = {
  demo_max_labs_per_guest: { min: 1, max: 20, fallback: 3 },
  demo_max_lab_bytes_per_guest: { min: 1048576, max: 268435456, fallback: 20971520 },
  demo_max_snapshots_per_lab: { min: 1, max: 20, fallback: 3 },
  demo_max_tokens_per_user: { min: 1, max: 50, fallback: 5 }
}
// Retention and backup caps share the same INT_BOUNDS contract.
const limitBounds = {
  retention_login_attempts_days: { min: 1, max: 3650, fallback: 30 },
  retention_audit_log_days: { min: 1, max: 3650, fallback: 90 },
  retention_jobs_days: { min: 1, max: 3650, fallback: 30 },
  retention_jobs_max_rows: { min: 100, max: 1000000, fallback: 1000 },
  maintenance_interval_seconds: { min: 60, max: 86400, fallback: 3600 },
  backup_max_archive_bytes: { min: 1048576, max: 10737418240, fallback: 2147483648 },
  backup_max_entries: { min: 1, max: 1000000, fallback: 10000 },
  backup_max_uncompressed_bytes: { min: 1048576, max: 42949672960, fallback: 4294967296 },
  backup_max_database_bytes: { min: 1048576, max: 10737418240, fallback: 1073741824 }
}
const LIMIT_DEFAULTS = Object.fromEntries(
  Object.entries(limitBounds).map(([key, bounds]) => [key, bounds.fallback])
)
const DEMO_DEFAULTS = {
  demo_enabled: 0,
  demo_landing: 'login',
  demo_max_guests: 50,
  demo_guest_ttl_hours: 48,
  demo_guest_idle_hours: 6,
  demo_max_nodes_per_lab: 5,
  demo_allowed_templates: 'vpcs',
  demo_max_ram_per_node: 1024,
  demo_memory_floor_mb: 2048,
  demo_idle_wipe_minutes: 120,
  demo_sweep_interval: 300,
  demo_max_labs_per_guest: 3,
  demo_max_lab_bytes_per_guest: 20971520,
  demo_max_snapshots_per_lab: 3,
  demo_max_tokens_per_user: 5,
  turnstile_enabled: 0,
  turnstile_site_key: '3x00000000000000000000FF',
  turnstile_hostname: ''
}

const route = useRoute()
const router = useRouter()
const host = ref(null)
const settingsForm = ref({
  ...DEMO_DEFAULTS,
  ...LIMIT_DEFAULTS,
  timezone: 'UTC',
  console_default: 'telnet',
  session_ttl: 3600,
  max_upload_bytes: 33554432
})
const saving = ref(false)
const settingsLoaded = ref(false)
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
const demoTemplates = ref([])
const demoTemplatesLoaded = ref(false)
const demoTemplatesError = ref('')
const demoTemplatesSelected = ref([])
const turnstileSecret = ref('')
const secretConfigured = ref(false)
const turnstileTestKeys = ref(false)
const demoStatus = ref(null)
const demoStatusError = ref('')
const sweeping = ref(false)
const maintenanceRunning = ref(false)
const canAdmin = computed(() => session.user?.role === 'admin')
const passwordOpen = ref(false)
const tabs = computed(() => {
  const list = []
  if (canAdmin.value) list.push('Host')
  list.push('Settings', 'Logs', 'Backup', 'Tokens')
  if (canAdmin.value) {
    list.push('Users')
    list.push('Demo')
  }
  list.push('About')
  return list
})

const defaultTab = computed(() => (canAdmin.value ? 'Host' : 'Settings'))

function matchTab(value) {
  const wanted = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return tabs.value.find((tab) => tab.toLowerCase() === wanted) || defaultTab.value
}

const active = ref(matchTab(route.query.tab))

function selectTab(tab) {
  active.value = tab
  if (route.query.tab === tab) return
  router.replace({ query: { ...route.query, tab } })
}

watch(() => route.query.tab, (value) => {
  const next = matchTab(value)
  if (next !== active.value) active.value = next
})
const cpuUsage = computed(() => {
  const value = host.value?.cpu_usage_percent
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`
})
const lastSweepLabel = computed(() => {
  const value = demoStatus.value?.last_sweep_at
  const seconds = Number(value)
  if (value === null || value === undefined || !Number.isFinite(seconds)) return 'never'
  return new Date(seconds * 1000).toLocaleString()
})
const memoryLabel = computed(() => {
  const value = demoStatus.value?.mem_available_mb
  const megabytes = Number(value)
  if (value === null || value === undefined || !Number.isFinite(megabytes)) return 'unknown'
  return `${Math.round(megabytes)} MB`
})
const guestLabBytesLabel = computed(() => {
  const value = demoStatus.value?.guest_lab_bytes
  if (value === null || value === undefined) return '—'
  return bytes(value)
})
const editingSelf = computed(() => !!editUser.value.user && isSelf(editUser.value.user))
const timezones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC']

function splitTemplateCsv(value) {
  const raw = Array.isArray(value) ? value.join(',') : String(value ?? '')
  return [...new Set(raw.split(',').map((part) => part.trim()).filter(Boolean))]
}

function normaliseBoundedInt(bounds, value) {
  const number = Number(value)
  if (value === null || value === undefined || value === '' || !Number.isFinite(number)) return bounds.fallback
  return Math.min(bounds.max, Math.max(bounds.min, Math.trunc(number)))
}

function normaliseBounds(map, form) {
  for (const [key, bounds] of Object.entries(map)) form[key] = normaliseBoundedInt(bounds, form[key])
}

function withinBound(bounds, value) {
  const number = Number(value)
  return Number.isInteger(number) && number >= bounds.min && number <= bounds.max
}

function boundsValid(map, form) {
  return Object.entries(map).every(([key, bounds]) => withinBound(bounds, form[key]))
}

const demoCapsValid = computed(() => boundsValid(demoCapBounds, settingsForm.value))
const limitSettingsValid = computed(() => boundsValid(limitBounds, settingsForm.value))

function templateCsv(values) {
  return [...new Set((values || []).map((value) => String(value).trim()).filter(Boolean))].join(',')
}

function applySettingsData(data) {
  const next = { ...DEMO_DEFAULTS, ...LIMIT_DEFAULTS, ...(data || {}) }
  for (const key of DEMO_INT_KEYS) next[key] = Number(next[key])
  normaliseBounds(demoCapBounds, next)
  normaliseBounds(limitBounds, next)
  if (!['login', 'demo'].includes(next.demo_landing)) next.demo_landing = DEMO_DEFAULTS.demo_landing
  if (typeof next.turnstile_hostname !== 'string') next.turnstile_hostname = ''
  secretConfigured.value = typeof next.turnstile_secret_key === 'string' && next.turnstile_secret_key !== ''
  delete next.turnstile_secret_key
  next.demo_allowed_templates = templateCsv(splitTemplateCsv(next.demo_allowed_templates))
  settingsForm.value = next
  demoTemplatesSelected.value = splitTemplateCsv(next.demo_allowed_templates)
}

async function loadDemoTemplates() {
  if (!canAdmin.value || demoTemplatesLoaded.value) return
  demoTemplatesError.value = ''
  try {
    const list = (await api.templates()).data || []
    demoTemplates.value = list.map((template) => ({
      id: String(template.id),
      name: template.name || String(template.id)
    }))
    demoTemplatesLoaded.value = true
    const available = new Set(demoTemplates.value.map((template) => template.id))
    demoTemplatesSelected.value = [...new Set(demoTemplatesSelected.value)].filter((id) => available.has(id))
  } catch (exception) {
    demoTemplatesError.value = exception?.message || 'Templates could not be loaded.'
  }
}

async function loadDemoStatus() {
  if (!canAdmin.value) return
  demoStatusError.value = ''
  try {
    demoStatus.value = (await api.demoStatus()).data || null
  } catch (exception) {
    demoStatusError.value = exception?.message || 'Sweep status could not be loaded.'
  }
}

function plural(value, singular, pluralForm) {
  return `${value} ${value === 1 ? singular : pluralForm}`
}

function sweepSummary(data) {
  const count = (value) => Number(value ?? 0) || 0
  const failed = count(data?.labs_failed)
  const summary = `Sweep complete: ${plural(count(data?.guests_purged), 'guest', 'guests')} purged, `
    + `${plural(count(data?.labs_wiped), 'lab', 'labs')} wiped, `
    + `${plural(count(data?.nodes_reaped), 'node', 'nodes')} stopped; `
    + `${plural(count(data?.guest_files_removed), 'guest file', 'guest files')} and `
    + `${plural(count(data?.snapshots_removed), 'snapshot', 'snapshots')} removed`
  return failed > 0
    ? { kind: 'warn', message: `${summary}; ${plural(failed, 'lab', 'labs')} could not be wiped and will be retried.` }
    : { kind: 'success', message: `${summary}.` }
}

async function sweepNow() {
  if (!canAdmin.value || sweeping.value) return
  sweeping.value = true
  try {
    const data = (await api.demoSweep()).data || {}
    const summary = sweepSummary(data)
    notify(summary.kind, summary.message)
    await loadDemoStatus()
  } catch (exception) {
    toastError(exception)
  } finally {
    sweeping.value = false
  }
}

function maintenanceSummary(data) {
  const count = (value) => Number(value ?? 0) || 0
  return `Maintenance complete: ${plural(count(data?.sessions_deleted), 'session', 'sessions')}, `
    + `${plural(count(data?.login_attempts_deleted), 'login attempt', 'login attempts')}, `
    + `${plural(count(data?.audit_log_deleted), 'audit row', 'audit rows')}, `
    + `${plural(count(data?.jobs_deleted), 'job', 'jobs')}, `
    + `${plural(count(data?.snapshots_deleted), 'snapshot', 'snapshots')}, and `
    + `${plural(count(data?.trash_deleted), 'trash lab', 'trash labs')} removed.`
}

async function runMaintenance() {
  if (!canAdmin.value || maintenanceRunning.value) return
  maintenanceRunning.value = true
  try {
    const data = (await api.maintenance()).data || {}
    notify('success', maintenanceSummary(data))
  } catch (exception) {
    toastError(exception)
  } finally {
    maintenanceRunning.value = false
  }
}

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
  const seconds = Number(value)
  if (value === null || value === undefined || value === '' || !Number.isFinite(seconds) || seconds < 0) return 'never'
  return new Date(seconds * 1000).toLocaleDateString()
}

function toDateInput(value) {
  const seconds = Number(value)
  if (value === null || value === undefined || value === '' || !Number.isFinite(seconds) || seconds < 0) return ''
  const when = new Date(seconds * 1000)
  const pad = (part) => String(part).padStart(2, '0')
  return `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}`
}

function toUnixSeconds(value) {
  const text = String(value ?? '').trim()
  if (!text) return -1
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(text) ? `${text}T23:59:59` : text
  const milliseconds = Date.parse(normalized)
  return Number.isFinite(milliseconds) ? Math.floor(milliseconds / 1000) : -1
}

function toastError(exception) {
  notify('error', exception?.message || 'Request failed')
}

async function loadHost() {
  // Host status is administrator-only. A viewer or editor must never request
  // it, even when the URL points at the Host tab.
  if (!canAdmin.value) {
    host.value = null
    return
  }
  host.value = (await api.host()).data
}

async function loadSettings() {
  applySettingsData((await api.settings()).data)
  settingsLoaded.value = true
}

// Health is public and advisory; a failed probe must not blank the Settings
// page. It tells us whether the API is still using Cloudflare's test secret.
async function loadTurnstileFlags() {
  try {
    const flags = await api.health()
    turnstileTestKeys.value = flags?.turnstile?.test_keys === true
  } catch {
    turnstileTestKeys.value = false
  }
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
    await Promise.all([loadHost(), loadSettings(), loadLogs(), loadTokens(), loadTurnstileFlags()])
  } catch (exception) {
    error.value = exception.message
  }
  if (canAdmin.value && (active.value === 'Users' || usersLoaded.value)) await refreshUsers()
  if (canAdmin.value && active.value === 'Demo') {
    loadDemoTemplates()
    loadDemoStatus()
  }
}

function settingsPayload() {
  return { ...settingsForm.value, demo_allowed_templates: templateCsv(demoTemplatesSelected.value) }
}

async function saveSettings() {
  if (!limitSettingsValid.value) {
    error.value = 'Retention and limit settings must stay within the allowed range.'
    return
  }
  saving.value = true
  error.value = ''
  try {
    applySettingsData((await api.saveSettings(settingsPayload())).data)
    notify('success', 'Settings saved')
  } catch (exception) {
    error.value = exception.message
  } finally {
    saving.value = false
  }
}

async function saveDemoSettings() {
  if (!demoCapsValid.value) {
    error.value = 'Demo limits must stay within the allowed range.'
    return
  }
  saving.value = true
  error.value = ''
  const typedSecret = turnstileSecret.value.trim() !== ''
  try {
    const payload = settingsPayload()
    if (typedSecret) payload.turnstile_secret_key = turnstileSecret.value
    applySettingsData((await api.saveSettings(payload)).data)
    if (typedSecret) secretConfigured.value = true
    turnstileSecret.value = ''
    notify('success', 'Demo settings saved')
  } catch (exception) {
    const message = exception?.message || 'Request failed'
    error.value = message
    notify('error', message)
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
      expiration: toDateInput(user.expiration),
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
  if (tab === 'Demo' && canAdmin.value) {
    loadDemoTemplates()
    loadDemoStatus()
  }
})

onMounted(loadAll)
</script>
