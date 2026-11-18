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
  let lab = {
    id: 'lab1',
    name: 'Editor Lab',
    owner: 'admin',
    path: 'Editor-Lab.unl',
    revision: 1,
    description: '',
    body: '',
    lock: null,
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
        interfaces: [{ id: 0, name: 'eth0', type: 'ethernet', network_id: '0' }]
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
      return send(response, 200, { ok: true })
    }
    if (path === '/api/v2/health') return send(response, 200, { status: 'ok', version: '1.2.0' })
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
    if (path === '/api/v2/labs/lab1' && request.method === 'GET') {
      return send(response, 200, { data: lab })
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
      return send(response, 202, { status: 'success', job_id: 'job1', data: { lab_id: 'lab1', [action]: [] } })
    }
    if (path === '/api/v2/jobs/job1') {
      return send(response, 200, { data: { id: 'job1', state: 'succeeded', progress: 100, type: 'lab.start' } })
    }
    if (path === '/api/v2/jobs') return send(response, 200, { data: [] })
    if (path === '/api/v2/templates') {
      return send(response, 200, { data: [
        { id: 'linux', name: 'Linux', kind: 'qemu', source: 'builtin', defaults: { cpu: 1, ram: 512, ethernet: 4, console: 'telnet' } },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs', source: 'builtin', defaults: { ethernet: 1, console: 'telnet' } }
      ] })
    }
    if (path === '/api/v2/images') return send(response, 200, { data: [] })
    if (path === '/api/v2/engines') return send(response, 200, { data: [] })
    if (path === '/api/v2/nodes/console') {
      return send(response, 200, { data: { kind: 'telnet', ws_path: '/ws/console?token=mock', expires_in: 300 } })
    }
    return send(response, 404, { message: 'not found' })
  })

  const wss = new WebSocketServer({ server, path: '/ws' })
  wss.on('connection', (socket) => {
    socket.send('hello from mock console\r\n')
    socket.on('message', (message) => socket.send(`echo:${message}`))
  })

  return new Promise((resolve) => {
    server.listen(port, '127.0.0.1', () => resolve({
      server,
      wss,
      close: () => {
        for (const client of wss.clients) client.terminate()
        wss.close()
        server.closeAllConnections?.()
        server.close()
      }
    }))
  })
}
