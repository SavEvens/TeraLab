// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const terminalMock = vi.hoisted(() => ({ writes: [] }))
const rfbMock = vi.hoisted(() => ({ instances: [] }))

vi.mock('@xterm/xterm', () => ({
  Terminal: class {
    open() {}
    loadAddon() {}
    writeln(text) { terminalMock.writes.push(text) }
    write() {}
    attachCustomKeyEventHandler() {}
    onData() {}
    getSelection() { return '' }
    paste() {}
    dispose() {}
  }
}))

vi.mock('@xterm/addon-fit', () => ({
  FitAddon: class {
    fit() {}
  }
}))

vi.mock('@novnc/novnc', () => ({
  default: class FakeRFB {
    constructor(target, url, options) {
      this.target = target
      this.url = url
      this.options = options
      this.listeners = {}
      rfbMock.instances.push(this)
    }
    addEventListener(name, callback) {
      ;(this.listeners[name] ||= []).push(callback)
    }
    dispatch(name, event) {
      for (const callback of this.listeners[name] || []) callback(event)
    }
    disconnect() {}
    sendCredentials() {}
  }
}))

import TelnetConsole from '../src/consoles/TelnetConsole.vue'
import VncConsole from '../src/consoles/VncConsole.vue'
import { toasts, dismiss } from '../src/stores/toasts'

class FakeSocket {
  static CONNECTING = 0
  static OPEN = 1
  static CLOSING = 2
  static CLOSED = 3
  static instances = []

  constructor(url, protocols) {
    this.url = url
    this.protocols = protocols
    this.readyState = FakeSocket.CONNECTING
    FakeSocket.instances.push(this)
  }

  send() {}
  close() { this.readyState = FakeSocket.CLOSED }
}

class ResizeObserverStub {
  observe() {}
  disconnect() {}
}

const TOKEN = 'deadbeef0123456789'
const PROTOCOL = `teralab.console.${TOKEN}`

let logs

beforeEach(() => {
  FakeSocket.instances = []
  terminalMock.writes = []
  rfbMock.instances = []
  for (const toast of [...toasts]) dismiss(toast.id)
  logs = []
  for (const level of ['log', 'info', 'warn', 'error', 'debug']) {
    vi.spyOn(console, level).mockImplementation((...args) => {
      logs.push(args.map((value) => String(value)).join(' '))
    })
  }
  vi.stubGlobal('WebSocket', FakeSocket)
  vi.stubGlobal('ResizeObserver', ResizeObserverStub)
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('telnet console connection', () => {
  it('sends the token as a WebSocket subprotocol and never in the URL', () => {
    const wrapper = mount(TelnetConsole, {
      props: { wsPath: '/ws/console?lab=lab1&node=2', token: TOKEN }
    })

    expect(FakeSocket.instances).toHaveLength(1)
    const socket = FakeSocket.instances[0]
    expect(socket.protocols).toEqual([PROTOCOL])
    expect(socket.url).toContain('/ws/console?lab=lab1&node=2')
    expect(socket.url).not.toContain(TOKEN)
    expect(socket.url).not.toMatch(/[?&]token=/i)
    expect(wrapper.html()).not.toContain(TOKEN)
    expect(document.body.innerHTML).not.toContain(TOKEN)
    expect(logs.join('\n')).not.toContain(TOKEN)
  })

  it('surfaces a clear message when the handshake is rejected', async () => {
    const wrapper = mount(TelnetConsole, {
      props: { wsPath: '/ws/console', token: TOKEN }
    })

    FakeSocket.instances[0].onclose()
    await nextTick()

    expect(wrapper.find('.console-status').text()).toBe('handshake rejected')
    expect(toasts.some((toast) => toast.kind === 'error'
      && /expired or the origin/.test(toast.message)
      && /Close and reopen the console/.test(toast.message))).toBe(true)
    expect(terminalMock.writes.join('\n')).toContain('close and reopen the console')
    expect(wrapper.html()).not.toContain(TOKEN)
  })

  it('does not connect with a URL token when the capability is missing', async () => {
    const wrapper = mount(TelnetConsole, {
      props: { wsPath: '/ws/console?token=legacy', token: '' }
    })
    await nextTick()

    expect(FakeSocket.instances).toHaveLength(0)
    expect(wrapper.find('.console-status').text()).toBe('token unavailable')
    expect(toasts.some((toast) => /Console token unavailable/.test(toast.message))).toBe(true)
  })
})

describe('vnc console connection', () => {
  it('passes the token as the noVNC wsProtocols option and keeps the URL clean', async () => {
    const wrapper = mount(VncConsole, {
      props: { wsPath: '/ws/vnc?node=2', token: TOKEN, password: 's3cret' }
    })
    await flushPromises()

    expect(rfbMock.instances).toHaveLength(1)
    const rfb = rfbMock.instances[0]
    expect(rfb.options.wsProtocols).toEqual([PROTOCOL])
    expect(rfb.options.credentials).toEqual({ password: 's3cret' })
    expect(rfb.url).toContain('/ws/vnc?node=2')
    expect(rfb.url).not.toContain(TOKEN)
    expect(rfb.url).not.toMatch(/[?&]token=/i)
    expect(wrapper.html()).not.toContain(TOKEN)
    expect(document.body.innerHTML).not.toContain(TOKEN)
    expect(logs.join('\n')).not.toContain(TOKEN)
  })

  it('reports a rejected VNC handshake without retrying an expired token', async () => {
    const wrapper = mount(VncConsole, {
      props: { wsPath: '/ws/vnc', token: TOKEN }
    })
    await flushPromises()

    rfbMock.instances[0].dispatch('disconnect', { detail: { clean: false } })
    await nextTick()

    expect(wrapper.find('.console-status').text()).toBe('handshake rejected')
    const error = wrapper.find('.vnc-wrap .error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toContain('VNC handshake rejected')
    expect(error.text()).toContain('Close and reopen the console')
    expect(toasts.some((toast) => /VNC handshake rejected/.test(toast.message))).toBe(true)
    expect(wrapper.html()).not.toContain(TOKEN)
  })

  it('does not create a VNC session without a valid capability', async () => {
    const wrapper = mount(VncConsole, {
      props: { wsPath: '/ws/vnc?token=legacy', token: '' }
    })
    await flushPromises()

    expect(rfbMock.instances).toHaveLength(0)
    expect(wrapper.find('.console-status').text()).toBe('token unavailable')
    expect(wrapper.find('.vnc-wrap .error').text()).toContain('Console token unavailable')
  })
})
