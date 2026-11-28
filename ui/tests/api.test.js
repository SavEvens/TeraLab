// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { afterEach, describe, expect, it, vi } from 'vitest'
import { api } from '../src/api'

const jsonResponse = (payload) => ({
  ok: true,
  status: 200,
  text: () => Promise.resolve(JSON.stringify(payload))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('api.setup', () => {
  it('sends the operator token as a header and JSON field', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ user: { id: 'u1' }, csrf_token: 'csrf' }))
    vi.stubGlobal('fetch', fetchMock)

    await api.setup({ username: 'admin', password: 'S3cret!', email: 'ops@example.test' }, 'token-123')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/v2/setup')
    expect(options.method).toBe('POST')
    expect(options.headers['X-TeraLab-Setup-Token']).toBe('token-123')
    expect(JSON.parse(options.body)).toEqual({
      username: 'admin',
      password: 'S3cret!',
      email: 'ops@example.test',
      setup_token: 'token-123'
    })
  })

  it('does not send token material when no token is supplied', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ user: { id: 'u1' }, csrf_token: 'csrf' }))
    vi.stubGlobal('fetch', fetchMock)

    await api.setup({ username: 'admin', password: 'S3cret!', email: '' })

    const [, options] = fetchMock.mock.calls[0]
    expect(options.headers['X-TeraLab-Setup-Token']).toBeUndefined()
    expect(JSON.parse(options.body)).toEqual({ username: 'admin', password: 'S3cret!', email: '' })
  })
})
