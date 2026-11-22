// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../src/api', () => ({
  api: {
    health: vi.fn(),
    guest: vi.fn(),
    login: vi.fn()
  },
  setCsrf: vi.fn(),
  ApiError: class ApiError extends Error {}
}))

vi.mock('../src/stores/session', () => ({
  login: vi.fn(),
  guestLogin: vi.fn()
}))

vi.mock('../src/stores/toasts', () => ({ notify: vi.fn() }))

vi.mock('../src/turnstile', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, loadTurnstile: vi.fn() }
})

import { api } from '../src/api'
import { guestLogin, login } from '../src/stores/session'
import { loadTurnstile } from '../src/turnstile'
import LoginView from '../src/views/LoginView.vue'

// Cloudflare test keys are used to keep the fixtures honest, but the view must
// not care which one it receives: it only waits for the render callback token.
const ALWAYS_PASS_KEY = '1x00000000000000000000AA'
const INTERACTIVE_KEY = '3x00000000000000000000FF'

beforeEach(() => {
  vi.resetAllMocks()
  delete window.turnstile
  api.health.mockResolvedValue({
    status: 'ok',
    demo: { enabled: false, landing: 'login' },
    turnstile: { enabled: false, site_key: '' }
  })
  guestLogin.mockResolvedValue({ user: { id: 'guest1', role: 'editor' }, csrf_token: 'csrf' })
  login.mockResolvedValue({ user: { id: 'user1', role: 'admin' }, csrf_token: 'csrf' })
})

afterEach(() => {
  vi.useRealTimers()
  delete window.turnstile
})

// The view receives the real Cloudflare Turnstile API shape. window.turnstile
// is stubbed because the loader returns it directly when present.
function fakeTurnstile({ execute = true, autoToken = '', executeToken = '', onExecute } = {}) {
  const state = { options: null }
  const widget = {
    render: vi.fn((_element, options) => {
      state.options = options
      if (autoToken) setTimeout(() => options.callback(autoToken), 0)
      return 'widget-1'
    }),
    reset: vi.fn(),
    remove: vi.fn()
  }
  if (execute) {
    widget.execute = vi.fn((widgetId) => {
      if (onExecute === 'throw') throw new Error('Call to execute() on a widget that is already executing')
      if (executeToken) setTimeout(() => state.options.callback(executeToken), 0)
      if (typeof onExecute === 'function') onExecute(widgetId)
    })
  }
  window.turnstile = widget
  loadTurnstile.mockImplementation(() => Promise.resolve(window.turnstile))
  return {
    widget,
    token: (value) => state.options.callback(value),
    fail: () => state.options['error-callback'](),
    options: () => state.options
  }
}

async function mountLogin(url = '/login') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginView },
      { path: '/setup', name: 'setup', component: { template: '<div class="setup-stub" />' } },
      { path: '/labs', name: 'labs', component: { template: '<div class="labs-stub" />' } },
      { path: '/labs/:id', name: 'editor', component: { template: '<div class="editor-stub" />' } }
    ]
  })
  await router.push(url)
  await router.isReady()
  const wrapper = mount(LoginView, { global: { plugins: [router] } })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

function healthWith({ demoEnabled = false, landing = 'login', siteKey = ALWAYS_PASS_KEY } = {}) {
  api.health.mockResolvedValue({
    demo: { enabled: demoEnabled, landing },
    turnstile: { enabled: true, site_key: siteKey }
  })
}

