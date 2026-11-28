// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

let csrfToken = ''

export class ApiError extends Error {
  constructor(status, message, details = {}, requestId = '') {
    super(message)
    this.status = status
    this.details = details
    this.requestId = requestId
  }
}

export function uploadImageWithProgress(form, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', '/api/v2/images')
    xhr.withCredentials = true
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken)
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100))
    }
    xhr.onerror = () => reject(new ApiError(0, 'Network error during upload'))
    xhr.onload = () => {
      let data = null
      try { data = xhr.responseText ? JSON.parse(xhr.responseText) : null } catch {}
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress(100)
        resolve(data)
        return
      }
      const requestId = typeof data?.request_id === 'string' ? data.request_id : ''
      const message = data?.message || `HTTP ${xhr.status}`
      reject(new ApiError(xhr.status, requestId ? `${message} (request ${requestId.slice(0, 8)})` : message, data?.details || {}, requestId))
    }
    xhr.send(form)
  })
}

export function setCsrf(token) {
  csrfToken = token || ''
}

async function request(path, { method = 'GET', body, raw = false, headers: extraHeaders } = {}) {
  const headers = { Accept: 'application/json', ...(extraHeaders || {}) }
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken
  let payload = body
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }
  const response = await fetch(`/api/v2${path}`, {
    method,
    credentials: 'same-origin',
    headers,
    body: payload
  })
  const text = await response.text()
  if (!response.ok) {
    let error = null
    try { error = text ? JSON.parse(text) : null } catch {}
    const requestId = typeof error?.request_id === 'string' ? error.request_id : ''
    const message = error?.message || `HTTP ${response.status}`
    throw new ApiError(
      response.status,
      requestId ? `${message} (request ${requestId.slice(0, 8)})` : message,
      error?.details || {},
      requestId
    )
  }
  if (raw) return text
  try {
    return text ? JSON.parse(text) : null
  } catch {
    throw new ApiError(response.status, 'Invalid server response')
  }
}

