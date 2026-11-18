// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

let csrfToken = ''

export class ApiError extends Error {
  constructor(status, message, details = {}) {
    super(message)
    this.status = status
    this.details = details
  }
}

export function setCsrf(token) {
  csrfToken = token || ''
}

async function request(path, { method = 'GET', body, raw = false } = {}) {
  const headers = { Accept: 'application/json' }
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
    throw new ApiError(response.status, error?.message || `HTTP ${response.status}`, error?.details || {})
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
  setup: (payload) => request('/setup', { method: 'POST', body: payload }),
  login: (payload) => request('/auth/login', { method: 'POST', body: payload }),
  logout: () => request('/auth/logout', { method: 'POST' }),
  me: () => request('/auth/me'),
  changePassword: (payload) => request('/auth/password', { method: 'POST', body: payload }),
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
  exportLab: (id) => request(`/labs/${encodeURIComponent(id)}/export`, { raw: true }),
  startLab: (id) => request(`/labs/${encodeURIComponent(id)}/start`, { method: 'POST' }),
  stopLab: (id) => request(`/labs/${encodeURIComponent(id)}/stop`, { method: 'POST' }),
  wipeLab: (id) => request(`/labs/${encodeURIComponent(id)}/wipe`, { method: 'POST' }),
  labNodes: (id) => request(`/labs/${encodeURIComponent(id)}/nodes`),
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
  engines: () => request('/engines'),
  job: (id) => request(`/jobs/${encodeURIComponent(id)}`),
  console: (nodeId) => request('/nodes/console', { method: 'POST', body: { node_id: nodeId } }),
  nodeStart: (id) => request('/nodes/start', { method: 'POST', body: { id } }),
  nodeStop: (id) => request('/nodes/stop', { method: 'POST', body: { id } }),
  nodeWipe: (id) => request('/nodes/wipe', { method: 'POST', body: { id } })
}

export function wsUrl(path) {
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
  if (/^wss?:\/\//.test(path)) return path
  return `${protocol}//${window.location.host}${path}`
}
