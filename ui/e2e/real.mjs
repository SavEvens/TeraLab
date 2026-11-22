// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { spawn } from 'node:child_process'
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'
import { chromium } from 'playwright-core'

const uiPort = 5174
const api = process.env.TERALAB_REAL_API || 'http://127.0.0.1:18094'
const ws = process.env.TERALAB_REAL_WS || 'ws://127.0.0.1:18091'
const password = process.env.TERALAB_ADMIN_PASSWORD || 'Teralab-Admin-2026!'
const outDir = process.env.TERALAB_E2E_OUT || path.join(os.tmpdir(), 'teralab-ui-e2e-real')
mkdirSync(outDir, { recursive: true })

const uiDir = new URL('..', import.meta.url).pathname
const env = { ...process.env, PATH: `/home/savanna/.local/node/bin:${process.env.PATH}`, TERALAB_UI_API: api, TERALAB_UI_WS: ws }
const vite = spawn(`${uiDir}/node_modules/.bin/vite`, ['--host', '127.0.0.1', '--port', String(uiPort)], {
  cwd: uiDir,
  env,
  detached: true,
  stdio: 'ignore'
})
const watchdog = setTimeout(() => { console.error('REAL_E2E_TIMEOUT'); process.exit(1) }, 300000)
watchdog.unref()

async function waitFor(url, timeout = 30000) {
  const start = Date.now()
  while (Date.now() - start < timeout) {
    try { if ((await fetch(url)).ok) return } catch {}
    await sleep(300)
  }
  throw new Error(`timeout waiting for ${url}`)
}

async function login() {
  const response = await fetch(`${api}/api/v2/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password })
  })
  if (!response.ok) throw new Error(`login failed: ${response.status}`)
  const data = await response.json()
  const cookie = (response.headers.getSetCookie?.()[0] || '').split(';')[0]
  return { cookie, csrf: data.csrf_token }
}

async function createLab(session, payload) {
  const response = await fetch(`${api}/api/v2/labs`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf, Cookie: session.cookie },
    body: JSON.stringify(payload)
  })
  if (!response.ok) throw new Error(`create lab failed: ${response.status} ${await response.text()}`)
  return (await response.json()).data
}

let browser
try {
  const session = await login()
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
  const big = await createLab(session, { name: `Big 100 ${Date.now()}`, nodes, networks })

  await waitFor(`http://127.0.0.1:${uiPort}/ui/login`)
  const chrome = process.env.TERALAB_CHROME || ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'].find((candidate) => existsSync(candidate))
  browser = await chromium.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] })
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
  const errors = []
  page.on('pageerror', (error) => { errors.push(error.message); console.error('PAGEERROR', error.message) })
  page.on('console', (message) => { if (message.type() === 'error' && !message.text().includes('Failed to load resource')) { errors.push(message.text()); console.error('CONSOLE', message.text()) } })

  await page.goto(`http://127.0.0.1:${uiPort}/ui/login`)
  await page.getByLabel('Username').fill('admin')
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL('**/ui/labs')

  // Template duplicate and YAML import against the real API.
  await page.getByRole('link', { name: 'Appliances' }).click()
  await page.waitForURL('**/ui/appliances')
  await page.getByRole('tab', { name: 'Templates' }).click()
  await page.waitForURL(/\/ui\/appliances\?tab=templates$/)
  await page.waitForFunction(() => document.querySelectorAll('.palette li').length > 40, null, { timeout: 20000 })
  const beforeTemplates = await page.locator('.palette li').count()
  await page.locator('.palette li', { hasText: 'linux' }).first().click()
  await page.waitForSelector('.template-editor textarea')
  await page.getByRole('button', { name: 'Duplicate' }).click()
  await page.waitForFunction((count) => document.querySelectorAll('.palette li').length > count, beforeTemplates, { timeout: 20000 })
  const importYaml = `schema: 1\nid: real-import-${Date.now()}\nname: Real Import\nkind: vpcs\ndefaults:\n  ethernet: 1\noptions: {}\n`
  const importId = /id: ([^\n]+)/.exec(importYaml)[1]
  const importFile = path.join(outDir, 'import.yaml')
  writeFileSync(importFile, importYaml)
  const beforeImport = await page.locator('.palette li').count()
  await page.locator('.toolbar input[type=file]').setInputFiles(importFile)
  await page.waitForFunction((count) => document.querySelectorAll('.palette li').length > count, beforeImport, { timeout: 20000 })
  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Export' }).click()
  const download = await downloadPromise
  const exported = download.suggestedFilename()

  // Real VNC console.
  await page.getByRole('link', { name: '← Labs' }).click()
  await page.getByRole('link', { name: 'Real VNC Lab' }).click()
  await page.waitForSelector('.lab-node')
  await page.locator('.lab-node').first().click()
  await page.getByRole('button', { name: 'Console' }).click()
  await page.waitForSelector('.vnc canvas', { state: 'attached', timeout: 20000 })
  await page.waitForTimeout(2000)
  const vncInfo = await page.evaluate(() => {
    const canvas = document.querySelector('.vnc canvas')
    const pane = document.querySelector('.console-pane')
    const block = document.querySelector('.vnc')
    const style = canvas ? getComputedStyle(canvas) : null
    return {
      canvas: canvas?.getBoundingClientRect(),
      pane: pane?.getBoundingClientRect(),
      block: block?.getBoundingClientRect(),
      display: style?.display,
      visibility: style?.visibility
    }
  })
  console.log('VNC_INFO', JSON.stringify(vncInfo))
  const vncError = await page.locator('.vnc-wrap .error').count()
  await page.screenshot({ path: `${outDir}/vnc-real.png`, fullPage: true })

  // 100-node interaction FPS and open time.
  await page.getByRole('link', { name: '← Labs' }).click()
  const largeStart = Date.now()
  await page.getByRole('link', { name: new RegExp(big.name) }).click()
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
  const frames = await page.evaluate(() => window.__frames)
  const fps = Math.round(frames / 2)
  await page.screenshot({ path: `${outDir}/editor-100-real.png`, fullPage: true })

  const result = { templateExport: exported, templateImport: importId, vncError, largeMs, fps, pageErrors: errors }
  console.log(JSON.stringify(result, null, 2))
  if (errors.length) throw new Error(`page errors: ${errors.join('; ')}`)
  if (!exported.endsWith('.yaml')) throw new Error('template export filename invalid')
  if (vncError !== 0) throw new Error('VNC reported an error')
  if (fps < 30) throw new Error(`low interaction fps: ${fps}`)
  if (largeMs > 10000) throw new Error(`slow 100-node open: ${largeMs}ms`)
  console.log('REAL_UI_E2E_OK')
} catch (error) {
  if (browser) {
    const pages = browser.contexts().flatMap((context) => context.pages())
    if (pages[0]) {
      console.error('FAIL_URL', pages[0].url())
      console.error('FAIL_BODY', (await pages[0].locator('body').innerText().catch(() => '')).slice(0, 1500))
      await pages[0].screenshot({ path: `${outDir}/real-failure.png`, fullPage: true }).catch(() => {})
    }
  }
  throw error
} finally {
  clearTimeout(watchdog)
  await browser?.close()
  try { process.kill(-vite.pid, 'SIGTERM') } catch {}
}
