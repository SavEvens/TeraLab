// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import {
  adminPassword,
  expectedVersion,
  missingSecurityHeaders,
  nodeIsUp,
  redact
} from '../e2e/live-utils.mjs'

const temporaryDirectories = []

function temporaryFile(contents) {
  const directory = mkdtempSync(path.join(os.tmpdir(), 'teralab-live-utils-'))
  temporaryDirectories.push(directory)
  const file = path.join(directory, 'value')
  writeFileSync(file, contents)
  return file
}

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    rmSync(directory, { recursive: true, force: true })
  }
})

describe('adminPassword', () => {
  it('uses TERALAB_ADMIN_PASSWORD before the password file', () => {
    const file = temporaryFile('from-file\n')
    expect(adminPassword({
      TERALAB_ADMIN_PASSWORD: 'from-env',
      TERALAB_ADMIN_PASSWORD_FILE: file
    })).toEqual({ password: 'from-env', source: 'TERALAB_ADMIN_PASSWORD' })
  })

  it('reads the password file and strips only line terminators', () => {
    const file = temporaryFile('two words  \r\n\n')
    expect(adminPassword({ TERALAB_ADMIN_PASSWORD_FILE: file })).toEqual({
      password: 'two words  ',
      source: 'TERALAB_ADMIN_PASSWORD_FILE'
    })
  })

  it('falls back to the file when the direct variable is empty', () => {
    const file = temporaryFile('secret\n')
    expect(adminPassword({
      TERALAB_ADMIN_PASSWORD: '',
      TERALAB_ADMIN_PASSWORD_FILE: file
    }).password).toBe('secret')
  })

  it('refuses an empty password file', () => {
    const file = temporaryFile('\n')
    expect(() => adminPassword({ TERALAB_ADMIN_PASSWORD_FILE: file })).toThrow(/is empty/)
  })

  it('has no built-in default', () => {
    expect(() => adminPassword({})).toThrow(/TERALAB_ADMIN_PASSWORD or TERALAB_ADMIN_PASSWORD_FILE/)
  })
})

describe('expectedVersion', () => {
  it('prefers and trims TERALAB_EXPECT_VERSION', () => {
    expect(expectedVersion({ TERALAB_EXPECT_VERSION: ' 1.6-dev\n' }, '/nonexistent')).toBe('1.6-dev')
  })

  it('reads the repository version file when the environment is silent', () => {
    const file = temporaryFile('1.7-dev\n')
    expect(expectedVersion({}, file)).toBe('1.7-dev')
  })

  it('falls back to the file for an empty or whitespace-only override', () => {
    const file = temporaryFile('1.7-dev\n')
    expect(expectedVersion({ TERALAB_EXPECT_VERSION: '   ' }, file)).toBe('1.7-dev')
  })

  it('fails clearly when neither source has a version', () => {
    const file = temporaryFile('\n')
    expect(() => expectedVersion({}, file)).toThrow(/is empty/)
    expect(() => expectedVersion({}, path.join(file, 'missing'))).toThrow(/cannot read/)
  })
})

describe('redact', () => {
  it('removes every occurrence of every secret', () => {
    const text = 'password=abc token=xyz abcdef abc'
    expect(redact(text, ['abc', 'xyz'])).toBe('password=[redacted] token=[redacted] [redacted]def [redacted]')
  })

  it('replaces longer secrets first so no suffix survives', () => {
    expect(redact('prefix-supersecret-suffix', ['secret', 'supersecret'])).toBe('prefix-[redacted]-suffix')
  })

  it('ignores empty or non-string secrets', () => {
    expect(redact('value', ['', null, undefined, 7])).toBe('value')
  })

  it('treats secrets as literal text, not regular expressions', () => {
    expect(redact('a.b aXb', ['a.b'])).toBe('[redacted] aXb')
  })
})

describe('missingSecurityHeaders', () => {
  const headers = (values) => ({ get: (name) => values[name] ?? null })

  it('accepts the full header set Apache sends for /ui/', () => {
    expect(missingSecurityHeaders(headers({
      'content-security-policy': "default-src 'self'",
      'x-frame-options': 'DENY',
      'x-content-type-options': 'nosniff',
      'referrer-policy': 'no-referrer'
    }))).toEqual([])
  })

  it('reports absent and empty headers', () => {
    expect(missingSecurityHeaders(headers({
      'content-security-policy': 'default-src \'self\'',
      'x-frame-options': ' ',
      'referrer-policy': 'no-referrer'
    }))).toEqual(['x-frame-options', 'x-content-type-options'])
  })
})

describe('nodeIsUp', () => {
  it('accepts ready or running states for the requested node', () => {
    expect(nodeIsUp({ nodes: [{ node_id: '1', state: 'stopped', ready: true }] }, '1')).toBe(true)
    expect(nodeIsUp({ nodes: [{ node_id: '1', state: 'running', ready: false }] }, '1')).toBe(true)
  })

  it('rejects other nodes and inactive states', () => {
    expect(nodeIsUp({ nodes: [{ node_id: '2', state: 'running', ready: true }] }, '1')).toBe(false)
    expect(nodeIsUp({ nodes: [{ node_id: '1', state: 'created', ready: false }] }, '1')).toBe(false)
    expect(nodeIsUp(null, '1')).toBe(false)
  })
})
