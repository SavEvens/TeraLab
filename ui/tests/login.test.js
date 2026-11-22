// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { beforeEach, describe, expect, it, vi } from 'vitest'
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

vi.mock('../src/turnstile', () => ({ loadTurnstile: vi.fn() }))

import { api } from '../src/api'
import { guestLogin, login } from '../src/stores/session'
import { loadTurnstile } from '../src/turnstile'
import LoginView from '../src/views/LoginView.vue'

beforeEach(() => {
  vi.resetAllMocks()
  api.health.mockResolvedValue({
    status: 'ok',
    demo: { enabled: false, landing: 'login' },
    turnstile: { enabled: false, site_key: '' }
  })
  guestLogin.mockResolvedValue({ user: { id: 'guest1', role: 'editor' }, csrf_token: 'csrf' })
  login.mockResolvedValue({ user: { id: 'user1', role: 'admin' }, csrf_token: 'csrf' })
})

function fakeTurnstile() {
  const state = { options: null }
  const widget = {
    render: vi.fn((element, options) => {
      state.options = options
      return 'widget-1'
    }),
    reset: vi.fn(),
    remove: vi.fn()
  }
  loadTurnstile.mockResolvedValue(widget)
  return { widget, token: (value) => state.options.callback(value) }
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

  it('renders one widget and sends its token with the guest submit', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'demo' },
      turnstile: { enabled: true, site_key: 'site-key-1' }
    })
    const { widget, token } = fakeTurnstile()
    const { wrapper, router } = await mountLogin()
    expect(widget.render).toHaveBeenCalledTimes(1)
    expect(widget.render.mock.calls[0][1].sitekey).toBe('site-key-1')
    token('guest-token')
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('guest-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('sends the captcha token with a credential login', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: false, landing: 'login' },
      turnstile: { enabled: true, site_key: 'site-key-1' }
    })
    const { token } = fakeTurnstile()
    const { wrapper, router } = await mountLogin('/login?next=/labs')
    token('login-token')
    await wrapper.get('input[autocomplete="username"]').setValue('admin')
    await wrapper.get('input[type="password"]').setValue('S3cret!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(login).toHaveBeenCalledWith('admin', 'S3cret!', 'login-token')
    expect(router.currentRoute.value.name).toBe('labs')
  })

  it('fails closed and keeps submits disabled when the script cannot load', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'demo' },
      turnstile: { enabled: true, site_key: 'site-key-1' }
    })
    loadTurnstile.mockRejectedValue(new Error('blocked'))
    const { wrapper } = await mountLogin()
    expect(wrapper.get('[data-test="turnstile-error"]').text()).toMatch(/could not be loaded/i)
    expect(wrapper.get('button[data-test="enter-demo"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(guestLogin).not.toHaveBeenCalled()
    expect(login).not.toHaveBeenCalled()
  })

  it('refuses to submit a captcha-gated action without a token', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'demo' },
      turnstile: { enabled: true, site_key: 'site-key-1' }
    })
    fakeTurnstile()
    const { wrapper } = await mountLogin()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(login).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="auth-error"]').text()).toMatch(/complete the security check/i)
  })

  it('resets the widget after a failed guest entry', async () => {
    api.health.mockResolvedValue({
      demo: { enabled: true, landing: 'demo' },
      turnstile: { enabled: true, site_key: 'site-key-1' }
    })
    const { widget, token } = fakeTurnstile()
    guestLogin.mockRejectedValue(new Error('The demo is full.'))
    const { wrapper } = await mountLogin()
    token('guest-token')
    await wrapper.get('button[data-test="enter-demo"]').trigger('click')
    await flushPromises()
    expect(guestLogin).toHaveBeenCalledWith('guest-token')
    expect(widget.reset).toHaveBeenCalledWith('widget-1')
    expect(wrapper.get('[data-test="auth-error"]').text()).toBe('The demo is full.')
  })
})
