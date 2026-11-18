// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { spawn } from 'node:child_process'
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'
import { chromium } from 'playwright-core'
import { startMockServer } from './mock-server.mjs'

const uiPort = 5173
const mockPort = 8099
const outDir = process.env.TERALAB_E2E_OUT || path.join(os.tmpdir(), 'teralab-ui-e2e')
mkdirSync(outDir, { recursive: true })

const mock = await startMockServer(mockPort)
const env = {
  ...process.env,
  PATH: `/home/savanna/.local/node/bin:${process.env.PATH}`,
  TERALAB_UI_API: `http://127.0.0.1:${mockPort}`,
  TERALAB_UI_WS: `ws://127.0.0.1:${mockPort}`
}
const uiDir = new URL('..', import.meta.url).pathname
const vite = spawn(`${uiDir}/node_modules/.bin/vite`, ['--host', '127.0.0.1', '--port', String(uiPort)], {
  cwd: uiDir,
  env,
  detached: true,
  stdio: 'ignore'
})
const watchdog = setTimeout(() => {
  console.error('E2E_TIMEOUT')
  process.exit(1)
}, 180000)
watchdog.unref()

async function waitFor(url, timeout = 30000) {
  const start = Date.now()
  while (Date.now() - start < timeout) {
    try {
      const response = await fetch(url)
      if (response.ok) return
    } catch {}
    await sleep(300)
  }
  throw new Error(`timeout waiting for ${url}`)
}

