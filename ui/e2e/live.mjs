// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors
//
// Live smoke for an installed TeraLab host (Apache + API + agent).
//
// It is intentionally read-only towards configuration: it creates one
// disposable VPCS lab with a unique name, exercises the API and console
// paths the UI depends on, visits the main screens in Chromium, and removes
// the lab again in `finally`. Credentials and console tokens never reach
// stdout or the evidence JSON.
//
// Environment:
//   TERALAB_LIVE_URL        default http://127.0.0.1
//   TERALAB_ADMIN_USER      default admin
//   TERALAB_ADMIN_PASSWORD  or TERALAB_ADMIN_PASSWORD_FILE (required)
//   TERALAB_EXPECT_VERSION  default: repository TERALAB_VERSION
//   TERALAB_E2E_OUT         artifact directory (outside the repository)
//   TERALAB_EVIDENCE        optional evidence JSON path (or directory)
//   TERALAB_CHROME          default /usr/bin/chromium
//
// Exit status is nonzero on any failed check. The JSON result is written to
// TERALAB_EVIDENCE when set and always printed to stdout.

import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, statSync, writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright-core'
import WebSocket from 'ws'
import {
  adminPassword,
  expectedVersion,
  missingSecurityHeaders,
  nodeIsUp,
  redact
} from './live-utils.mjs'

const REPO_ROOT = path.resolve(fileURLToPath(new URL('../..', import.meta.url)))
const base = (process.env.TERALAB_LIVE_URL || 'http://127.0.0.1').replace(/\/+$/, '')
const adminUser = process.env.TERALAB_ADMIN_USER || 'admin'
const outDir = path.resolve(process.env.TERALAB_E2E_OUT || path.join(os.tmpdir(), 'teralab-live-e2e'))
const chromePath = process.env.TERALAB_CHROME || '/usr/bin/chromium'
const startedAt = new Date()
const startedMs = Date.now()

const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14)
const suffix = randomBytes(4).toString('hex')
const labPrefix = `TeraLab live ${stamp}-${suffix}`

const checks = []
const pageErrors = []
const cleanup = { attempted: false, stopped: false, deleted: false, purged: false, gone: null }
let current = 'environment'
let failure = null
let failureDetail = ''
let session = null
let password = ''
let expectVersion = ''
let consoleToken = ''
let labId = ''
let browser = null
let socket = null

function secrets() {
  return [password, consoleToken, session?.csrf, session?.cookie].filter(
    (value) => typeof value === 'string' && value !== ''
  )
}

function safe(text) {
  return redact(text, secrets())
}

function pass(detail) {
  checks.push({ name: current, pass: true, ...(detail === undefined ? {} : { detail }) })
}

function next(name) {
  current = name
}

function assertOutDirOutsideRepo() {
  if (outDir === REPO_ROOT || outDir.startsWith(`${REPO_ROOT}${path.sep}`)) {
    throw new Error(`TERALAB_E2E_OUT must be outside the repository: ${outDir}`)
  }
  if (!/^https?:\/\//i.test(base)) {
    throw new Error(`TERALAB_LIVE_URL must be http(s): ${base}`)
  }
}

try {
  assertOutDirOutsideRepo()
  password = adminPassword(process.env).password
  expectVersion = expectedVersion(process.env)
} catch (error) {
  console.error(`LIVE_E2E_ENV_ERROR ${error.message}`)
  process.exit(1)
}
mkdirSync(outDir, { recursive: true })

async function api(method, apiPath, body, { timeout = 15000 } = {}) {
  const headers = { Accept: 'application/json' }
  if (session) {
    headers.Cookie = session.cookie
    headers['X-CSRF-Token'] = session.csrf
  }
  let payload = body
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }
  const response = await fetch(`${base}/api/v2${apiPath}`, {
    method,
    headers,
    body: payload,
    signal: AbortSignal.timeout(timeout)
  })
  const text = await response.text()
  let data = null
  try { data = text ? JSON.parse(text) : null } catch {}
  return { response, status: response.status, text, data }
}

async function call(method, apiPath, body) {
  const result = await api(method, apiPath, body)
  if (!result.response.ok) {
    throw new Error(`${method} ${apiPath}: ${result.status} ${safe(result.text.slice(0, 300))}`)
  }
  return result.data
}

function sessionCookie(response) {
  const cookies = response.headers.getSetCookie?.() || []
  const raw = cookies.find((value) => value.startsWith('teralab_session=')) || cookies[0] || ''
  const match = /^([^=]+)=([^;]*)/.exec(raw)
  return {
    cookie: match ? `${match[1]}=${match[2]}` : '',
    secure: /;\s*Secure(?:;|$)/i.test(raw)
  }
}

