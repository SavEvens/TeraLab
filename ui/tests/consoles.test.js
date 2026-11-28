// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { openConsole, closeConsole, activateConsole, consoleState } from '../src/stores/consoles'

function consoleResponse(data = {}) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({
      data: {
        kind: 'telnet',
        token: 'lab-token',
        ws_path: '/ws/console?token=lab-token&lab=lab1&node=2',
        expires_in: 300,
        password: '',
        ...data
      }
    })
  }
}

beforeEach(() => {
  consoleState.tabs.splice(0, consoleState.tabs.length)
  consoleState.active = null
  vi.stubGlobal('fetch', vi.fn(async () => consoleResponse()))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('console store', () => {
  it('opens a console through the lab-scoped node endpoint', async () => {
    const tab = await openConsole('lab 1', { id: 'node/2', name: 'R2' })

    expect(fetch).toHaveBeenCalledTimes(1)
    const [url, options] = fetch.mock.calls[0]
    expect(url).toBe('/api/v2/labs/lab%201/nodes/node%2F2/console')
    expect(url).not.toContain('lab 1-node/2')
    expect(options.method).toBe('POST')
    expect(options.body).toBeUndefined()

    expect(tab).toMatchObject({
      labId: 'lab 1',
      nodeId: 'node/2',
      name: 'R2',
      kind: 'telnet',
      token: 'lab-token',
      password: ''
    })
    // The capability is kept out of the path that becomes the WebSocket URL.
    expect(tab.wsPath).toBe('/ws/console?lab=lab1&node=2')
    expect(tab.wsPath).not.toContain('lab-token')
    expect(consoleState.tabs).toEqual([tab])
    expect(consoleState.active).toBe(tab.id)
  })

  it('ignores a legacy token in ws_path', async () => {
    const tab = await openConsole('lab1', { id: '1', name: 'R1' }, undefined)

    expect(tab.token).toBe('lab-token')
    expect(tab.wsPath).toBe('/ws/console?lab=lab1&node=2')
  })

  it('keeps a clean ws_path unchanged and accepts a token-only legacy path', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => consoleResponse({ ws_path: '/ws/console' })))
    const clean = await openConsole('lab1', { id: '1' })
    expect(clean.wsPath).toBe('/ws/console')

    vi.stubGlobal('fetch', vi.fn(async () => consoleResponse({ ws_path: '/ws/console?token=lab-token' })))
    const legacy = await openConsole('lab2', { id: '2' })
    expect(legacy.wsPath).toBe('/ws/console')
    expect(legacy.token).toBe('lab-token')
  })

  it('reuses the tab for the same lab and node and keeps close/activate behaviour', async () => {
    const first = await openConsole('lab1', { id: '1', name: 'R1' })
    const second = await openConsole('lab1', { id: '1' }, 'Renamed')

    expect(second.id).toBe(first.id)
    expect(consoleState.tabs).toHaveLength(1)
    expect(consoleState.active).toBe(first.id)

    closeConsole(first.id)
    expect(consoleState.tabs).toHaveLength(0)
    expect(consoleState.active).toBe(null)

    const third = await openConsole('lab1', { id: '2', name: 'R2' })
    const fourth = await openConsole('lab1', { id: '3', name: 'R3' })
    activateConsole(third.id)
    expect(consoleState.active).toBe(third.id)
    expect(fourth.id).not.toBe(third.id)
  })
})
