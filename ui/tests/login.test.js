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

const INVISIBLE_KEY = '3x00000000000000000000FF'
const MANAGED_KEY = 'site-key-1'

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
function fakeTurnstile({ execute = true } = {}) {
  const state = { options: null }
  const widget = {
    render: vi.fn((_element, options) => {
      state.options = options
      return 'widget-1'
    }),
    reset: vi.fn(),
    remove: vi.fn()
  }
  if (execute) widget.execute = vi.fn()
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

function healthWith({ demoEnabled = false, landing = 'login', siteKey = MANAGED_KEY } = {}) {
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

  it('sends a managed widget callback token without executing it', async () => {
    healthWith({ demoEnabled: true, landing: 'demo' })
    const { widget, token } = fakeTurnstile()
    const { wrapper, router } = await mountLogin()
    expect(widget.render).toHaveBeenCalledTimes(1)
    expect(widget.render.mock.calls[0][1].sitekey).toBe(MANAGED_KEY)
    token('guest-token')
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).not.toHaveBeenCalled()
    expect(guestLogin).toHaveBeenCalledWith('guest-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('sends the captcha token with a credential login', async () => {
    healthWith({ landing: 'login' })
    const { widget, token } = fakeTurnstile()
    const { wrapper, router } = await mountLogin('/login?next=/labs')
    token('login-token')
    await wrapper.get('input[autocomplete="username"]').setValue('admin')
    await wrapper.get('input[type="password"]').setValue('S3cret!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(widget.execute).not.toHaveBeenCalled()
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

  it('refuses to submit a managed captcha-gated action without a token', async () => {
    healthWith({ demoEnabled: true, landing: 'demo' })
    const { widget } = fakeTurnstile()
    const { wrapper } = await mountLogin()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(widget.execute).not.toHaveBeenCalled()
    expect(login).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
  })

  it('resets the widget after a failed guest entry and does not reuse its token', async () => {
    healthWith({ demoEnabled: true, landing: 'demo' })
    const { widget, token } = fakeTurnstile()
    guestLogin.mockRejectedValue(new Error('The demo is full.'))
    const { wrapper } = await mountLogin()
    token('guest-token')
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('guest-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(wrapper.get('[data-test="auth-error"]').text()).toBe('The demo is full.')

    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
  })

  it('executes an invisible widget and sends the token from its callback', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INVISIBLE_KEY })
    const { widget, token, options } = fakeTurnstile()
    const { wrapper, router } = await mountLogin()
    expect(widget.render.mock.calls[0][1].sitekey).toBe(INVISIBLE_KEY)
    expect(options().callback).toBeTypeOf('function')

    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledTimes(1)
    expect(widget.execute).toHaveBeenCalledWith('widget-1')
    expect(guestLogin).not.toHaveBeenCalled()
    expect(wrapper.get('button[data-test="enter-demo"]').attributes('disabled')).toBeDefined()

    token('invisible-token')
    await flushPromises()
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('invisible-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('executes an invisible widget for credential sign-in too', async () => {
    healthWith({ landing: 'login', siteKey: INVISIBLE_KEY })
    const { widget, token } = fakeTurnstile()
    const { wrapper, router } = await mountLogin('/login?next=/labs')
    await wrapper.get('input[autocomplete="username"]').setValue('admin')
    await wrapper.get('input[type="password"]').setValue('S3cret!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(widget.execute).toHaveBeenCalledWith('widget-1')
    expect(login).not.toHaveBeenCalled()

    token('login-token')
    await flushPromises()
    await flushPromises()
    expect(login).toHaveBeenCalledWith('admin', 'S3cret!', 'login-token')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('fails closed when an invisible challenge reports an error', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INVISIBLE_KEY })
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

  it('fails closed when an invisible challenge times out', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INVISIBLE_KEY })
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

  it('fails closed when an invisible widget cannot execute', async () => {
    healthWith({ demoEnabled: true, landing: 'demo', siteKey: INVISIBLE_KEY })
    const { widget } = fakeTurnstile({ execute: false })
    const { wrapper } = await mountLogin()
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(widget.execute).toBeUndefined()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
  })
})
