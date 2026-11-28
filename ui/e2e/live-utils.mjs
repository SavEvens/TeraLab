// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors
//
// Small, dependency-free helpers shared by the live and Apache E2E suites.
// They live outside the suites' top-level code so the unit tests can exercise
// environment parsing without a network, a browser, or a live host.

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/** Repository TERALAB_VERSION, resolved from ui/e2e/live-utils.mjs. */
export function repositoryVersionFile() {
  return fileURLToPath(new URL('../../TERALAB_VERSION', import.meta.url))
}

const SECRET_ENV = 'TERALAB_ADMIN_PASSWORD'
const SECRET_FILE_ENV = 'TERALAB_ADMIN_PASSWORD_FILE'

/**
 * Read a password from a file, preserving internal spaces and stripping only
 * the line terminator a text editor or `echo` would add.
 */
export function readSecretFile(file) {
  const text = readFileSync(file, 'utf8')
  return text.replace(/(?:\r?\n)+$/, '')
}

/**
 * Resolve the admin password from TERALAB_ADMIN_PASSWORD or
 * TERALAB_ADMIN_PASSWORD_FILE. There is deliberately no default: a live host
 * credential must never be baked into the repository.
 *
 * @param {Record<string, string | undefined>} env
 * @returns {{ password: string, source: string }}
 */
export function adminPassword(env = process.env) {
  const direct = env[SECRET_ENV]
  if (typeof direct === 'string' && direct !== '') {
    return { password: direct, source: SECRET_ENV }
  }

  const file = env[SECRET_FILE_ENV]
  if (typeof file === 'string' && file !== '') {
    const password = readSecretFile(file)
    if (password === '') {
      throw new Error(`${SECRET_FILE_ENV} (${file}) is empty`)
    }
    return { password, source: SECRET_FILE_ENV }
  }

  throw new Error(
    `admin password required: set ${SECRET_ENV} or ${SECRET_FILE_ENV} (no built-in default)`
  )
}

/**
 * Expected product version: TERALAB_EXPECT_VERSION wins, otherwise the
 * repository TERALAB_VERSION file. A missing or empty value is a hard error,
 * because comparing against an invented version would hide drift.
 */
export function expectedVersion(env = process.env, versionFile = repositoryVersionFile()) {
  const explicit = env.TERALAB_EXPECT_VERSION
  if (typeof explicit === 'string' && explicit.trim() !== '') {
    return explicit.trim()
  }

  let text
  try {
    text = readFileSync(versionFile, 'utf8')
  } catch (error) {
    throw new Error(`cannot read TERALAB_EXPECT_VERSION or ${versionFile}: ${error.message}`)
  }
  const version = text.trim()
  if (version === '') throw new Error(`${versionFile} is empty`)
  return version
}

/**
 * Remove every occurrence of the given secrets from a string before it is
 * logged or written to evidence. Longest secrets first so a secret that
 * contains another one cannot leave a suffix behind.
 */
export function redact(text, secrets = []) {
  let result = String(text)
  for (const secret of [...secrets]
    .filter((value) => typeof value === 'string' && value !== '')
    .sort((left, right) => right.length - left.length)) {
    result = result.split(secret).join('[redacted]')
  }
  return result
}

/** Security headers Apache must send for /ui/ (see packaging/teralab/teralab.apache.conf). */
export const REQUIRED_UI_HEADERS = [
  'content-security-policy',
  'x-frame-options',
  'x-content-type-options',
  'referrer-policy'
]

/**
 * @param {{ get(name: string): string | null }} headers
 * @returns {string[]} required header names that are absent or empty
 */
export function missingSecurityHeaders(headers) {
  return REQUIRED_UI_HEADERS.filter((name) => {
    const value = headers.get(name)
    return typeof value !== 'string' || value.trim() === ''
  })
}

/** True when a lab nodes payload reports the node as running or ready. */
export function nodeIsUp(nodesPayload, nodeId = '1') {
  const nodes = Array.isArray(nodesPayload?.nodes) ? nodesPayload.nodes : []
  return nodes.some((entry) => {
    if (nodeId !== null && String(entry?.node_id) !== String(nodeId)) return false
    return entry?.ready === true || ['running', 'ready'].includes(String(entry?.state))
  })
}
