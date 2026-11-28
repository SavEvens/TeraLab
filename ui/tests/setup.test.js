// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../src/api', () => ({
  api: {
    health: vi.fn(),
    setup: vi.fn()
  },
  setCsrf: vi.fn(),
  ApiError: class ApiError extends Error {}
}))

vi.mock('../src/stores/toasts', () => ({ notify: vi.fn() }))

import { api } from '../src/api'
import { session } from '../src/stores/session'
import { notify } from '../src/stores/toasts'
import SetupView from '../src/views/SetupView.vue'

const sessionPayload = {
  user: { id: 'user1', username: 'admin', role: 'admin' },
  csrf_token: 'csrf-setup'
}

const apiError = (status, message) => Object.assign(new Error(message), { status })

async function mountSetup() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/setup', name: 'setup', component: SetupView },
      { path: '/login', name: 'login', component: { template: '<div class="login-stub" />' } },
      { path: '/labs', name: 'labs', component: { template: '<div class="labs-stub" />' } }
    ]
  })
  await router.push('/setup')
  await router.isReady()
  const wrapper = mount(SetupView, { global: { plugins: [router] } })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

async function fillForm(wrapper, { token = 'setup-token-1', password = 'S3cret!' } = {}) {
  await wrapper.get('input[aria-label="Setup token"]').setValue(token)
  await wrapper.get('input[autocomplete="email"]').setValue('ops@example.test')
  await wrapper.get('input[autocomplete="new-password"]').setValue(password)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  vi.resetAllMocks()
  session.ready = false
  session.user = null
  session.csrf = ''
  api.health.mockResolvedValue({
    status: 'ok',
    turnstile: { enabled: false, site_key: '', test_keys: false }
  })
  api.setup.mockResolvedValue(sessionPayload)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('SetupView', () => {
  it('requires an operator token and keeps the existing credential rules', async () => {
    const { wrapper } = await mountSetup()

    const token = wrapper.get('input[aria-label="Setup token"]')
    expect(token.attributes('type')).toBe('password')
    expect(token.attributes('autocomplete')).toBe('off')
    expect(token.attributes('required')).toBeDefined()
    expect(wrapper.get('input[autocomplete="username"]').attributes('required')).toBeDefined()
    expect(wrapper.get('input[autocomplete="new-password"]').attributes('required')).toBeDefined()
    expect(wrapper.get('input[autocomplete="email"]').attributes('type')).toBe('email')
    wrapper.unmount()
  })

  it('submits the token with the administrator details and enters the app', async () => {
    const { wrapper, router } = await mountSetup()
    await fillForm(wrapper)

    expect(api.setup).toHaveBeenCalledTimes(1)
    expect(api.setup).toHaveBeenCalledWith(
      { username: 'admin', password: 'S3cret!', email: 'ops@example.test' },
      'setup-token-1'
    )
    expect(notify).toHaveBeenCalledWith('success', 'Administrator created')
    expect(router.currentRoute.value.name).toBe('labs')
    // The single-use token is not retained after a successful setup.
    expect(wrapper.get('input[aria-label="Setup token"]').element.value).toBe('')
    wrapper.unmount()
  })

  it('shows the server error when the token is missing', async () => {
    const { wrapper } = await mountSetup()
    api.setup.mockRejectedValue(apiError(401, 'Setup token is required.'))

    await wrapper.get('input[autocomplete="new-password"]').setValue('S3cret!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    // The browser marks the field required, but the server stays authoritative:
    // an empty submission is attempted and its 401 is shown with context.
    expect(api.setup).toHaveBeenCalledWith(
      { username: 'admin', password: 'S3cret!', email: '' },
      ''
    )
    expect(wrapper.get('[data-test="setup-error"]').text()).toMatch(/setup token is missing or invalid/i)
    wrapper.unmount()
  })

  it('shows a contextual token error for a missing or rejected token', async () => {
    const { wrapper, router } = await mountSetup()
    api.setup
      .mockRejectedValueOnce(apiError(401, 'Setup token is required.'))
      .mockRejectedValueOnce(apiError(403, 'Setup token is invalid.'))

    await fillForm(wrapper)
    let message = wrapper.get('[data-test="setup-error"]').text()
    expect(message).toMatch(/setup token is missing or invalid/i)
    expect(message).toContain('/etc/teralab/setup.token')
    expect(router.currentRoute.value.name).toBe('setup')

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    message = wrapper.get('[data-test="setup-error"]').text()
    expect(message).toMatch(/setup token is missing or invalid/i)
    expect(api.setup).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('reports that setup was already completed with a link to sign in', async () => {
    const { wrapper, router } = await mountSetup()
    api.setup.mockRejectedValue(apiError(409, 'Setup has already been completed.'))

    await fillForm(wrapper)

    const message = wrapper.get('[data-test="setup-error"]').text()
    expect(message).toMatch(/setup has already been completed/i)
    expect(message).toMatch(/sign in/i)
    expect(wrapper.get('[data-test="setup-login-link"] a').attributes('href')).toBe('/login')
    expect(router.currentRoute.value.name).toBe('setup')
    wrapper.unmount()
  })

  it('warns when health reports the shipped Turnstile test keys', async () => {
    api.health.mockResolvedValue({
      status: 'ok',
      turnstile: { enabled: true, site_key: '1x00000000000000000000AA', test_keys: true }
    })
    const { wrapper } = await mountSetup()

    const warning = wrapper.get('[data-test="turnstile-test-key-warning"]')
    expect(warning.text()).toMatch(/public test keys/i)
    expect(warning.text()).toMatch(/not production-ready/i)
    wrapper.unmount()
  })

  it('does not warn about test keys when the API reports real keys', async () => {
    const { wrapper } = await mountSetup()
    expect(wrapper.find('[data-test="turnstile-test-key-warning"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