export const api = {
  health: () => request('/health'),
  version: () => request('/version'),
  // The operator token is sent in the header the API treats as authoritative
  // and repeated in the JSON body so either accepted contract works. It is
  // never persisted by the client.
  setup: (payload, setupToken = '') => request('/setup', {
    method: 'POST',
    body: setupToken ? { ...payload, setup_token: setupToken } : payload,
    headers: setupToken ? { 'X-TeraLab-Setup-Token': setupToken } : {}
  }),
  login: (payload) => request('/auth/login', { method: 'POST', body: payload }),
  guest: (payload) => request('/auth/guest', { method: 'POST', body: payload }),
  logout: () => request('/auth/logout', { method: 'POST' }),
  me: () => request('/auth/me'),
  changePassword: (payload) => request('/auth/password', { method: 'POST', body: payload }),
  users: () => request('/users'),
  createUser: (payload) => request('/users', { method: 'POST', body: payload }),
  updateUser: (id, payload) => request(`/users/${encodeURIComponent(id)}`, { method: 'PATCH', body: payload }),
  resetUserPassword: (id, payload) => request(`/users/${encodeURIComponent(id)}/password`, { method: 'POST', body: payload }),
  revokeUserSessions: (id) => request(`/users/${encodeURIComponent(id)}/sessions/revoke`, { method: 'POST' }),
  deleteUser: (id, payload) => request(`/users/${encodeURIComponent(id)}`, { method: 'DELETE', body: payload }),
  labs: () => request('/labs'),
  lab: (id) => request(`/labs/${encodeURIComponent(id)}`),
  createLab: (payload) => request('/labs', { method: 'POST', body: payload }),
  saveLab: (id, payload) => request(`/labs/${encodeURIComponent(id)}`, { method: 'PUT', body: payload }),
  deleteLab: (id) => request(`/labs/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  duplicateLab: (id) => request(`/labs/${encodeURIComponent(id)}/duplicate`, { method: 'POST' }),
  moveLab: (id, folder) => request(`/labs/${encodeURIComponent(id)}/move`, { method: 'POST', body: { folder } }),
  importLab: (file) => {
    const form = new FormData()
    form.append('file', file)
    return request('/labs/import', { method: 'POST', body: form })
  },
  validateLabYaml: (payload) => request('/labs/validate', { method: 'POST', body: payload }),
  applyLabYaml: (id, payload) => request(`/labs/${encodeURIComponent(id)}/apply`, { method: 'POST', body: payload }),
  exportLab: (id) => request(`/labs/${encodeURIComponent(id)}/export`, { raw: true }),
  startLab: (id) => request(`/labs/${encodeURIComponent(id)}/start`, { method: 'POST' }),
  stopLab: (id) => request(`/labs/${encodeURIComponent(id)}/stop`, { method: 'POST' }),
  wipeLab: (id) => request(`/labs/${encodeURIComponent(id)}/wipe`, { method: 'POST' }),
  labNodes: (id) => request(`/labs/${encodeURIComponent(id)}/nodes`),
  labNodeStart: (id, node) => request(`/labs/${encodeURIComponent(id)}/nodes/${encodeURIComponent(node)}/start`, { method: 'POST' }),
  labNodeStop: (id, node) => request(`/labs/${encodeURIComponent(id)}/nodes/${encodeURIComponent(node)}/stop`, { method: 'POST' }),
  labNodeWipe: (id, node) => request(`/labs/${encodeURIComponent(id)}/nodes/${encodeURIComponent(node)}/wipe`, { method: 'POST' }),
  labNodeConsole: (id, node) => request(`/labs/${encodeURIComponent(id)}/nodes/${encodeURIComponent(node)}/console`, { method: 'POST' }),
  snapshots: (id) => request(`/labs/${encodeURIComponent(id)}/snapshots`),
  createSnapshot: (id, name) => request(`/labs/${encodeURIComponent(id)}/snapshots`, { method: 'POST', body: { name } }),
  restoreSnapshot: (id, snapshot) => request(`/labs/${encodeURIComponent(id)}/snapshots/${encodeURIComponent(snapshot)}/restore`, { method: 'POST' }),
  deleteSnapshot: (id, snapshot) => request(`/labs/${encodeURIComponent(id)}/snapshots/${encodeURIComponent(snapshot)}`, { method: 'DELETE' }),
  host: () => request('/system/host'),
  settings: () => request('/system/settings'),
  saveSettings: (payload) => request('/system/settings', { method: 'PUT', body: payload }),
  demoStatus: () => request('/system/demo/status'),
  demoSweep: () => request('/system/demo/sweep', { method: 'POST' }),
  maintenance: () => request('/system/maintenance', { method: 'POST' }),
  logs: (source) => request(`/system/logs?source=${encodeURIComponent(source)}`),
  tokens: () => request('/system/tokens'),
  createToken: (payload) => request('/system/tokens', { method: 'POST', body: payload }),
  revokeToken: (id) => request(`/system/tokens/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  restoreBackup: (file) => {
    const form = new FormData()
    form.append('file', file)
    return request('/system/restore', { method: 'POST', body: form })
  },
  trash: () => request('/labs/trash'),
  restoreLab: (id) => request(`/labs/${encodeURIComponent(id)}/restore`, { method: 'POST' }),
  purgeLab: (id) => request(`/labs/trash/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  templates: () => request('/templates'),
  template: (id) => request(`/templates/${encodeURIComponent(id)}`),
  saveTemplate: (id, yaml) => request(`/templates/${encodeURIComponent(id)}`, { method: 'PUT', body: { yaml } }),
  deleteTemplate: (id) => request(`/templates/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  exportTemplate: (id) => request(`/templates/${encodeURIComponent(id)}/export`, { raw: true }),
  importTemplate: (file) => {
    const form = new FormData()
    form.append('file', file)
    return request('/templates/import', { method: 'POST', body: form })
  },
  duplicateTemplate: (id) => request(`/templates/${encodeURIComponent(id)}/duplicate`, { method: 'POST' }),
  images: () => request('/images'),
  dockerImages: () => request('/docker/images'),
  importLegacyImages: (payload) => request('/images/import', { method: 'POST', body: payload }),
  uploadImage: (form) => request('/images', { method: 'POST', body: form }),
  deleteImage: (id) => request(`/images/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  renameImage: (id, name) => request(`/images/${encodeURIComponent(id)}`, { method: 'PATCH', body: { name } }),
  setImageReference: (id, reference) => request(`/images/${encodeURIComponent(id)}`, { method: 'PATCH', body: { reference } }),
  mapImageTemplate: (id, template) => request(`/images/${encodeURIComponent(id)}/template`, { method: 'PUT', body: { template } }),
  engines: () => request('/engines'),
  job: (id) => request(`/jobs/${encodeURIComponent(id)}`)
}

export function wsUrl(path) {
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
  if (/^wss?:\/\//.test(path)) return path
  return `${protocol}//${window.location.host}${path}`
}