describe('LoginView', () => {
  it('hides the demo surface when demo mode is disabled', async () => {
    const { wrapper } = await mountLogin()
    expect(wrapper.find('[data-test="demo-panel"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="credentials-panel"]').classes()).toContain('is-primary')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Sign in')
    expect(wrapper.find('[data-test="turnstile"]').exists()).toBe(false)
  })

  it('replaces the query and emphasises the demo panel when landing is demo', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'demo' },
      turnstile: { enabled: false, site_key: '' }
    })
    const { wrapper, router } = await mountLogin('/login?next=/labs/lab1')
    expect(router.currentRoute.value.query).toMatchObject({ next: '/labs/lab1', mode: 'demo' })
    expect(wrapper.get('[data-test="demo-panel"]').classes()).toContain('is-primary')
    expect(wrapper.get('[data-test="credentials-panel"]').classes()).not.toContain('is-primary')
    expect(wrapper.get('button[data-test="enter-demo"]').text()).toBe('Enter demo')
  })

  it('keeps the credential form primary when landing is login', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'login' },
      turnstile: { enabled: false, site_key: '' }
    })
    const { wrapper, router } = await mountLogin()
    expect(router.currentRoute.value.query.mode).toBeUndefined()
    expect(wrapper.get('[data-test="demo-panel"]').classes()).not.toContain('is-primary')
    expect(wrapper.get('[data-test="credentials-panel"]').classes()).toContain('is-primary')
    expect(wrapper.find('[data-test="demo-panel"]').exists()).toBe(true)
  })

  it('enters the demo and redirects to next without a captcha token when disabled', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'demo' },
      turnstile: { enabled: false, site_key: '' }
    })
    const { wrapper, router } = await mountLogin('/login?next=/labs/lab1')
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('')
    expect(router.currentRoute.value.fullPath).toBe('/labs/lab1')
  })

  it('sends a token the widget delivers on its own without executing it', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: ALWAYS_PASS_KEY })
    const { widget } = fakeTurnstile({ autoToken: 'guest-token' })
    const { wrapper, router } = await mountLogin()
    expect(widget.render).toHaveBeenCalledTimes(1)
    expect(widget.render.mock.calls[0][1].sitekey).toBe(ALWAYS_PASS_KEY)
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).not.toHaveBeenCalled()
    expect(guestLogin).toHaveBeenCalledWith('guest-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('executes a widget without a token and sends the callback token', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INTERACTIVE_KEY })
    const { widget, options } = fakeTurnstile({ executeToken: 'interactive-token' })
    const { wrapper, router } = await mountLogin()
    expect(widget.render.mock.calls[0][1].sitekey).toBe(INTERACTIVE_KEY)
    expect(options().callback).toBeTypeOf('function')

    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledTimes(1)
    expect(widget.execute).toHaveBeenCalledWith('widget-1')
    // The submit waits for the render callback rather than framing the request.
    expect(guestLogin).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('interactive-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('executes the widget before a credential sign-in too', async () => {
    healthWith({ landing: 'login', siteKey: INTERACTIVE_KEY })
    const { widget } = fakeTurnstile({ executeToken: 'login-token' })
    const { wrapper, router } = await mountLogin('/login?next=/labs')

    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('input[autocomplete="username"]').setValue('admin')
    await wrapper.get('input[type="password"]').setValue('S3cret!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledWith('widget-1')
    expect(login).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()
    expect(login).toHaveBeenCalledWith('admin', 'S3cret!', 'login-token')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('fails closed and keeps submits disabled when the script cannot load', async () => {
    healthWith({ demoEnabled: true, landing: 'demo' })
    loadTurnstile.mockRejectedValue(new Error('blocked'))
    const { wrapper } = await mountLogin()
    expect(wrapper.get('[data-test="turnstile-error"]').text()).toMatch(/could not be loaded/i)
    expect(wrapper.get('button[data-test="enter-demo"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(login).not.toHaveBeenCalled()
  })

  it('fails closed without a request when execute throws and no callback arrives', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INTERACTIVE_KEY })
    const { widget } = fakeTurnstile({ onExecute: 'throw' })
    const { wrapper } = await mountLogin()

    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledTimes(1)
    expect(widget.execute).toHaveBeenCalledWith('widget-1')
    expect(guestLogin).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(15000)
    await flushPromises()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
  })

  it('resets the widget after a failed guest entry and does not reuse its token', async () => {
    healthWith({ demoEnabled: true, landing: 'demo' })
    const { widget, token } = fakeTurnstile()
    guestLogin.mockRejectedValueOnce(new Error('The demo is full.'))
    const { wrapper } = await mountLogin()
    token('guest-token')
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('guest-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(wrapper.get('[data-test="auth-error"]').text()).toBe('The demo is full.')

    // The spent token is gone: the retry executes the widget and waits for a
    // fresh callback instead of resubmitting the old value.
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledTimes(1)
    expect(guestLogin).toHaveBeenCalledTimes(1)

    token('fresh-token')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledTimes(2)
    expect(guestLogin).toHaveBeenLastCalledWith('fresh-token')
  })

  it('fails closed when the challenge reports an error', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INTERACTIVE_KEY })
    const { widget, fail } = fakeTurnstile()
    const { wrapper } = await mountLogin()
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledTimes(1)

    fail()
    await flushPromises()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/security check failed/i)
    expect(wrapper.get('button[data-test="enter-demo"]').attributes('disabled')).toBeDefined()
  })

  it('fails closed when the challenge times out', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INTERACTIVE_KEY })
    const { widget } = fakeTurnstile()
    const { wrapper } = await mountLogin()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(15000)
    await flushPromises()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(wrapper.get('button[data-test="enter-demo"]').attributes('disabled')).toBeUndefined()
  })

  it('fails closed when the widget cannot execute', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INTERACTIVE_KEY })
    const { widget } = fakeTurnstile({ execute: false })
    const { wrapper } = await mountLogin()
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toBeUndefined()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
  })
})
