// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { openConsole, closeConsole, activateConsole, consoleState } from '../src/stores/consoles'

function consoleResponse() {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({
      data: { kind: 'telnet', ws_path: '/ws/console?token=lab-token', expires_in: 300, password: '' }
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
      wsPath: '/ws/console?token=lab-token',
      password: ''
    })
    expect(consoleState.tabs).toEqual([tab])
    expect(consoleState.active).toBe(tab.id)
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
