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

export function startMockServer(port = 8099) {
  let conflict = false
  let trash = []
  let images = []
  let snapshots = []
  let tokens = []
  // Per-node runtime state exposed by GET /labs/{id}/nodes. lab1 has one node
  // already running (Start is hidden) and one stopped (Start and Wipe shown).
  const nodeStates = { lab1: { 1: 'running', 2: 'stopped' } }
  let settings = { timezone: 'UTC', session_ttl: 3600, max_upload_bytes: 33554432, console_default: 'telnet' }
  let lab = {
    id: 'lab1',
    name: 'Editor Lab',
    owner: 'admin',
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
      return send(response, 200, { lab: { name: lab.name, node_list: lab.node_list, network_list: lab.network_list } })
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
    if (path === '/api/v2/health') return send(response, 200, { status: 'ok', version: '1.5.6' })
    if (path === '/api/v2/version') return send(response, 200, { product: 'TeraLab', version: '1.5.6', license: 'AGPL-3.0-or-later', source: 'https://github.com/SavEvens/TeraLab', components: [{ name: 'TeraLab', license: 'AGPL-3.0-or-later', separate: false }, { name: 'QEMU', license: 'GPL-2.0', separate: true }] })
    if (path === '/api/v2/setup') return send(response, 409, { message: 'Setup has already been completed.' })
    if (path === '/api/v2/auth/login' && request.method === 'POST') {
      return send(response, 200, {
        status: 'success',
        user: { id: 'user1', username: 'admin', role: 'admin' },
        csrf_token: csrf,
        expires_in: 3600
      }, { 'Set-Cookie': 'teralab_session=test; Path=/; HttpOnly; SameSite=Lax' })
    }
    if (path === '/api/v2/auth/me') {
      if (!String(request.headers.cookie || '').includes('teralab_session=')) {
        return send(response, 401, { message: 'Authentication required.' })
      }
      return send(response, 200, { user: { id: 'user1', username: 'admin', role: 'admin' }, csrf_token: csrf })
    }
    if (path === '/api/v2/auth/logout') return send(response, 200, { status: 'success' })
    if (path === '/api/v2/labs' && request.method === 'GET') {
      return send(response, 200, { data: [{ ...lab, node_list: undefined, network_list: undefined }] })
    }
    if (path === '/api/v2/labs' && request.method === 'POST') {
      const input = await body(request)
      lab = {
        ...lab,
        id: 'newlab',
        name: input.name || 'New Lab',
        revision: 1,
        node_list: [],
        network_list: [],
        nodes: 0,
        networks: 0,
        path: 'New-Lab.unl'
      }
      return send(response, 201, { status: 'success', data: lab })
    }
    if (path === '/api/v2/labs/import' && request.method === 'POST') {
      return send(response, 201, {
        status: 'success',
        data: { ...lab, id: 'importlab', name: 'Imported Lab', path: 'Imported-Lab.unl', format: 'eve-ng', nodes: 0, networks: 0, node_list: [], network_list: [] },
        meta: { warnings: [
          { type: 'image_unmapped', node: 'R1', message: 'image "veos" is not mapped to a stored image.' },
          { type: 'network_unsupported', network: '2', message: 'Network "Cloud" uses type "pnet0".' }
        ] }
      })
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
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs', source: 'builtin', defaults: { ethernet: 1, console: 'telnet' } }
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
    if (path === '/api/v2/system/host') {
      return send(response, 200, { data: { load1: 0.2, load5: 0.1, load15: 0.1, cpu_usage_percent: 12.5, disk_free_bytes: 1000000, disk_total_bytes: 2000000, nodes: [], engines: [{ name: 'qemu', available: true, version: '10.0' }, { name: 'dynamips', available: false, version: '' }], interfaces: [{ name: 'eth0', up: true, mac: '00:11:22:33:44:55', addresses: ['192.0.2.10'] }, { name: 'eth1', up: false, mac: '00:11:22:33:44:56', addresses: [] }] } })
    }
    if (path === '/api/v2/system/settings' && request.method === 'GET') return send(response, 200, { data: settings })
    if (path === '/api/v2/system/settings' && request.method === 'PUT') {
      settings = { ...settings, ...(await body(request)) }
      return send(response, 200, { status: 'success', data: settings })
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
    if (path.startsWith('/api/v2/images/') && request.method === 'PATCH') {
      const input = await body(request)
      images = images.map((image) => ({ ...image, name: input.name || image.name }))
      return send(response, 200, { status: 'success', data: images[0] })
    }
    if (path.startsWith('/api/v2/images/') && path.endsWith('/template') && request.method === 'PUT') {
      const input = await body(request)
      images = images.map((image) => ({ ...image, template: input.template }))
      return send(response, 200, { status: 'success', data: images[0] })
    }
    if (path.startsWith('/api/v2/images/') && request.method === 'DELETE') {
      images = []
      return send(response, 200, { status: 'success' })
    }
    if (path === '/api/v2/engines') return send(response, 200, { data: [] })
    if (path === '/api/v2/nodes/console') {
      return send(response, 200, { data: { kind: 'telnet', ws_path: '/ws/console?token=mock', expires_in: 300 } })
    }
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