function closeSocket() {
  try { socket?.close() } catch {}
  socket = null
}

async function cleanupLab() {
  closeSocket()
  if (!labId || cleanup.gone === true) return
  cleanup.attempted = true

  try {
    const stopped = await api('POST', `/labs/${labId}/stop`)
    cleanup.stopped = stopped.response.ok || [404, 409].includes(stopped.status)
    if (!cleanup.stopped) cleanup.stopError = safe(`status ${stopped.status}`)
  } catch (error) {
    cleanup.stopError = safe(error.message)
  }

  try {
    const deleted = await api('DELETE', `/labs/${labId}`)
    cleanup.deleted = deleted.response.ok || deleted.status === 404
    if (!cleanup.deleted) cleanup.deleteError = safe(`status ${deleted.status}`)
  } catch (error) {
    cleanup.deleteError = safe(error.message)
  }

  // A lab delete moves the entry to the trash. Purge it so no disposable lab
  // (or its files) is left behind, but never mistake a 404 for a failure.
  try {
    const purged = await api('DELETE', `/labs/trash/${labId}`)
    cleanup.purged = purged.response.ok || purged.status === 404
    if (!cleanup.purged) cleanup.purgeError = safe(`status ${purged.status}`)
  } catch (error) {
    cleanup.purgeError = safe(error.message)
  }

  try {
    const labs = (await call('GET', '/labs')).data
    const trash = (await call('GET', '/labs/trash')).data
    const leftovers = [...(labs || []), ...(trash || [])].filter((lab) =>
      String(lab?.name || '').startsWith(labPrefix)
    )
    cleanup.leftovers = leftovers.length
    cleanup.gone = leftovers.length === 0
  } catch (error) {
    cleanup.listError = safe(error.message)
    cleanup.gone = false
  }
}

async function runApiChecks() {
  next('health')
  const health = await call('GET', '/health')
  if (health?.status !== 'ok') throw new Error(`health status is ${health?.status}`)
  if (health.version !== expectVersion) {
    throw new Error(`health version ${health.version} != expected ${expectVersion}`)
  }
  const time = Number(health.time)
  if (!Number.isFinite(time)) throw new Error('health time is not a unix timestamp')
  const skew = Math.abs(Date.now() / 1000 - time)
  if (skew > 300) throw new Error(`health time is ${Math.round(skew)}s away from local time`)
  pass({ version: health.version, skewSeconds: Math.round(skew) })

  next('version')
  const version = await call('GET', '/version')
  if (version.version !== expectVersion) {
    throw new Error(`version ${version.version} != expected ${expectVersion}`)
  }
  if (version.product !== 'TeraLab') throw new Error(`unexpected product ${version.product}`)
  pass({ version: version.version, product: version.product })

  next('ui')
  const ui = await fetch(`${base}/ui/`, { signal: AbortSignal.timeout(15000) })
  const uiBody = await ui.text()
  const contentType = ui.headers.get('content-type') || ''
  if (ui.status !== 200) throw new Error(`/ui/ returned ${ui.status}`)
  if (!/text\/html/i.test(contentType)) throw new Error(`/ui/ content-type is ${contentType}`)
  if (!/<div id="app">/.test(uiBody) || !/TeraLab/.test(uiBody)) {
    throw new Error('/ui/ does not look like the TeraLab SPA')
  }
  const missingHeaders = missingSecurityHeaders(ui.headers)
  if (missingHeaders.length) throw new Error(`missing security headers: ${missingHeaders.join(', ')}`)
  pass({ status: ui.status, headers: missingHeaders.length === 0 })

  next('setup-closed')
  const setup = await api('POST', '/setup', {})
  if (setup.status === 200) throw new Error('POST /setup without a token was accepted')
  if (![403, 404, 409, 422].includes(setup.status)) {
    throw new Error(`unexpected setup status ${setup.status}`)
  }
  pass({ status: setup.status })

  next('login')
  const login = await api('POST', '/auth/login', { username: adminUser, password })
  if (login.status !== 200) {
    throw new Error(`login failed: ${login.status} ${safe(login.text.slice(0, 200))}`)
  }
  const cookie = sessionCookie(login.response)
  if (!cookie.cookie) throw new Error('login response did not set a session cookie')
  const csrf = login.data?.csrf_token
  if (typeof csrf !== 'string' || csrf === '') throw new Error('login response is missing csrf_token')
  if (base.startsWith('http:') && cookie.secure) {
    throw new Error('session cookie is Secure over plain HTTP')
  }
  session = { cookie: cookie.cookie, csrf, cookieSecure: cookie.secure }
  pass({ user: login.data?.user?.username || null, cookieSecure: cookie.secure })

  next('auth-me')
  const me = await call('GET', '/auth/me')
  if (me?.user?.username !== adminUser) {
    throw new Error(`/auth/me returned ${me?.user?.username || 'no user'}`)
  }
  // /auth/me rotates the CSRF token; every later mutation must use the new one.
  if (typeof me.csrf_token !== 'string' || me.csrf_token === '') {
    throw new Error('/auth/me did not rotate csrf_token')
  }
  if (me.user.role !== 'admin') {
    throw new Error(`live smoke requires an admin account (got role ${me.user.role || 'unknown'})`)
  }
  session.csrf = me.csrf_token
  pass({ user: me.user.username, role: me.user.role || null })

  next('templates')
  const templates = (await call('GET', '/templates')).data
  if (!Array.isArray(templates)) throw new Error('template list is not an array')
  if (templates.length <= 40) throw new Error(`only ${templates.length} templates, expected > 40`)
  const templateIds = new Set(templates.map((template) => String(template.id)))
  for (const required of ['vpcs', 'frr-ospf', 'frr-bgp']) {
    if (!templateIds.has(required)) throw new Error(`template ${required} is missing`)
  }
  pass({ count: templates.length })

  next('catalogues')
  const images = (await call('GET', '/images')).data
  const labs = (await call('GET', '/labs')).data
  if (!Array.isArray(images)) throw new Error('image list is not an array')
  if (!Array.isArray(labs)) throw new Error('lab list is not an array')
  pass({ images: images.length, labs: labs.length })
}