let browser
try {
  await waitFor(`http://127.0.0.1:${uiPort}/ui/login`)
  const chrome = process.env.TERALAB_CHROME || ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'].find((path) => existsSync(path))
  if (!chrome) throw new Error('no Chromium/Chrome binary found')
  browser = await chromium.launch({
    executablePath: chrome,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage']
  })
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('Failed to load resource')) {
      errors.push(`console: ${message.text()}`)
    }
  })
  page.on('response', (response) => {
    if (response.status() >= 400 && !response.url().includes('/api/v2/auth/me')) {
      console.error('HTTP', response.status(), response.url())
    }
  })

  await page.goto(`http://127.0.0.1:${uiPort}/ui/login`)
  await page.waitForTimeout(1500)
  if (!(await page.getByLabel('Username').count())) {
    console.error('URL', page.url())
    console.error('TITLE', await page.title())
    console.error('BODY', (await page.locator('body').innerText()).slice(0, 1000))
    console.error('HTML_HEAD', (await page.content()).slice(0, 1200))
    console.error('ERRORS', errors)
    await page.screenshot({ path: `${outDir}/login-debug.png`, fullPage: true })
  }
  await page.getByLabel('Username').fill('admin')
  await page.getByLabel('Password').fill('Teralab-Admin-2026!')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL(`**/ui/labs`)

  // Images: upload, rename, map a template.
  await page.getByRole('link', { name: 'Images' }).click()
  await page.waitForURL('**/ui/images')
  const imageFile = path.join(outDir, 'base.qcow2')
  writeFileSync(imageFile, 'QFI\xfb' + '\0'.repeat(64))
  await page.locator('.images-view input[type=file]').setInputFiles(imageFile)
  await page.getByRole('button', { name: 'Upload' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /uploaded/i.test(toast.textContent || '')), null, { timeout: 10000 })
  await page.waitForSelector('.images-view tbody tr')
  page.once('dialog', (dialog) => dialog.accept('Renamed base'))
  await page.getByRole('button', { name: 'Rename' }).click()
  await page.waitForFunction(() => document.querySelector('.images-view tbody')?.textContent.includes('Renamed base'), null, { timeout: 10000 })
  await page.locator('.images-view tbody tr').first().locator('select').selectOption('linux')
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /Template mapped/i.test(toast.textContent || '')), null, { timeout: 10000 })
  const imageRow = await page.locator('.images-view tbody tr').first().innerText()

  await page.getByRole('link', { name: 'Labs', exact: true }).click()
  await page.waitForURL('**/ui/labs')
  await page.getByRole('link', { name: 'Editor Lab' }).click()
  await page.waitForURL(`**/ui/labs/lab1`)
  await page.waitForSelector('.lab-node')
  const initialNodes = await page.locator('.lab-node').count()

  // Add a node from the palette.
  await page.locator('.palette li').first().dblclick()
  await page.waitForFunction((count) => document.querySelectorAll('.lab-node').length > count, initialNodes)
  const addedNodes = await page.locator('.lab-node').count()

  // Inspector edit triggers autosave.
  await page.locator('.lab-node').first().click()
  const imageSelect = page.locator('.inspector label').filter({ hasText: 'Image' }).locator('select')
  const imageOptions = await imageSelect.locator('option').allTextContents()
  const nameInput = page.locator('.inspector input').first()
  await nameInput.fill('Renamed R1')
  const nicSelect = page.locator('.inspector select').filter({ has: page.locator('option[value="e1000"]') })
  await nicSelect.selectOption('virtio-net-pci')
  const serialInput = page.locator('.inspector label').filter({ hasText: 'Serial ports' }).locator('input')
  await serialInput.fill('2')
  await page.waitForTimeout(1800)
  const savedText = await page.locator('.save-state').innerText()
  const saved = (await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()).lab
  const savedNode = saved.node_list.find((node) => node.id === '1')
  const nodeFields = { nic: savedNode?.nic, serial: savedNode?.serial }

  // Force one conflict and check the dialog.
  await fetch(`http://127.0.0.1:${mockPort}/__conflict`, { method: 'POST' })
  await nameInput.fill('Conflict R1')
  await page.waitForSelector('.modal', { timeout: 8000 })
  const conflictText = await page.locator('.modal').innerText()
  await page.getByRole('button', { name: 'Cancel' }).click()

  // Console tab over the mock WebSocket.
  await page.locator('.lab-node').first().click()
  await page.getByRole('button', { name: 'Console' }).click()
  await page.waitForSelector('.console-dock')
  const consoleTab = await page.locator('.console-dock .tabs button').first().innerText()
  await page.waitForTimeout(1000)
  await page.getByRole('link', { name: '← Labs' }).click()
  await page.waitForURL('**/ui/labs')
  const dockPersists = await page.locator('.console-dock').count()
  await page.getByRole('link', { name: 'Editor Lab' }).click()
  await page.waitForURL('**/ui/labs/lab1')
  await page.waitForSelector('.lab-node')

  // Text and picture objects plus the accessible table view.
  await page.locator('.palette .special button', { hasText: 'Text' }).click()
  await page.waitForSelector('.text-node')
  await page.locator('.palette .special button', { hasText: 'Picture' }).click()
  await page.waitForSelector('.picture-node')
  await page.getByRole('button', { name: 'Table' }).click()
  await page.waitForSelector('.node-table')
  const tableRows = await page.locator('.node-table tbody tr').count()
  const xtermCount = await page.locator('.xterm').count()

  await page.getByRole('button', { name: 'Start' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /start/i.test(toast.textContent || '')), null, { timeout: 12000 })
  const startToast = await page.locator('.toasts').innerText()
  await page.waitForTimeout(800)

  await page.screenshot({ path: `${outDir}/editor.png`, fullPage: true })

  // Large-lab open budget (100 nodes).
  await fetch(`http://127.0.0.1:${mockPort}/__large`, { method: 'POST' })
  const largeStart = Date.now()
  await page.goto(`http://127.0.0.1:${uiPort}/ui/labs/lab1`)
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 100, null, { timeout: 15000 })
  const largeMs = Date.now() - largeStart
  await page.screenshot({ path: `${outDir}/editor-100.png`, fullPage: true })

  await page.getByRole('link', { name: 'Images' }).click()
  await page.waitForURL('**/ui/images')
  await page.waitForSelector('.images-view tbody tr', { timeout: 10000 })
  page.once('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: 'Delete' }).first().click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /deleted/i.test(toast.textContent || '')), null, { timeout: 10000 })
  const imagesAfterDelete = await page.locator('.images-view tbody tr').count()

  const result = {
    initialNodes,
    addedNodes,
    savedText,
    conflictText: conflictText.replace(/\s+/g, ' ').trim(),
    consoleTab,
    nodeFields,
    imageRow: imageRow.replace(/\s+/g, ' ').trim(),
    imageOptions,
    dockPersists,
    tableRows,
    xtermCount,
    startToast: startToast.replace(/\s+/g, ' ').trim(),
    largeMs,
    imagesAfterDelete,
    pageErrors: errors
  }
  console.log(JSON.stringify(result, null, 2))
  if (errors.length) throw new Error(`page errors: ${errors.join('; ')}`)
  if (addedNodes !== initialNodes + 1) throw new Error('palette add failed')
  if (!savedText.includes('Saved')) throw new Error('autosave did not complete')
  if (nodeFields.nic !== 'virtio-net-pci' || nodeFields.serial !== 2) throw new Error('node nic/serial were not saved')
  if (!imageRow.includes('Renamed base')) throw new Error('image rename failed')
  if (!imageOptions.some((option) => option.includes('Renamed base'))) throw new Error('inspector image picker missing uploaded image')
  if (!conflictText.includes('Save conflict')) throw new Error('conflict dialog missing')
  if (!consoleTab.trim()) throw new Error('console tab missing')
  if (xtermCount < 1) throw new Error('xterm not mounted')
  if (tableRows < 5) throw new Error('table fallback missing nodes')
  if (dockPersists !== 1) throw new Error('console dock did not persist across routes')
  if (largeMs > 10000) throw new Error(`100-node editor open took ${largeMs}ms`)
  if (imagesAfterDelete !== 0) throw new Error('image delete failed')
  if (!/start/i.test(startToast)) throw new Error('start job toast missing')
  console.log('UI_E2E_OK')
} finally {
  clearTimeout(watchdog)
  await browser?.close()
  try { process.kill(-vite.pid, 'SIGTERM') } catch {}
  mock.close()
}
