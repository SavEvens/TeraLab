// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import http from 'node:http'
import { WebSocketServer } from 'ws'

const csrf = 'csrf-token'

function send(response, status, payload, headers = {}) {
  response.writeHead(status, { 'Content-Type': 'application/json', ...headers })
  response.end(payload === undefined ? '' : JSON.stringify(payload))
}

function body(request) {
  return new Promise((resolve) => {
    let data = ''
    request.on('data', (chunk) => { data += chunk })
    request.on('end', () => {
      try { resolve(data ? JSON.parse(data) : {}) } catch { resolve({}) }
    })
  })
}

// Multipart imports are detected from the raw request so the mock can mirror
// the API's extension-then-content routing without a parser dependency.
function rawBody(request) {
  return new Promise((resolve) => {
    let data = ''
    request.on('data', (chunk) => { data += chunk })
    request.on('end', () => resolve(data))
  })
}

const YAML_SOURCE = `schema: 1
name: YAML Source Lab
nodes:
  R1:
    template: iol
  R2:
    template: iol
networks:
  lan-a: {}
links:
  - [R1.e0/0, R2.e0/0]
`

function yamlName(text) {
  const match = /(?:^|\r?\n)name:\s*([^\r\n]+)/.exec(text)
  return match ? match[1].trim().replace(/^["']|["']$/g, '') : ''
}

// Two-space keys are the node/network identities in the published format;
// four-space keys are node fields. Enough to report the same counts as the
// real validator for the generic documents used by the e2e suite.
function yamlCounts(text) {
  const nodes = new Set()
  const networks = new Set()
  let links = 0
  let section = ''
  for (const line of text.split(/\r?\n/)) {
    const top = /^([A-Za-z0-9_.-]+):/.exec(line)
    if (top) {
      section = top[1]
      continue
    }
    if (section === 'nodes') {
      const match = /^  ([A-Za-z0-9_.-]+):/.exec(line)
      if (match) nodes.add(match[1])
    } else if (section === 'networks') {
      const match = /^  ([A-Za-z0-9_.-]+):/.exec(line)
      if (match) networks.add(match[1])
    } else if (section === 'links' && /^  -/.test(line)) {
      links += 1
    }
  }
  return { nodes: nodes.size, networks: networks.size, links }
}

function yamlWarnings(text) {
  if (!/(?:^|\r?\n)\s*image:\s*/m.test(text)) return []
  return [{ type: 'image_unmapped', node: 'R1', message: 'image "base.qcow2" is not mapped to a stored image.' }]
}

export function startMockServer(port = 8099) {
  let conflict = false
  let trash = []
  let images = []
  let snapshots = []
  let tokens = []
  const usersNow = Math.floor(Date.now() / 1000)
  let nextUserId = 10
  let generatedCount = 0
  let lastUserAction = null
  let createdLabs = []
  let lastMove = null
  let yamlImportCount = 0
  let guestCount = 0
  let lastValidate = null
  let lastYamlApply = null
  let lastDemoSweep = null
  const demo = { lastSweepAt: null, memAvailableMb: 8192, nodesRunning: 1 }
  let users = [
    { id: 'user1', username: 'admin', email: 'admin@example.test', name: 'Ada Admin', role: 'admin', expiration: -1, created_at: usersNow - 7200, updated_at: usersNow - 3600, session_count: 2, last_seen_at: usersNow - 60, lab_count: 1 },
    { id: 'user2', username: 'editor', email: 'editor@example.test', name: 'Ed Editor', role: 'editor', expiration: Math.floor((Date.now() + 30 * 86400000) / 1000), created_at: usersNow - 6000, updated_at: usersNow - 5000, session_count: 0, last_seen_at: null, lab_count: 2 },
    { id: 'user3', username: 'viewer', email: '', name: 'Vera Viewer', role: 'user', expiration: -1, created_at: usersNow - 4000, updated_at: usersNow - 3000, session_count: 0, last_seen_at: null, lab_count: 0 }
  ]
  const adminCount = () => users.filter((user) => user.role === 'admin').length
  const generatedPassword = () => `Teralab-Gen-${++generatedCount}-2026!`
  const sessionUser = (request) => {
    const match = /(?:^|;\s*)teralab_session=([^;]+)/.exec(String(request.headers.cookie || ''))
    if (!match) return null
    return users.find((user) => user.username === decodeURIComponent(match[1])) || null
  }
  const requireAdmin = (request, response) => {
    const actor = sessionUser(request)
    if (!actor) {
      send(response, 401, { message: 'Authentication required.' })
      return null
    }
    if (actor.role !== 'admin') {
      send(response, 403, { message: 'Administrator role required.' })
      return null
    }
    return actor
  }
  // Per-node runtime state exposed by GET /labs/{id}/nodes. lab1 has one node
  // already running (Start is hidden) and one stopped (Start and Wipe shown).
  const nodeStates = { lab1: { 1: 'running', 2: 'stopped' } }
  let settings = {
    timezone: 'UTC',
    session_ttl: 3600,
    max_upload_bytes: 33554432,
    console_default: 'telnet',
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
    turnstile_enabled: 0,
    turnstile_site_key: '3x00000000000000000000FF',
    turnstile_secret_key: '1x0000000000000000000000000000000AA'
  }
  let lab = {
    id: 'lab1',
    name: 'Editor Lab',
    owner: 'user1',
    path: 'Editor-Lab.unl',
    revision: 1,
    description: '',
    body: '',
    lock: null,
    format: 'legacy',
    running: false,
    running_nodes: 0,
    nodes: 2,
    networks: 1,
    network_list: [{ id: '0', name: 'Net-1', type: 'bridge' }],
    node_list: [
      {
        id: '1', name: 'R1', type: 'qemu', template: 'linux', image: 'base.qcow2',
        console: 'telnet', cpu: 1, ram: 512, uuid: '', platform: '', left: 120, top: 100,
        interfaces: [{ id: 0, name: 'eth0', type: 'ethernet', network_id: '0' }]
      },
      {
        id: '2', name: 'R2', type: 'qemu', template: 'linux', image: 'base.qcow2',
        console: 'telnet', cpu: 1, ram: 512, uuid: '', platform: '', left: 480, top: 100,
        interfaces: [
          { id: 0, name: 'eth0', type: 'ethernet', network_id: '0' },
          { id: 1, name: 'eth1', type: 'ethernet', network_id: '' }
        ]
      }
    ]
  }

  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, `http://127.0.0.1:${port}`)
    const path = url.pathname
    if (request.method === 'POST' && path === '/__conflict') {
      conflict = true
      return send(response, 200, { ok: true })
    }
    if (request.method === 'POST' && path === '/__trash') {
      trash = [{
        id: 'trashed1',
        name: 'Trashed Lab',
        original_path: 'Trashed-Lab.unl',
        deleted_at: Math.floor(Date.now() / 1000),
        deleted_by: 'user1',
        expires_at: Math.floor(Date.now() / 1000) + 2592000,
        trash_token: 'token1'
      }]
      return send(response, 200, { ok: true })
    }
    if (request.method === 'GET' && path === '/__state') {
      return send(response, 200, {
        lab: {
          name: lab.name,
          revision: lab.revision,
          node_list: lab.node_list,
          network_list: lab.network_list,
          extensions: lab.extensions
        },
        createdLabs: createdLabs.map((created) => ({ id: created.id, name: created.name, path: created.path })),
        lastMove,
        lastValidate,
        lastYamlApply,
        lastDemoSweep
      })
    }
    if (request.method === 'POST' && (path === '/__yaml' || path === '/__yaml-stopped')) {
      lab = {
        ...lab,
        id: 'lab1',
        name: 'YAML Source Lab',
        path: 'YAML-Source-Lab.unl',
        revision: 2,
        format: 'teralab-2',
        description: '',
        body: '',
        lock: null,
        running: false,
        running_nodes: 0,
        nodes: 2,
        networks: 1,
        network_list: [{ id: '1', name: 'lan-a', type: 'bridge' }],
        node_list: [
          {
            id: '1', name: 'R1', type: 'iol', template: 'iol', image: '',
            console: 'telnet', cpu: 1, ram: 256, uuid: '', platform: '', left: 120, top: 100,
            interfaces: [{ id: 0, name: 'Ethernet0/0', type: 'ethernet', network_id: '1' }]
          },
          {
            id: '2', name: 'R2', type: 'iol', template: 'iol', image: '',
            console: 'telnet', cpu: 1, ram: 256, uuid: '', platform: '', left: 480, top: 100,
            interfaces: [{ id: 0, name: 'Ethernet0/0', type: 'ethernet', network_id: '1' }]
          }
        ],
        extensions: {
          source: {
            kind: 'yaml',
            name: 'triangle.yaml',
            sha256: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            revision: 2,
            text: YAML_SOURCE
          }
        }
      }
      nodeStates.lab1 = path === '/__yaml' ? { 1: 'running', 2: 'stopped' } : {}
      return send(response, 200, { ok: true })
    }
    if (request.method === 'GET' && path === '/__users-state') {
      return send(response, 200, { users, lastUserAction })
    }
    // Demo/Turnstile test switch. Turnstile stays off in the mock: the real
    // Cloudflare widget is covered by a component test with a stubbed widget.
    if (request.method === 'POST' && path === '/__demo') {
      const input = await body(request)
      if (input.enabled !== undefined) settings.demo_enabled = input.enabled ? 1 : 0
      if (input.landing !== undefined) settings.demo_landing = input.landing === 'demo' ? 'demo' : 'login'
      if (input.max_guests !== undefined) settings.demo_max_guests = Number(input.max_guests)
      if (input.turnstile !== undefined) settings.turnstile_enabled = input.turnstile ? 1 : 0
      return send(response, 200, {
        ok: true,
        demo: { enabled: !!settings.demo_enabled, landing: settings.demo_landing, max_guests: settings.demo_max_guests },
        turnstile: { enabled: !!settings.turnstile_enabled }
      })
    }
    if (request.method === 'POST' && path === '/__fifty') {
      const nodeList = Array.from({ length: 50 }, (_, index) => ({
        id: String(index + 1),
        name: `N${index + 1}`,
        type: 'qemu',
        template: 'linux',
        image: 'base.qcow2',
        console: 'telnet',
        cpu: 1,
        ram: 512,
        uuid: '',
        platform: '',
        left: 60 + (index % 10) * 180,
        top: 60 + Math.floor(index / 10) * 110,
        interfaces: [{ id: 0, name: 'eth0', type: 'ethernet', network_id: String(index % 10) }]
      }))
      lab.node_list = nodeList
      lab.network_list = Array.from({ length: 10 }, (_, index) => ({ id: String(index), name: `Net-${index}`, type: 'bridge' }))
      lab.nodes = 50
      lab.networks = 10
      nodeStates.lab1 = {}
      return send(response, 200, { ok: true })
    }
    if (request.method === 'POST' && path === '/__large') {
      const nodeList = Array.from({ length: 100 }, (_, index) => ({
        id: String(index + 1),
        name: `N${index + 1}`,
        type: 'qemu',
        template: 'linux',
        image: 'base.qcow2',
        console: 'telnet',
        cpu: 1,
        ram: 512,
        uuid: '',
        platform: '',
        left: 60 + (index % 10) * 180,
        top: 60 + Math.floor(index / 10) * 110,
        interfaces: [{ id: 0, name: 'eth0', type: 'ethernet', network_id: String(index % 10) }]
      }))
      lab.node_list = nodeList
      lab.network_list = Array.from({ length: 10 }, (_, index) => ({ id: String(index), name: `Net-${index}`, type: 'bridge' }))
      lab.nodes = 100
      lab.networks = 10
      nodeStates.lab1 = {}
      return send(response, 200, { ok: true })
    }
    // Two IOL nodes linked by a single cable network, used to prove that
    // deleting one node detaches the peer and removes the network.
    if (request.method === 'POST' && path === '/__linked') {
      lab = {
        ...lab,
        name: 'Linked Lab',
        revision: 1,
        running: false,
        running_nodes: 0,
        nodes: 2,
        networks: 1,
        network_list: [{ id: '1', name: 'Net-1', type: 'bridge' }],
        node_list: [
          {
            id: '1', name: 'IOL_1', type: 'iol', template: 'iol', image: '',
            console: 'telnet', cpu: 1, ram: 256, uuid: '', platform: '', left: 120, top: 100,
            interfaces: [{ id: 0, name: 'Ethernet0/0', type: 'ethernet', network_id: '1' }]
          },
          {
            id: '2', name: 'IOL_2', type: 'iol', template: 'iol', image: '',
            console: 'telnet', cpu: 1, ram: 256, uuid: '', platform: '', left: 480, top: 100,
            interfaces: [{ id: 0, name: 'Ethernet0/0', type: 'ethernet', network_id: '1' }]
          }
        ]
      }
      nodeStates.lab1 = {}
      return send(response, 200, { ok: true })
    }
    if (path === '/api/v2/health') {
      return send(response, 200, {
        status: 'ok',
        version: '1.6-dev',
        demo: {
          enabled: !!settings.demo_enabled,
          landing: settings.demo_landing,
          // The picker groups catalogue templates by this allowlist for guests.
          templates: String(settings.demo_allowed_templates || '')
            .split(',')
            .map((id) => id.trim())
            .filter(Boolean)
        },
        turnstile: { enabled: !!settings.turnstile_enabled, site_key: settings.turnstile_site_key }
      })
    }
    if (path === '/api/v2/version') return send(response, 200, { product: 'TeraLab', version: '1.6-dev', license: 'AGPL-3.0-or-later', source: 'https://github.com/SavEvens/TeraLab', components: [{ name: 'TeraLab', license: 'AGPL-3.0-or-later', separate: false }, { name: 'QEMU', license: 'GPL-2.0', separate: true }] })
    if (path === '/api/v2/setup') return send(response, 409, { message: 'Setup has already been completed.' })
    if (path === '/api/v2/auth/guest' && request.method === 'POST') {
      if (!settings.demo_enabled) return send(response, 404, { message: 'Demo mode is disabled.' })
      const activeGuests = users.filter((user) => user.guest).length
      if (activeGuests >= Number(settings.demo_max_guests)) {
        return send(response, 403, { message: 'The demo is full.' })
      }
      const input = await body(request)
      if (settings.turnstile_enabled && !input['cf-turnstile-response']) {
        return send(response, 422, { message: 'Captcha verification is required.' })
      }
      const username = `g-${String(++guestCount).padStart(16, '0')}`
      const user = {
        id: `guest${guestCount}`,
        username,
        email: '',
        name: '',
        role: 'editor',
        guest: true,
        expiration: Math.floor(Date.now() / 1000) + 48 * 3600,
        created_at: Math.floor(Date.now() / 1000),
        updated_at: Math.floor(Date.now() / 1000),
        session_count: 1,
        last_seen_at: Math.floor(Date.now() / 1000),
        lab_count: 0
      }
      users = [...users, user]
      return send(response, 200, {
        status: 'success',
        user: { id: user.id, username: user.username, role: user.role, guest: true },
        csrf_token: csrf,
        expires_in: 3600
      }, { 'Set-Cookie': `teralab_session=${encodeURIComponent(user.username)}; Path=/; HttpOnly; SameSite=Lax` })
    }
    if (path === '/api/v2/auth/login' && request.method === 'POST') {
      const input = await body(request)
      const user = users.find((candidate) => candidate.username === input.username)
      if (!user) return send(response, 401, { message: 'Invalid username or password.' })
      return send(response, 200, {
        status: 'success',
        user: { id: user.id, username: user.username, role: user.role },
        csrf_token: csrf,
        expires_in: 3600
      }, { 'Set-Cookie': `teralab_session=${encodeURIComponent(user.username)}; Path=/; HttpOnly; SameSite=Lax` })
    }
    if (path === '/api/v2/auth/me') {
      const user = sessionUser(request)
      if (!user) return send(response, 401, { message: 'Authentication required.' })
      return send(response, 200, { user: { id: user.id, username: user.username, role: user.role, guest: !!user.guest }, csrf_token: csrf })
    }
    if (path === '/api/v2/auth/logout') {
      return send(response, 200, { status: 'success' }, { 'Set-Cookie': 'teralab_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax' })
    }
    if (path === '/api/v2/auth/password' && request.method === 'POST') {
      const input = await body(request)
      if (!input.current_password) return send(response, 422, { message: 'current_password and new_password are required.' })
      return send(response, 200, { status: 'success' })
    }
    if (path === '/api/v2/users' && request.method === 'GET') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      return send(response, 200, { data: users })
    }
    if (path === '/api/v2/users' && request.method === 'POST') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const input = await body(request)
      const username = String(input.username || '').trim()
      if (!/^[A-Za-z0-9][A-Za-z0-9_.-]{2,31}$/.test(username)) {
        return send(response, 422, { message: 'username must be 3-32 characters and start with a letter or digit.' })
      }
      if (users.some((user) => user.username === username)) {
        return send(response, 422, { message: 'That username is already taken.' })
      }
      if (!['admin', 'editor', 'user'].includes(input.role)) {
        return send(response, 422, { message: 'role must be admin, editor, or user.' })
      }
      const generated = input.password ? null : generatedPassword()
      const user = {
        id: `user${nextUserId++}`,
        username,
        email: typeof input.email === 'string' ? input.email : '',
        name: typeof input.name === 'string' ? input.name : '',
        role: input.role,
        expiration: Number.isInteger(input.expiration) ? input.expiration : -1,
        created_at: Math.floor(Date.now() / 1000),
        updated_at: Math.floor(Date.now() / 1000),
        session_count: 0,
        last_seen_at: null,
        lab_count: 0
      }
      users = [...users, user]
      lastUserAction = { type: 'create', username, password_generated: !!generated }
      return send(response, 201, { data: { user, ...(generated ? { password: generated } : {}) } })
    }
    const userMatch = /^\/api\/v2\/users\/([^/]+)$/.exec(path)
    if (request.method === 'PATCH' && userMatch) {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const id = decodeURIComponent(userMatch[1])
      const target = users.find((user) => user.id === id)
      if (!target) return send(response, 404, { message: 'User not found.' })
      const input = await body(request)
      if (input.role !== undefined && input.role !== target.role) {
        if (!['admin', 'editor', 'user'].includes(input.role)) {
          return send(response, 422, { message: 'role must be admin, editor, or user.' })
        }
        if (target.id === actor.id) {
          return send(response, 409, { message: 'You cannot change your own role.' })
        }
        if (target.role === 'admin' && input.role !== 'admin' && adminCount() <= 1) {
          return send(response, 409, { message: 'The last administrator cannot be demoted.' })
        }
      }
      users = users.map((user) => user.id === id
        ? {
            ...user,
            email: typeof input.email === 'string' ? input.email : user.email,
            name: typeof input.name === 'string' ? input.name : user.name,
            expiration: Number.isInteger(input.expiration) ? input.expiration : user.expiration,
            role: input.role !== undefined ? input.role : user.role,
            updated_at: Math.floor(Date.now() / 1000)
          }
        : user)
      lastUserAction = { type: 'update', username: target.username }
      return send(response, 200, { data: users.find((user) => user.id === id) })
    }
    if (request.method === 'DELETE' && userMatch) {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const id = decodeURIComponent(userMatch[1])
      const target = users.find((user) => user.id === id)
      if (!target) return send(response, 404, { message: 'User not found.' })
      const input = await body(request)
      if (target.id === actor.id) {
        return send(response, 409, { message: 'You cannot delete your own account.' })
      }
      if (target.role === 'admin' && adminCount() <= 1) {
        return send(response, 409, { message: 'The last administrator cannot be deleted.' })
      }
      if (input.delete_labs && lab.owner === target.username && lab.running) {
        return send(response, 409, { message: 'One or more labs have running nodes; stop them before deleting the user.' })
      }
      const labsTrashed = input.delete_labs ? target.lab_count : 0
      users = users.filter((user) => user.id !== id)
      lastUserAction = { type: 'delete', username: target.username, delete_labs: !!input.delete_labs, labs_trashed: labsTrashed }
      return send(response, 200, { data: { deleted: true, labs_trashed: labsTrashed } })
    }
    const userPasswordMatch = /^\/api\/v2\/users\/([^/]+)\/password$/.exec(path)
    if (request.method === 'POST' && userPasswordMatch) {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const id = decodeURIComponent(userPasswordMatch[1])
      const target = users.find((user) => user.id === id)
      if (!target) return send(response, 404, { message: 'User not found.' })
      const input = await body(request)
      const generated = input.password ? null : generatedPassword()
      users = users.map((user) => user.id === id ? { ...user, session_count: 0, updated_at: Math.floor(Date.now() / 1000) } : user)
      lastUserAction = { type: 'password', username: target.username, password_generated: !!generated }
      return send(response, 200, { data: generated ? { password: generated } : {} })
    }
    const userRevokeMatch = /^\/api\/v2\/users\/([^/]+)\/sessions\/revoke$/.exec(path)
    if (request.method === 'POST' && userRevokeMatch) {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const id = decodeURIComponent(userRevokeMatch[1])
      const target = users.find((user) => user.id === id)
      if (!target) return send(response, 404, { message: 'User not found.' })
      const revoked = target.session_count || 0
      users = users.map((user) => user.id === id ? { ...user, session_count: 0, last_seen_at: null } : user)
      lastUserAction = { type: 'revoke', username: target.username, revoked }
      return send(response, 200, { data: { revoked } })
    }
    if (path === '/api/v2/labs' && request.method === 'GET') {
      // Guests get an isolated, initially empty workspace.
      if (sessionUser(request)?.guest) return send(response, 200, { data: [] })
      return send(response, 200, {
        data: [
          { ...lab, node_list: undefined, network_list: undefined, extensions: undefined },
          ...createdLabs.map((created) => ({ ...created, node_list: undefined, network_list: undefined }))
        ]
      })
    }
    if (path === '/api/v2/labs' && request.method === 'POST') {
      const input = await body(request)
      const name = input.name || 'New Lab'
      const created = {
        id: `newlab${createdLabs.length + 1}`,
        name,
        owner: 'user1',
        path: `${name.replace(/\s+/g, '-')}.unl`,
        revision: 1,
        format: 'teralab-2',
        running: false,
        running_nodes: 0,
        nodes: 0,
        networks: 0,
        node_list: [],
        network_list: []
      }
      createdLabs.push(created)
      return send(response, 201, { status: 'success', data: created })
    }
    const moveMatch = /^\/api\/v2\/labs\/([^/]+)\/move$/.exec(path)
    if (request.method === 'POST' && moveMatch) {
      const input = await body(request)
      const id = decodeURIComponent(moveMatch[1])
      const target = createdLabs.find((created) => created.id === id)
      if (!target) return send(response, 404, { message: 'Lab not found.' })
      const folder = String(input.folder || '').trim()
      target.path = folder ? `${folder}/${target.name}.unl` : `${target.name}.unl`
      lastMove = { id, folder }
      return send(response, 200, { status: 'success', data: target })
    }
    if (path === '/api/v2/labs/import' && request.method === 'POST') {
      const raw = await rawBody(request)
      if (/filename="[^"]+\.ya?ml"/i.test(raw) || /(?:^|\r?\n)schema:\s*1\s*(?:\r?\n|$)/.test(raw)) {
        const name = yamlName(raw) || 'YAML Lab'
        const counts = yamlCounts(raw)
        const imported = {
          id: `yamllab${++yamlImportCount}`,
          name,
          owner: 'user1',
          path: `${name.replace(/\s+/g, '-')}.unl`,
          revision: 1,
          format: 'teralab-2',
          running: false,
          running_nodes: 0,
          nodes: counts.nodes,
          networks: counts.networks,
          node_list: [],
          network_list: []
        }
        createdLabs.push(imported)
        return send(response, 201, {
          status: 'success',
          data: imported,
          meta: { warnings: yamlWarnings(raw) }
        })
      }
      return send(response, 201, {
        status: 'success',
        data: { ...lab, id: 'importlab', name: 'Imported Lab', path: 'Imported-Lab.unl', format: 'eve-ng', nodes: 0, networks: 0, node_list: [], network_list: [] },
        meta: { warnings: [
          { type: 'image_unmapped', node: 'R1', message: 'image "veos" is not mapped to a stored image.' },
          { type: 'network_unsupported', network: '2', message: 'Network "Cloud" uses type "pnet0".' }
        ] }
      })
    }
    if (path === '/api/v2/labs/validate' && request.method === 'POST') {
      const input = await body(request)
      const text = String(input.yaml || '')
      const counts = yamlCounts(text)
      const errors = []
      if (!text.trim()) errors.push('YAML document is empty.')
      const schema = /(?:^|\r?\n)schema:\s*([^\r\n]+)/.exec(text)
      if (text.trim() && (!schema || schema[1].trim() !== '1')) errors.push('schema must be 1.')
      lastValidate = { filename: input.filename || '', valid: errors.length === 0 }
      return send(response, 200, {
        data: {
          valid: errors.length === 0,
          errors,
          warnings: yamlWarnings(text),
          counts
        }
      })
    }
    const applyMatch = /^\/api\/v2\/labs\/([^/]+)\/apply$/.exec(path)
    if (request.method === 'POST' && applyMatch) {
      const id = decodeURIComponent(applyMatch[1])
      const input = await body(request)
      const states = nodeStates[id] || {}
      if (Object.values(states).some((state) => state === 'running')) {
        return send(response, 409, { message: 'Stop and wipe the lab before applying YAML.' })
      }
      if (Number(input.revision) !== Number(lab.revision)) {
        return send(response, 409, {
          message: 'The lab was modified by another session.',
          details: { revision: lab.revision }
        })
      }
      const text = String(input.yaml || '')
      const counts = yamlCounts(text)
      const revision = lab.revision + 1
      const networkList = Array.from({ length: Math.max(0, counts.networks) }, (_, index) => ({
        id: String(index + 1),
        name: `net-${index + 1}`,
        type: 'bridge'
      }))
      const nodeList = Array.from({ length: Math.max(0, counts.nodes) }, (_, index) => ({
        id: String(index + 1),
        name: `N${index + 1}`,
        type: 'iol',
        template: 'iol',
        image: '',
        console: 'telnet',
        cpu: 1,
        ram: 256,
        uuid: '',
        platform: '',
        left: 120 + (index % 4) * 180,
        top: 100 + Math.floor(index / 4) * 120,
        interfaces: [{
          id: 0,
          name: 'Ethernet0/0',
          type: 'ethernet',
          network_id: networkList.length ? networkList[index % networkList.length].id : ''
        }]
      }))
      lab = {
        ...lab,
        name: yamlName(text) || lab.name,
        revision,
        nodes: nodeList.length,
        networks: networkList.length,
        node_list: nodeList,
        network_list: networkList,
        extensions: {
          ...lab.extensions,
          source: {
            kind: 'yaml',
            name: input.filename || 'lab.yaml',
            sha256: 'f'.repeat(64),
            revision,
            text
          }
        }
      }
      nodeStates[id] = {}
      lastYamlApply = { id, filename: input.filename || 'lab.yaml', revision, yaml: text }
      return send(response, 200, { status: 'success', data: lab, meta: { warnings: yamlWarnings(text) } })
    }
    if (path === '/api/v2/labs/trash' && request.method === 'GET') {
      return send(response, 200, { data: trash })
    }
    if (path.startsWith('/api/v2/labs/trash/') && request.method === 'DELETE') {
      trash = []
      return send(response, 200, { status: 'success' })
    }
    if (path === '/api/v2/labs/trashed1/restore' && request.method === 'POST') {
      trash = []
      return send(response, 200, { status: 'success', data: { ...lab, id: 'trashed1', name: 'Trashed Lab' } })
    }
    if (path === '/api/v2/labs/lab1' && request.method === 'GET') {
      return send(response, 200, { data: lab })
    }
    const createdLabMatch = /^\/api\/v2\/labs\/([^/]+)$/.exec(path)
    if (request.method === 'GET' && createdLabMatch) {
      const createdId = decodeURIComponent(createdLabMatch[1])
      const created = createdLabs.find((candidate) => candidate.id === createdId)
      if (created) {
        return send(response, 200, {
          data: { ...created, node_list: created.node_list || [], network_list: created.network_list || [] }
        })
      }
    }
    const nodesMatch = /^\/api\/v2\/labs\/([^/]+)\/nodes$/.exec(path)
    if (request.method === 'GET' && nodesMatch) {
      const labId = decodeURIComponent(nodesMatch[1])
      const states = nodeStates[labId] || {}
      const nodeList = labId === 'lab1' ? lab.node_list : []
      return send(response, 200, {
        status: 'success',
        data: {
          lab_id: labId,
          nodes: nodeList.map((node) => ({
            node_id: String(node.id),
            agent_id: `agent-${labId}`,
            name: node.name || String(node.id),
            type: node.type || 'qemu',
            state: states[String(node.id)] || (lab.running ? 'running' : 'stopped')
          }))
        }
      })
    }
    if (path === '/api/v2/labs/lab1' && request.method === 'PUT') {
      const input = await body(request)
      if (conflict) {
        conflict = false
        return send(response, 409, { message: 'The lab was modified by another session.', details: { revision: lab.revision + 9 } })
      }
      if (input.revision !== lab.revision) {
        return send(response, 409, { message: 'The lab was modified by another session.', details: { revision: lab.revision } })
      }
      lab = {
        ...lab,
        name: input.name || lab.name,
        revision: lab.revision + 1,
        node_list: input.nodes || lab.node_list,
        network_list: input.networks || lab.network_list,
        nodes: (input.nodes || lab.node_list).length,
        networks: (input.networks || lab.network_list).length
      }
      return send(response, 200, { status: 'success', data: lab })
    }
    if (path === '/api/v2/labs/lab1/start' || path === '/api/v2/labs/lab1/stop' || path === '/api/v2/labs/lab1/wipe') {
      const action = path.split('/').pop()
      lab = {
        ...lab,
        running: action === 'start',
        running_nodes: action === 'start' ? lab.node_list.length : 0
      }
      for (const node of lab.node_list) {
        nodeStates.lab1[String(node.id)] = action === 'start' ? 'running' : 'stopped'
      }
      return send(response, 202, { status: 'success', job_id: 'job1', data: { lab_id: 'lab1', [action]: [] } })
    }
    if (path === '/api/v2/jobs/job1') {
      return send(response, 200, { data: { id: 'job1', state: 'succeeded', progress: 100, type: 'lab.start' } })
    }
    if (path === '/api/v2/jobs') return send(response, 200, { data: [] })
    if (path === '/api/v2/templates') {
      return send(response, 200, { data: [
        { id: 'linux', name: 'Linux', kind: 'qemu', source: 'builtin', defaults: { cpu: 1, ram: 512, ethernet: 4, console: 'telnet' } },
        { id: 'iol', name: 'Cisco IOL', kind: 'iol', source: 'builtin', icon: 'Router.png', defaults: { ethernet: 2, serial: 2, console: 'telnet' } },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs', source: 'builtin', defaults: { ethernet: 1, console: 'telnet' } },
        { id: 'frr', name: 'FRR Router', kind: 'docker', options: { docker: { image: 'frrouting/frr:v8.4.1' } }, defaults: { cpu: 1, ram: 256, ethernet: 1, console: 'telnet' }, icon: 'Router.png', source: 'builtin' }
      ] })
    }
    // Locally pulled container images; administrator-only and reference-only.
    if (path === '/api/v2/docker/images' && request.method === 'GET') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      return send(response, 200, { data: [
        { reference: 'frrouting/frr:v8.4.1' },
        { reference: 'alpine:3.20' }
      ] })
    }
    if (path === '/api/v2/labs/lab1/snapshots' && request.method === 'GET') return send(response, 200, { data: snapshots })
    if (path === '/api/v2/labs/lab1/snapshots' && request.method === 'POST') {
      const input = await body(request)
      const snapshot = { id: `snap${snapshots.length + 1}`, lab_id: 'lab1', name: input.name || 'Snapshot', revision: lab.revision, created_at: Math.floor(Date.now() / 1000) }
      snapshots = [snapshot, ...snapshots]
      return send(response, 201, { status: 'success', data: snapshot })
    }
    if (path.startsWith('/api/v2/labs/lab1/snapshots/') && path.endsWith('/restore')) return send(response, 200, { status: 'success', data: lab })
    if (path.startsWith('/api/v2/labs/lab1/snapshots/') && request.method === 'DELETE') {
      snapshots = []
      return send(response, 200, { status: 'success' })
    }
    const nodeControlMatch = /^\/api\/v2\/labs\/([^/]+)\/nodes\/([^/]+)\/(start|stop|wipe)$/.exec(path)
    if (request.method === 'POST' && nodeControlMatch) {
      const [, labId, nodeId, action] = nodeControlMatch
      const states = nodeStates[labId] || (nodeStates[labId] = {})
      states[decodeURIComponent(nodeId)] = action === 'start' ? 'running' : 'stopped'
      return send(response, 200, { status: 'success', data: { state: 'ok' } })
    }
    const nodeConsoleMatch = /^\/api\/v2\/labs\/([^/]+)\/nodes\/([^/]+)\/console$/.exec(path)
    if (request.method === 'POST' && nodeConsoleMatch) {
      return send(response, 200, {
        data: {
          kind: 'telnet',
          token: 'mock',
          ws_path: `/ws/console?token=mock&lab=${encodeURIComponent(nodeConsoleMatch[1])}&node=${encodeURIComponent(nodeConsoleMatch[2])}`,
          expires_in: 300
        }
      })
    }
    if (path === '/api/v2/system/host') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      return send(response, 200, { data: { load1: 0.2, load5: 0.1, load15: 0.1, cpu_usage_percent: 12.5, disk_free_bytes: 1000000, disk_total_bytes: 2000000, nodes: [], engines: [{ name: 'qemu', available: true, version: '10.0' }, { name: 'dynamips', available: false, version: '' }], interfaces: [{ name: 'eth0', up: true, mac: '00:11:22:33:44:55', addresses: ['192.0.2.10'] }, { name: 'eth1', up: false, mac: '00:11:22:33:44:56', addresses: [] }] } })
    }
    if (path === '/api/v2/system/settings' && request.method === 'GET') return send(response, 200, { data: settings })
    if (path === '/api/v2/system/settings' && request.method === 'PUT') {
      settings = { ...settings, ...(await body(request)) }
      return send(response, 200, { status: 'success', data: settings })
    }
    if (path === '/api/v2/system/demo/status' && request.method === 'GET') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      return send(response, 200, {
        data: {
          last_sweep_at: demo.lastSweepAt,
          mem_available_mb: demo.memAvailableMb,
          guests_active: users.filter((user) => user.guest).length,
          demo_nodes_running: demo.nodesRunning
        }
      })
    }
    if (path === '/api/v2/system/demo/sweep' && request.method === 'POST') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      demo.lastSweepAt = Math.floor(Date.now() / 1000)
      demo.memAvailableMb = 6144
      demo.nodesRunning = 0
      lastDemoSweep = { guests_purged: 2, labs_wiped: 3, nodes_reaped: 1, labs_failed: 0, mem_available_mb: demo.memAvailableMb }
      return send(response, 200, { data: lastDemoSweep })
    }
    if (path === '/api/v2/system/logs') return send(response, 200, { data: { source: 'api', lines: ['mock log line'], size: 14, modified: 0 } })
    if (path === '/api/v2/system/tokens' && request.method === 'GET') return send(response, 200, { data: tokens })
    if (path === '/api/v2/system/tokens' && request.method === 'POST') {
      const input = await body(request)
      const token = { id: 'tok1', name: input.name || 'token', token: 'tl_mock_token', scopes: input.scopes || ['read'], created_at: Math.floor(Date.now() / 1000), last_used_at: null, expires_at: null }
      tokens = [token, ...tokens]
      return send(response, 201, { status: 'success', data: token })
    }
    if (path.startsWith('/api/v2/system/tokens/') && request.method === 'DELETE') {
      tokens = []
      return send(response, 200, { status: 'success' })
    }
    if (path === '/api/v2/images' && request.method === 'GET') return send(response, 200, { data: images })
    if (path === '/api/v2/images' && request.method === 'POST') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const image = {
        id: 'img1',
        kind: 'qemu',
        name: 'base',
        reference: 'base.qcow2',
        path: '/var/lib/teralab/images/qemu/base.qcow2',
        sha256: 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
        size: 1048576,
        virtual_size: 8388608,
        format: 'qcow2',
        backing_file: '',
        notes: null,
        template: null,
        created_at: Math.floor(Date.now() / 1000)
      }
      images = [image]
      return send(response, 201, { status: 'success', data: image })
    }
    if (request.method === 'POST' && path === '/__extra-image') {
      // A second qemu image, mapped to a different template. The Inspector must
      // not offer it for a Linux node once Linux has its own mapping.
      images.push({
        id: 'img2',
        kind: 'qemu',
        name: 'Other image',
        reference: 'other.qcow2',
        path: '/var/lib/teralab/images/qemu/other.qcow2',
        sha256: 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        size: 2097152,
        virtual_size: 8388608,
        format: 'qcow2',
        backing_file: '',
        notes: null,
        template: 'vpcs',
        created_at: Math.floor(Date.now() / 1000)
      })
      return send(response, 200, { ok: true })
    }
    if (path.startsWith('/api/v2/images/') && request.method === 'PATCH') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const input = await body(request)
      const id = decodeURIComponent(path.split('/').pop())
      const current = images.find((image) => image.id === id)
      if (!current) return send(response, 404, { message: 'Image not found.' })
      if (
        typeof input.reference === 'string' &&
        input.reference !== current.reference &&
        images.some((image) => image.id !== id && image.reference === input.reference)
      ) {
        return send(response, 409, { message: 'Another image already uses that reference.' })
      }
      images = images.map((image) => image.id === id
        ? {
            ...image,
            name: typeof input.name === 'string' ? input.name : image.name,
            reference: typeof input.reference === 'string' ? input.reference : image.reference
          }
        : image)
      return send(response, 200, { status: 'success', data: images.find((image) => image.id === id) })
    }
    if (path.startsWith('/api/v2/images/') && path.endsWith('/template') && request.method === 'PUT') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      const input = await body(request)
      images = images.map((image) => ({ ...image, template: input.template }))
      return send(response, 200, { status: 'success', data: images[0] })
    }
    if (path.startsWith('/api/v2/images/') && request.method === 'DELETE') {
      const actor = requireAdmin(request, response)
      if (!actor) return
      images = []
      return send(response, 200, { status: 'success' })
    }
    if (path === '/api/v2/engines') return send(response, 200, { data: [] })
    return send(response, 404, { message: 'not found' })
  })

  const wss = new WebSocketServer({ server, path: '/ws' })
  // Record the frames each console connection sends so e2e can assert that the
  // telnet console no longer writes resize control JSON into the device stream.
  const wsMessages = []
  wss.on('connection', (socket) => {
    const messages = []
    wsMessages.push(messages)
    socket.send('hello from mock console\r\n')
    socket.on('message', (message) => {
      messages.push(Buffer.isBuffer(message) ? message.toString('utf8') : String(message))
      socket.send(`echo:${message}`)
    })
  })

  return new Promise((resolve) => {
    server.listen(port, '127.0.0.1', () => resolve({
      server,
      wss,
      wsMessages,
      close: () => {
        for (const client of wss.clients) client.terminate()
        wss.close()
        server.closeAllConnections?.()
        server.close()
      }
    }))
  })
}