async function runLabChecks() {
  next('lab-create')
  const lab = (await call('POST', '/labs', {
    name: labPrefix,
    nodes: [{
      id: '1',
      name: 'PC1',
      type: 'vpcs',
      cpu: 1,
      ram: 128,
      interfaces: [{ id: 0, network_id: '0' }]
    }],
    networks: [{ id: '0', name: 'Net-1' }]
  })).data
  labId = String(lab?.id || '')
  if (!labId) throw new Error('lab create response has no id')
  pass({ id: labId })

  next('lab-start')
  await call('POST', `/labs/${labId}/start`)
  const deadline = Date.now() + 90000
  let state = null
  while (Date.now() < deadline) {
    const nodes = (await call('GET', `/labs/${labId}/nodes`)).data
    const node = (nodes?.nodes || []).find((entry) => String(entry.node_id) === '1')
    state = node ? `${node.state}${node.ready ? '/ready' : ''}` : null
    if (nodeIsUp(nodes, '1')) break
    await sleep(1000)
  }
  if (!nodeIsUp((await call('GET', `/labs/${labId}/nodes`)).data, '1')) {
    throw new Error(`node did not become running/ready within 90s (last ${state || 'unknown'})`)
  }
  pass({ state })

  next('console')
  const info = (await call('POST', `/labs/${labId}/nodes/1/console`)).data
  consoleToken = typeof info?.token === 'string' ? info.token : ''
  if (!consoleToken) throw new Error('console response is missing the capability token')
  const wsUrl = new URL(info.ws_path || '/ws/console', base)
  wsUrl.protocol = wsUrl.protocol === 'https:' ? 'wss:' : 'ws:'
  wsUrl.search = ''
  const subprotocol = `teralab.console.${consoleToken}`

  let consoleText = ''
  socket = new WebSocket(wsUrl, [subprotocol], { origin: base, handshakeTimeout: 15000 })
  try {
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('console timeout waiting for VPCS')), 20000)
      socket.on('message', (data) => {
        consoleText += data.toString()
        if (consoleText.includes('VPCS')) { clearTimeout(timer); resolve() }
      })
      socket.on('error', (error) => { clearTimeout(timer); reject(error) })
      socket.on('close', () => {
        if (!consoleText.includes('VPCS')) {
          clearTimeout(timer)
          reject(new Error('console closed before printing VPCS'))
        }
      })
    })
  } finally {
    closeSocket()
  }
  pass({ banner: 'VPCS', subprotocol: true })

  next('lab-stop')
  await call('POST', `/labs/${labId}/stop`)
  pass()

  next('lab-delete')
  await call('DELETE', `/labs/${labId}`)
  const purged = await api('DELETE', `/labs/trash/${labId}`)
  if (!purged.response.ok && purged.status !== 404) {
    throw new Error(`trash purge failed: ${purged.status} ${safe(purged.text.slice(0, 200))}`)
  }
  const labs = (await call('GET', '/labs')).data
  const trash = (await call('GET', '/labs/trash')).data
  const leftovers = [...(labs || []), ...(trash || [])].filter((entry) =>
    String(entry?.name || '').startsWith(labPrefix)
  )
  if (leftovers.length) throw new Error(`${leftovers.length} disposable lab(s) still present`)
  cleanup.stopped = true
  cleanup.deleted = true
  cleanup.purged = true
  cleanup.gone = true
  pass({ gone: true })
}

