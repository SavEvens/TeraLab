// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors
//
// Real-stack E2E through the installed Apache /ui/ + /api + /ws paths.
// Requires TERALAB_APACHE_URL (default http://127.0.0.1:18080), a running
// agent/API, and TERALAB_TEST_IMAGE pointing at a small qcow2 file.

import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'
import { chromium } from 'playwright-core'
import WebSocket from 'ws'

const base = (process.env.TERALAB_APACHE_URL || 'http://127.0.0.1:18080').replace(/\/$/, '')
const password = process.env.TERALAB_ADMIN_PASSWORD || 'Teralab-Admin-2026!'
const qcow2 = process.env.TERALAB_TEST_IMAGE || '/tmp/apache-base.qcow2'
const outDir = process.env.TERALAB_E2E_OUT || path.join(os.tmpdir(), 'teralab-apache-e2e')
mkdirSync(outDir, { recursive: true })

const stamp = Date.now()
const created = { labs: [], templates: [], images: [] }
let session = null

async function call(method, apiPath, body) {
  const headers = { Accept: 'application/json' }
  if (session) {
    headers.Cookie = session.cookie
    headers['X-CSRF-Token'] = session.csrf
  }
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(body)
  }
  const response = await fetch(`${base}/api/v2${apiPath}`, { method, headers, body })
  const text = await response.text()
  let data = null
  try { data = text ? JSON.parse(text) : null } catch {}
  if (!response.ok) throw new Error(`${method} ${apiPath}: ${response.status} ${text.slice(0, 300)}`)
  return data
}

