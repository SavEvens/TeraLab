// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../src/api', () => ({
  api: {
    guest: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
    setup: vi.fn()
  },
  setCsrf: vi.fn(),
  ApiError: class ApiError extends Error {}
}))

import { api, setCsrf } from '../src/api'
import { guestLogin, login, session } from '../src/stores/session'

beforeEach(() => {
  vi.resetAllMocks()
  session.ready = false
  session.user = null
  session.csrf = ''
})

const payload = { user: { id: 'guest1', username: 'g-0123456789abcdef', role: 'editor', guest: true }, csrf_token: 'csrf-guest', expires_in: 3600 }

describe('session store', () => {
  it('applies a guest session like a credential login', async () => {
    api.guest.mockResolvedValue(payload)
    const data = await guestLogin()
    expect(api.guest).toHaveBeenCalledWith({})
    expect(data).toBe(payload)
    expect(session.user).toEqual(payload.user)
    expect(session.csrf).toBe('csrf-guest')
    expect(setCsrf).toHaveBeenCalledWith('csrf-guest')
  })

  it('forwards a guest captcha token as cf-turnstile-response', async () => {
    api.guest.mockResolvedValue(payload)
    await guestLogin('guest-token')
    expect(api.guest).toHaveBeenCalledWith({ 'cf-turnstile-response': 'guest-token' })
  })

  it('forwards a login captcha token only when present', async () => {
    api.login.mockResolvedValue(payload)
    await login('admin', 'secret')
    expect(api.login).toHaveBeenCalledWith({ username: 'admin', password: 'secret' })
    await login('admin', 'secret', 'login-token')
    expect(api.login).toHaveBeenLastCalledWith({
      username: 'admin',
      password: 'secret',
      'cf-turnstile-response': 'login-token'
    })
  })
})