async function runPlaywrightChecks() {
  next('playwright')
  if (!existsSync(chromePath)) throw new Error(`Chromium not found at ${chromePath} (set TERALAB_CHROME)`)
  browser = await chromium.launch({
    executablePath: chromePath,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage']
  })
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
  page.on('pageerror', (error) => pageErrors.push(safe(error.message)))
  page.on('response', (response) => {
    if (response.status() >= 500 && response.url().includes('/api/v2/')) {
      pageErrors.push(safe(`HTTP ${response.status()} ${response.url()}`))
    }
  })

  await page.goto(`${base}/ui/login`, { waitUntil: 'domcontentloaded' })
  await page.getByLabel('Username').fill(adminUser)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL('**/ui/labs', { timeout: 20000 })
  await page.locator('.labs').waitFor({ timeout: 20000 })

  await page.getByRole('link', { name: 'Appliances' }).click()
  await page.waitForURL('**/ui/appliances', { timeout: 15000 })
  await page.getByRole('tab', { name: 'Templates' }).click()
  await page.waitForURL(/\/ui\/appliances\?tab=templates$/, { timeout: 15000 })
  await page.waitForFunction(() => document.querySelectorAll('.palette li').length > 40, null, { timeout: 20000 })

  await page.getByRole('link', { name: 'System' }).click()
  await page.waitForURL('**/ui/system', { timeout: 15000 })
  await page.getByRole('tab', { name: 'About' }).click()
  await page.locator('.system-about').waitFor({ state: 'visible', timeout: 15000 })

  // Give late uncaught errors a chance to surface before judging the pages.
  await sleep(500)
  if (pageErrors.length) throw new Error(`page errors: ${pageErrors.join('; ')}`)
  pass({ pages: ['/ui/labs', '/ui/appliances?tab=templates', '/ui/system?tab=About'] })
}

try {
  await runApiChecks()
  await runLabChecks()
  await runPlaywrightChecks()
} catch (error) {
  failure = error
  failureDetail = error?.cause?.message ? `${error.message}: ${error.cause.message}` : error.message
  checks.push({ name: current, pass: false, detail: safe(failureDetail).slice(0, 800) })
} finally {
  try {
    await cleanupLab()
  } catch (error) {
    cleanup.error = safe(error.message)
    cleanup.gone = false
  }
  try { await browser?.close() } catch {}
  browser = null
}

if (labId) {
  checks.push({
    name: 'lab-cleanup',
    pass: cleanup.gone === true,
    detail: cleanup.gone === true
      ? { leftovers: 0 }
      : safe(JSON.stringify({
          leftovers: cleanup.leftovers ?? null,
          stop: cleanup.stopError || null,
          delete: cleanup.deleteError || null,
          purge: cleanup.purgeError || null,
          list: cleanup.listError || null
        }))
  })
  if (cleanup.gone !== true && !failure) {
    failure = new Error('cleanup did not remove the disposable lab')
  }
}

const result = {
  tool: 'teralab-live-e2e',
  pass: !failure && pageErrors.length === 0 && checks.every((check) => check.pass),
  url: base,
  version: expectVersion,
  startedAt: startedAt.toISOString(),
  durationMs: Date.now() - startedMs,
  lab: { prefix: labPrefix, created: labId !== '', removed: labId === '' ? null : cleanup.gone === true },
  checks,
  pageErrors,
  cleanup
}

if (!result.pass && failure) result.error = safe(failureDetail || failure.message).slice(0, 800)

const evidence = process.env.TERALAB_EVIDENCE
if (evidence) {
  let target = path.resolve(evidence)
  if (evidence.endsWith(path.sep) || (existsSync(target) && statSync(target).isDirectory())) {
    target = path.join(target, 'live-e2e.json')
  }
  mkdirSync(path.dirname(target), { recursive: true })
  writeFileSync(target, `${JSON.stringify(result, null, 2)}\n`, { mode: 0o600 })
  console.error(`LIVE_E2E_EVIDENCE ${target}`)
}

console.log(JSON.stringify(result, null, 2))
if (!result.pass) {
  console.error(`LIVE_E2E_FAILED ${safe(failureDetail || failure?.message || 'one or more checks failed')}`)
  process.exitCode = 1
} else {
  console.error('LIVE_E2E_OK')
}