async function login() {
  await fetch(`${base}/api/v2/setup`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password })
  }).catch(() => {})

  const response = await fetch(`${base}/api/v2/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password })
  })
  if (!response.ok) throw new Error(`login failed: ${response.status} ${await response.text()}`)
  const data = await response.json()
  const setCookie = (response.headers.getSetCookie?.()[0] || '').split(';')[0]
  const rawCookie = response.headers.getSetCookie?.()[0] || ''
  return { cookie: setCookie, csrf: data.csrf_token, cookieSecure: /;\s*Secure/i.test(rawCookie) }
}

function node(id, name, type, extra = {}) {
  return { id: String(id), name, type, cpu: 1, ram: 512, interfaces: [{ id: 0, network_id: '0' }], ...extra }
}

async function createLab(name, nodes, networks = [{ id: '0', name: 'Net-1' }]) {
  const lab = (await call('POST', '/labs', { name, nodes, networks })).data
  created.labs.push(lab.id)
  return lab
}

async function cleanup() {
  for (const id of created.labs) {
    try { await call('POST', `/labs/${id}/stop`) } catch {}
    try { await call('DELETE', `/labs/${id}`) } catch {}
    try { await call('DELETE', `/labs/trash/${id}`) } catch {}
  }
  for (const id of created.templates) {
    try { await call('DELETE', `/templates/${id}`) } catch {}
  }
  for (const id of created.images) {
    try { await call('DELETE', `/images/${id}`) } catch {}
  }
}

let browser
try {
  session = await login()
  const version = await call('GET', '/version')
  const health = await call('GET', '/health')
  if (version.version !== '1.4.2' || health.version !== '1.4.2') {
    throw new Error(`version mismatch: ${version.version}/${health.version}`)
  }
  if (session.cookieSecure) throw new Error('session cookie is Secure over plain HTTP')

  const vnc = await createLab(`Apache VNC ${stamp}`, [
    node(1, 'VNC1', 'qemu', {
      iso: '/var/lib/teralab-test/images/alpine.iso',
      console: 'vnc'
    })
  ])
  const consoleLab = await createLab(`Apache Console ${stamp}`, [node(1, 'PC1', 'vpcs')])
  const nodes = Array.from({ length: 100 }, (_, index) => ({
    id: String(index + 1),
    name: `N${index + 1}`,
    type: 'qemu',
    cpu: 1,
    ram: 256,
    interfaces: [{ id: 0, network_id: String(index % 10) }],
    left: 60 + (index % 10) * 180,
    top: 60 + Math.floor(index / 10) * 110
  }))
  const networks = Array.from({ length: 10 }, (_, index) => ({ id: String(index), name: `Net-${index}` }))
  const big = await createLab(`Apache 100 ${stamp}`, nodes, networks)

  const chrome = process.env.TERALAB_CHROME || ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'].find((candidate) => existsSync(candidate))
  if (!chrome) throw new Error('no Chromium/Chrome binary found')
  browser = await chromium.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] })
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('Failed to load resource')) errors.push(message.text())
  })
  page.on('response', async (response) => {
    if (response.status() >= 400 && response.url().includes('/api/v2/')) {
      console.error('HTTP', response.status(), response.url(), (await response.text().catch(() => '')).slice(0, 300))
    }
  })

  await page.goto(`${base}/ui/login`)
  await page.getByLabel('Username').fill('admin')
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL('**/ui/labs')

  // Drag-and-drop .unl import through Apache multipart handling.
  const dropped = `<?xml version="1.0" encoding="UTF-8"?>
<lab name="Dropped Apache ${stamp}" id="11111111-1111-4111-8111-${String(stamp).slice(-12)}" version="1">
<topology><nodes><node id="1" name="PC1" type="vpcs" console="telnet" cpu="1" ram="128" ethernet="1">
<interface id="0" name="eth0" type="ethernet" network_id="0"/></node></nodes>
<networks><network id="0" name="Net-1" type="bridge"/></networks></topology></lab>`
  const dropFile = path.join(outDir, 'dropped.unl')
  writeFileSync(dropFile, dropped)
  const droppedXml = Buffer.from(dropped, 'utf8').toString('base64')
  await page.evaluate((payload) => {
    const bytes = Uint8Array.from(atob(payload), (char) => char.charCodeAt(0))
    const transfer = new DataTransfer()
    transfer.items.add(new File([bytes], 'dropped.unl', { type: 'application/xml' }))
    document.querySelector('.labs').dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }))
  }, droppedXml)
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /imported/i.test(toast.textContent || '')), null, { timeout: 15000 })
  const droppedLink = page.getByRole('link', { name: new RegExp(`Dropped Apache ${stamp}`) })
  await droppedLink.waitFor({ timeout: 15000 }).catch(() => {})
  const droppedVisible = await droppedLink.count()
  if (!droppedVisible) throw new Error('drag-drop import did not appear in the lab list')
  const imported = (await call('GET', '/labs')).data.find((lab) => lab.name === `Dropped Apache ${stamp}`)
  if (imported) created.labs.push(imported.id)

  // Templates: duplicate, import, export through the UI.
  await page.getByRole('link', { name: 'Templates' }).click()
  await page.waitForURL('**/ui/templates')
  await page.waitForFunction(() => document.querySelectorAll('.palette li').length > 40, null, { timeout: 20000 })
  await page.locator('.palette li', { hasText: 'linux' }).first().click()
  await page.waitForSelector('.template-editor textarea')
  const beforeTemplates = await page.locator('.palette li').count()
  const beforeTemplateIds = new Set((await call('GET', '/templates')).data.map((template) => template.id))
  await page.getByRole('button', { name: 'Duplicate' }).click()
  await page.waitForFunction((count) => document.querySelectorAll('.palette li').length > count, beforeTemplates, { timeout: 20000 })
  const copy = (await call('GET', '/templates')).data.find((template) => !beforeTemplateIds.has(template.id))
  if (copy) created.templates.push(copy.id)

  const importId = `apache-import-${stamp}`
  const importYaml = `schema: 1\nid: ${importId}\nname: Apache Import\nkind: vpcs\ndefaults:\n  ethernet: 1\noptions: {}\n`
  const importFile = path.join(outDir, 'import.yaml')
  writeFileSync(importFile, importYaml)
  const beforeImport = await page.locator('.palette li').count()
  await page.locator('.toolbar input[type=file]').setInputFiles(importFile)
  await page.waitForFunction((count) => document.querySelectorAll('.palette li').length > count, beforeImport, { timeout: 20000 })
  created.templates.push(importId)
  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Export' }).click()
  const exported = (await downloadPromise).suggestedFilename()
  if (!exported.endsWith('.yaml')) throw new Error(`template export filename invalid: ${exported}`)

  // Images: upload, rename, map template through the UI.
  await page.getByRole('link', { name: 'Labs', exact: true }).click()
  await page.waitForURL('**/ui/labs')
  await page.getByRole('link', { name: 'Images' }).click()
  await page.waitForURL('**/ui/images')
  const beforeImageIds = new Set((await call('GET', '/images')).data.map((image) => image.id))
  await page.locator('.images-view input[type=file]').setInputFiles(qcow2)
  await page.getByRole('button', { name: 'Upload' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /uploaded/i.test(toast.textContent || '')), null, { timeout: 30000 })
  await page.waitForSelector('.images-view tbody tr')
  const uploaded = (await call('GET', '/images')).data.find((image) => !beforeImageIds.has(image.id))
  if (!uploaded) throw new Error('uploaded image not found')
  created.images.push(uploaded.id)
  const uploadedRow = () => page.locator('.images-view tbody tr').filter({ hasText: uploaded.path })
  page.once('dialog', (dialog) => dialog.accept(`Apache Image ${stamp}`))
  await uploadedRow().getByRole('button', { name: 'Rename' }).click()
  await page.waitForFunction((name) => document.querySelector('.images-view tbody')?.textContent.includes(name), `Apache Image ${stamp}`, { timeout: 10000 })
  await uploadedRow().locator('select').selectOption('linux')
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /Template mapped/i.test(toast.textContent || '')), null, { timeout: 10000 })
  const imageRow = await uploadedRow().innerText()

  // VNC console on a real QEMU node through Apache /ws/vnc.
  await page.getByRole('link', { name: 'Labs', exact: true }).click()
  await page.waitForURL('**/ui/labs')
  await page.getByRole('link', { name: new RegExp(`Apache VNC ${stamp}`) }).click()
  await page.waitForSelector('.lab-node')
  const imageSelect = page.locator('.inspector label').filter({ hasText: 'Image' }).locator('select')
  await page.locator('.lab-node').first().click()
  const imageOptions = await imageSelect.locator('option').allTextContents()
  if (!imageOptions.some((option) => option.includes(`Apache Image ${stamp}`))) {
    throw new Error('inspector image picker missing the uploaded image')
  }
  await page.getByRole('button', { name: 'Start lab' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /start/i.test(toast.textContent || '')), null, { timeout: 20000 })
  await page.getByRole('button', { name: 'Console' }).click()
  await page.waitForSelector('.vnc canvas', { state: 'attached', timeout: 30000 })
  await page.waitForFunction(() => {
    const canvas = document.querySelector('.vnc canvas')
    const rect = canvas?.getBoundingClientRect()
    return rect && rect.width > 100 && rect.height > 100
  }, null, { timeout: 20000 })
  await page.waitForTimeout(1500)
  const vncError = await page.locator('.vnc-wrap .error').count()
  await page.screenshot({ path: `${outDir}/apache-vnc.png`, fullPage: true })

  // Telnet WebSocket through Apache /ws/console on a real VPCS node.
  await call('POST', `/labs/${consoleLab.id}/start`)
  const consoleInfo = (await call('POST', '/nodes/console', { node_id: `${consoleLab.id}-1` })).data
  const ws = new WebSocket(`${base.replace(/^http/, 'ws')}${consoleInfo.ws_path}`)
  let consoleText = ''
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('telnet console timeout')), 15000)
    ws.on('message', (data) => {
      consoleText += data.toString()
      if (consoleText.includes('VPCS')) { clearTimeout(timer); resolve() }
    })
    ws.on('error', reject)
  })
  ws.close()
  await call('POST', `/labs/${consoleLab.id}/stop`)

  // 100-node open and interaction FPS.
  await page.goto(`${base}/ui/labs`)
  const largeStart = Date.now()
  await page.getByRole('link', { name: new RegExp(`Apache 100 ${stamp}`) }).click()
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 100, null, { timeout: 20000 })
  const largeMs = Date.now() - largeStart
  await page.evaluate(() => {
    window.__frames = 0
    window.__done = false
    const start = performance.now()
    function loop(now) {
      window.__frames++
      if (now - start < 2000) requestAnimationFrame(loop)
      else window.__done = true
    }
    requestAnimationFrame(loop)
  })
  const box = await page.locator('.canvas').boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  await page.mouse.down()
  for (let step = 0; step < 20; step++) {
    await page.mouse.move(box.x + box.width / 2 - step * 12, box.y + box.height / 2 - step * 4)
    await sleep(90)
  }
  await page.mouse.up()
  await page.waitForFunction(() => window.__done === true, null, { timeout: 5000 })
  const fps = Math.round((await page.evaluate(() => window.__frames)) / 2)
  await page.screenshot({ path: `${outDir}/apache-100.png`, fullPage: true })

  const result = {
    version: version.version,
    cookieSecure: session.cookieSecure,
    templateExport: exported,
    templateImport: importId,
    imageRow: imageRow.replace(/\s+/g, ' ').trim(),
    imageOptions,
    dragDrop: droppedVisible > 0,
    vncError,
    telnetConsole: consoleText.includes('VPCS'),
    largeMs,
    fps,
    pageErrors: errors
  }
  console.log(JSON.stringify(result, null, 2))

  if (errors.length) throw new Error(`page errors: ${errors.join('; ')}`)
  if (!imageRow.includes(`Apache Image ${stamp}`)) throw new Error('image rename failed')
  if (vncError !== 0) throw new Error('VNC reported an error')
  if (!consoleText.includes('VPCS')) throw new Error('telnet console did not produce VPCS output')
  if (fps < 30) throw new Error(`low interaction fps: ${fps}`)
  if (largeMs > 10000) throw new Error(`slow 100-node open: ${largeMs}ms`)
  console.log('APACHE_UI_E2E_OK')
} catch (error) {
  if (browser) {
    const pages = browser.contexts().flatMap((context) => context.pages())
    if (pages[0]) {
      console.error('FAIL_URL', pages[0].url())
      console.error('FAIL_BODY', (await pages[0].locator('body').innerText().catch(() => '')).slice(0, 1200))
      await pages[0].screenshot({ path: `${outDir}/apache-failure.png`, fullPage: true }).catch(() => {})
    }
  }
  throw error
} finally {
  try { await cleanup() } catch (error) { console.error('CLEANUP_FAILED', error.message) }
  await browser?.close()
}
