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

  // Bounding boxes of the Vue Flow chrome and the editor toolbars. Used to
  // prove the zoom controls and minimap are never covered by the bottom bar or
  // status line, including while the console dock is open.
  const readChromeLayout = () => page.evaluate(() => {
    const box = (selector) => {
      const element = document.querySelector(selector)
      if (!element) return null
      const rect = element.getBoundingClientRect()
      if (!rect.width || !rect.height) return null
      return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom }
    }
    const overlaps = (a, b) => !!(a && b && a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top)
    const controls = box('.vue-flow__controls')
    const minimap = box('.vue-flow__minimap')
    const bottom = box('.ed-bottom')
    const status = box('.ed-status')
    return {
      controls,
      minimap,
      overlap: overlaps(controls, bottom) || overlaps(controls, status) || overlaps(minimap, bottom) || overlaps(minimap, status)
    }
  })

  const loginStart = Date.now()
  await page.goto(`http://127.0.0.1:${uiPort}/ui/login`)
  await page.getByLabel('Username').waitFor({ timeout: 30000 })
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
  const loginMs = Date.now() - loginStart

  // System page smoke: host, settings, logs, and tokens.
  await page.getByRole('link', { name: 'System' }).click()
  await page.waitForURL('**/ui/system')
  await page.getByRole('tab', { name: 'Host' }).click()
  const systemHost = await page.locator('.system-grid').first().innerText()
  const systemVersionBadges = await page.locator('.system-view .page-head .badge').count()
  const systemPayloadCards = await page.locator('.system-grid').first().locator('pre.log-view').count()
  await page.getByRole('tab', { name: 'Settings' }).click()
  await page.getByRole('tab', { name: 'Tokens' }).click()
  await page.locator('.token-form input').first().fill('e2e token')
  await page.getByRole('button', { name: 'Create token' }).click()
  await page.waitForFunction(() => document.querySelector('.token-once')?.textContent.includes('tl_mock_token'), null, { timeout: 10000 })
  await page.getByRole('link', { name: 'About' }).click()
  await page.waitForURL('**/ui/about')
  const aboutText = await page.locator('.about-view').innerText()
  const aboutBadge = (await page.locator('.about-view .page-head .badge').innerText()).trim()
  const aboutDots = await page.locator('.about-view .dot').count()
  await page.getByRole('link', { name: 'Labs', exact: true }).click()
  await page.waitForURL('**/ui/labs')
  await page.waitForSelector('.lab-table tbody tr')
  const labsStatusBefore = await page.locator('[data-lab-status="lab1"]').innerText()
  const stopButtonsBefore = await page.getByRole('button', { name: '■ Stop' }).count()
  const formatBadge = await page.locator('.lab-table .badge.format').first().innerText()
  await page.locator('.toolbar input[type=file]').setInputFiles({
    name: 'eve-lab.unl',
    mimeType: 'application/xml',
    buffer: Buffer.from('<?xml version="1.0"?><lab name="EVE Lab"><topology/></lab>')
  })
  await page.waitForSelector('.import-report')
  const importReport = await page.locator('.import-report').innerText()
  await page.locator('.import-report').getByRole('button', { name: 'Close' }).click()

  // Images: upload, rename, map a template.
  await page.getByRole('link', { name: 'Images' }).click()
  await page.waitForURL('**/ui/images')
  const imageFile = path.join(outDir, 'base.qcow2')
  writeFileSync(imageFile, 'QFI\xfb' + '\0'.repeat(64))
  await page.locator('.images-view input[type=file]').setInputFiles(imageFile)
  await page.getByRole('button', { name: 'Upload' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /uploaded/i.test(toast.textContent || '')), null, { timeout: 10000 })
  await page.waitForSelector('.images-view tbody tr')
  await page.getByRole('button', { name: 'Rename' }).click()
  await page.waitForSelector('.rename-modal')
  const renamePrefill = {
    name: await page.locator('.rename-modal input[aria-label="Display name"]').inputValue(),
    reference: await page.locator('.rename-modal input[aria-label="Reference"]').inputValue()
  }
  await page.locator('.rename-modal input[aria-label="Display name"]').fill('Renamed base')
  await page.locator('.rename-modal').getByRole('button', { name: 'Save' }).click()
  await page.waitForFunction(() => document.querySelector('.images-view tbody')?.textContent.includes('Renamed base'), null, { timeout: 10000 })
  await page.locator('.images-view tbody tr').first().locator('select').selectOption('linux')
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /Template mapped/i.test(toast.textContent || '')), null, { timeout: 10000 })
  // A second qemu image mapped elsewhere: the Linux node's Inspector must not
  // offer it once Linux has its own mapping.
  await fetch(`http://127.0.0.1:${mockPort}/__extra-image`, { method: 'POST' })
  const imageRow = await page.locator('.images-view tbody tr').first().innerText()

  await page.getByRole('link', { name: 'Labs', exact: true }).click()
  await page.waitForURL('**/ui/labs')
  const editorStart = Date.now()
  await page.getByRole('link', { name: 'Editor Lab' }).click()
  await page.waitForURL(`**/ui/labs/lab1`)
  await page.waitForSelector('.lab-node')
  const editorMs = Date.now() - editorStart
  const editorButtons = (await page.locator('.editor button').allTextContents()).map((text) => text.trim())
  const addLinkTop = await page.locator('.ed-actions button').filter({ hasText: '+ Add link' }).count()
  const addLinkBottom = await page.locator('.ed-bottom button').filter({ hasText: 'Add link' }).count()
  const addLinkEnabled = await page.locator('.ed-actions button').filter({ hasText: '+ Add link' }).isEnabled()
  const editorChrome = await readChromeLayout()

  // Snapshot create/list.
  await page.getByRole('button', { name: 'Snapshots' }).click()
  await page.locator('.snapshot-modal input').fill('before changes')
  await page.getByRole('button', { name: 'Create snapshot' }).click()
  await page.waitForFunction(() => document.querySelector('.snapshot-modal tbody')?.textContent.includes('before changes'), null, { timeout: 10000 })
  await page.getByRole('button', { name: 'Close' }).click()

  // Context menus: canvas, node, and link.
  await page.locator('.canvas').click({ button: 'right', position: { x: 700, y: 800 } })
  await page.waitForSelector('.context-menu')
  const canvasMenu = await page.locator('.context-menu').innerText()
  await page.screenshot({ path: `${outDir}/context-menu.png`, fullPage: true })
  await page.keyboard.press('Escape')
  await page.locator('.lab-node').first().click({ button: 'right' })
  await page.waitForSelector('.context-menu')
  const nodeMenu = await page.locator('.context-menu').innerText()
  await page.keyboard.press('Escape')
  await page.locator('.vue-flow__edge-path').first().click({ button: 'right', force: true })
  await page.waitForSelector('.context-menu')
  const edgeMenu = await page.locator('.context-menu').innerText()
  await page.getByText('Delete link', { exact: true }).click()
  await page.waitForFunction(() => document.querySelectorAll('.vue-flow__edge').length === 0, null, { timeout: 10000 })

  await page.locator('.lab-node').first().click({ button: 'right' })
  await page.getByText('Add link…', { exact: true }).click()
  await page.waitForSelector('.link-modal')
  const linkInterfaceOptions = await page.locator('select[aria-label="Interface A"] option').allTextContents()
  await page.getByRole('button', { name: 'Create link' }).click()
  await page.waitForFunction(() => document.querySelectorAll('.vue-flow__edge').length === 1, null, { timeout: 10000 })
  const linkMenuRecreated = await page.locator('.vue-flow__edge').count()
  const linkTagsAfterRecreate = await page.locator('.link-port-tag').allTextContents()
  const initialNodes = await page.locator('.lab-node').count()

  // Add node: templates grouped by image mapping.
  await page.locator('.add-node').click()
  await page.waitForSelector('.template-picker')
  const pickerReady = await page.locator('[data-group="ready"]').innerText()
  const pickerNeedsImage = await page.locator('[data-group="needs-image"]').innerText()
  await page.screenshot({ path: `${outDir}/add-node.png` })
  await page.locator('[data-group="needs-image"] li').filter({ hasText: 'Cisco IOL' }).getByRole('button', { name: 'Add' }).click()
  await page.waitForFunction((count) => document.querySelectorAll('.lab-node').length > count, initialNodes)
  const addedNodes = await page.locator('.lab-node').count()

  // Multi-add: a count of 3 in one picker action appends three nodes.
  const nodeCountBeforeBatch = await page.locator('.ed-status span strong').first().innerText()
  await page.locator('.add-node').click()
  await page.waitForSelector('.template-picker')
  await page.locator('.picker-count input').fill('3')
  await page.locator('[data-group="ready"] li').filter({ hasText: 'Linux' }).getByRole('button', { name: 'Add', exact: true }).click()
  await page.waitForFunction((count) => document.querySelectorAll('.lab-node').length === count + 3, addedNodes)
  const batchNodes = await page.locator('.lab-node').count()
  const nodeCountAfterBatch = await page.locator('.ed-status span strong').first().innerText()
  // The batch cascade can place the last node outside the viewport; select it
  // through the same click handler instead of scrolling the canvas.
  await page.locator('.lab-node').last().evaluate((element) => element.dispatchEvent(new MouseEvent('click', { bubbles: true })))
  await page.waitForFunction(() => document.querySelector('.inspector input')?.value === 'Linux6', null, { timeout: 10000 })
  const addedImageSelect = page.locator('.inspector label').filter({ hasText: 'Image' }).locator('select')
  const addedImageValue = await addedImageSelect.inputValue()
  const addedImageOptions = (await addedImageSelect.locator('option').allTextContents()).map((text) => text.trim())

  // Explicit link dialog with an IOL interface (e0/0, e0/1).
  await page.locator('.ed-actions button').filter({ hasText: 'Add link' }).click()
  await page.waitForSelector('.link-modal')
  await page.locator('select[aria-label="Node A"]').selectOption('3')
  const iolOptions = await page.locator('select[aria-label="Interface A"] option').allTextContents()
  await page.locator('select[aria-label="Interface A"]').selectOption('1')
  await page.locator('select[aria-label="Node B"]').selectOption('2')
  const linkSummary = await page.locator('.link-summary').innerText()
  await page.screenshot({ path: `${outDir}/add-link.png` })
  await page.getByRole('button', { name: 'Create link' }).click()
  await page.waitForFunction(() => document.querySelectorAll('.vue-flow__edge').length === 2, null, { timeout: 10000 })
  const linkTags = await page.locator('.link-port-tag').allTextContents()
  await page.waitForTimeout(1600)
  const linkedState = (await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()).lab
  const iolNode = linkedState.node_list.find((node) => node.id === '3')
  const batchNode = linkedState.node_list.find((node) => node.id === '4')
  const r2Node = linkedState.node_list.find((node) => node.id === '2')
  const interfaceLink = {
    iol: iolNode?.interfaces?.[1]?.network_id,
    r2: r2Node?.interfaces?.[1]?.network_id
  }
  await page.waitForTimeout(400)
  await page.screenshot({ path: `${outDir}/links.png` })

  // Inspector edit triggers autosave.
  await page.locator('.lab-node').first().click()
  const imageSelect = page.locator('.inspector label').filter({ hasText: 'Image' }).locator('select')
  const imageOptions = await imageSelect.locator('option').allTextContents()
  const nameInput = page.locator('.inspector input').first()
  await nameInput.fill('Renamed R1')
  const nicSelect = page.locator('.inspector select').filter({ has: page.locator('option[value="e1000"]') })
  await nicSelect.selectOption('virtio')
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
  const consoleStart = Date.now()
  await page.getByRole('button', { name: 'Console' }).click()
  await page.waitForSelector('.console-dock')
  await page.waitForSelector('.xterm')
  const consoleMs = Date.now() - consoleStart
  await page.waitForTimeout(500)
  const resizeFrames = mock.wsMessages.flat().filter((message) => message.startsWith('{"type":"resize"'))
  const heightBefore = await page.locator('.console-body').evaluate((element) => element.getBoundingClientRect().height)
  const cssHeightBefore = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--console-h').trim())
  const handleBox = await page.locator('.console-resize').boundingBox()
  await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2)
  await page.mouse.down()
  await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y - 90, { steps: 6 })
  await page.mouse.up()
  await page.waitForTimeout(250)
  const heightAfter = await page.locator('.console-body').evaluate((element) => element.getBoundingClientRect().height)
  const cssHeightAfter = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--console-h').trim())
  const dockStoredHeight = await page.evaluate(() => localStorage.getItem('teralab.console-h.user1'))
  const chromeLayoutConsole = await readChromeLayout()
  const terminalBounds = await page.evaluate(() => {
    const screen = document.querySelector('.console-pane .xterm-screen')?.getBoundingClientRect()
    const pane = document.querySelector('.console-pane')?.getBoundingClientRect()
    return { screenBottom: screen?.bottom ?? 0, paneBottom: pane?.bottom ?? 0 }
  })
  const consoleTab = await page.locator('.console-dock .tabs button').first().innerText()
  await page.waitForTimeout(1000)
  await page.getByRole('link', { name: '← Labs' }).click()
  await page.waitForURL('**/ui/labs')
  const dockPersists = await page.locator('.console-dock').count()
  await page.getByRole('link', { name: 'Editor Lab' }).click()
  await page.waitForURL('**/ui/labs/lab1')
  await page.waitForSelector('.lab-node')

  // Text and picture objects plus the accessible table view.
  await page.locator('.add-node').click()
  await page.waitForSelector('.template-picker')
  await page.locator('.picker-special button', { hasText: 'Add text' }).click()
  await page.waitForSelector('.text-node')
  await page.locator('.add-node').click()
  await page.waitForSelector('.template-picker')
  await page.locator('.picker-special button', { hasText: 'Add picture' }).click()
  await page.waitForSelector('.picture-node')
  await page.getByRole('button', { name: 'Table' }).click()
  await page.waitForSelector('.node-table')
  const tableRows = await page.locator('.node-table tbody tr').count()
  const xtermCount = await page.locator('.xterm').count()

  await page.getByRole('button', { name: 'Start lab' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /start/i.test(toast.textContent || '')), null, { timeout: 12000 })
  const startToast = await page.locator('.toasts').innerText()
  await page.waitForTimeout(800)

  await page.getByRole('button', { name: 'Canvas', exact: true }).click()
  await page.waitForFunction(() => document.querySelectorAll('.vue-flow__edge').length > 0, null, { timeout: 10000 })
  await page.screenshot({ path: `${outDir}/editor.png`, fullPage: true })

  // Labs menu: running status and stop control.
  await page.getByRole('link', { name: '← Labs' }).click()
  await page.waitForURL('**/ui/labs')
  await page.waitForFunction(() => document.querySelector('[data-lab-status="lab1"]')?.textContent.includes('Running'), null, { timeout: 10000 })
  const labsStatusRunning = await page.locator('[data-lab-status="lab1"]').innerText()
  const stopButtonsRunning = await page.getByRole('button', { name: '■ Stop' }).count()
  await page.screenshot({ path: `${outDir}/labs-running.png` })
  await page.getByRole('button', { name: '■ Stop' }).click()
  await page.waitForFunction(() => document.querySelector('[data-lab-status="lab1"]')?.textContent.includes('Stopped'), null, { timeout: 15000 })
  const labsStatusAfterStop = await page.locator('[data-lab-status="lab1"]').innerText()
  const stopButtonsAfter = await page.getByRole('button', { name: '■ Stop' }).count()

  // Large-lab open budgets (50 and 100 nodes).
  await fetch(`http://127.0.0.1:${mockPort}/__fifty`, { method: 'POST' })
  const fiftyStart = Date.now()
  await page.goto(`http://127.0.0.1:${uiPort}/ui/labs/lab1`)
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 50, null, { timeout: 15000 })
  const fiftyMs = Date.now() - fiftyStart

  await fetch(`http://127.0.0.1:${mockPort}/__large`, { method: 'POST' })
  const largeStart = Date.now()
  await page.goto(`http://127.0.0.1:${uiPort}/ui/labs/lab1`)
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 100, null, { timeout: 15000 })
  const largeMs = Date.now() - largeStart
  await page.screenshot({ path: `${outDir}/editor-100.png`, fullPage: true })

  // Deleting one end of a cable must detach the peer and remove the network:
  // a network only exists while two or more interfaces are attached to it.
  await fetch(`http://127.0.0.1:${mockPort}/__linked`, { method: 'POST' })
  await page.goto(`http://127.0.0.1:${uiPort}/ui/labs/lab1`)
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 2, null, { timeout: 15000 })
  await page.waitForFunction(() => document.querySelectorAll('.vue-flow__edge').length === 1, null, { timeout: 10000 })
  const linkedBefore = await page.locator('.ed-status span strong').allTextContents()
  await page.locator('.lab-node').nth(1).click({ button: 'right' })
  await page.waitForSelector('.context-menu')
  await page.locator('.context-menu').getByText('Delete', { exact: true }).click()
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 1, null, { timeout: 10000 })
  await page.waitForFunction(() => document.querySelectorAll('.vue-flow__edge').length === 0, null, { timeout: 10000 })
  const linkedAfter = await page.locator('.ed-status span strong').allTextContents()
  const pruneDeadline = Date.now() + 10000
  let prunedState = (await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()).lab
  while (prunedState.node_list.length !== 1 && Date.now() < pruneDeadline) {
    await sleep(200)
    prunedState = (await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()).lab
  }
  const linkedRemaining = prunedState.node_list.map((node) => ({ id: node.id, network_id: node.interfaces[0]?.network_id ?? null }))
  const linkedNetworksAfterSave = prunedState.network_list.length

  await page.getByRole('link', { name: '← Labs' }).click()
  await page.waitForURL('**/ui/labs')
  await page.getByRole('link', { name: 'Images' }).click()
  await page.waitForURL('**/ui/images')
  await page.waitForSelector('.images-view tbody tr', { timeout: 10000 })
  let remainingImages = await page.locator('.images-view tbody tr').count()
  while (remainingImages > 0) {
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('button', { name: 'Delete' }).first().click()
    await page.waitForFunction((count) => document.querySelectorAll('.images-view tbody tr').length < count, remainingImages, { timeout: 10000 })
    remainingImages = await page.locator('.images-view tbody tr').count()
  }
  const imagesAfterDelete = await page.locator('.images-view tbody tr').count()

  const result = {
    loginMs,
    editorMs,
    consoleMs,
    terminalBounds,
    canvasMenu: canvasMenu.replace(/\s+/g, ' ').trim(),
    nodeMenu: nodeMenu.replace(/\s+/g, ' ').trim(),
    edgeMenu: edgeMenu.replace(/\s+/g, ' ').trim(),
    linkMenuRecreated,
    linkInterfaceOptions,
    linkTagsAfterRecreate,
    pickerReady: pickerReady.replace(/\s+/g, ' ').trim(),
    pickerNeedsImage: pickerNeedsImage.replace(/\s+/g, ' ').trim(),
    iolOptions,
    linkSummary: linkSummary.replace(/\s+/g, ' ').trim(),
    linkTags,
    interfaceLink,
    labsStatusBefore: labsStatusBefore.replace(/\s+/g, ' ').trim(),
    formatBadge: formatBadge.trim(),
    importReport: importReport.replace(/\s+/g, ' ').trim(),
    labsStatusRunning: labsStatusRunning.replace(/\s+/g, ' ').trim(),
    labsStatusAfterStop: labsStatusAfterStop.replace(/\s+/g, ' ').trim(),
    stopButtonsBefore,
    stopButtonsRunning,
    stopButtonsAfter,
    aboutText: aboutText.replace(/\s+/g, ' ').trim().slice(0, 120),
    aboutBadge,
    aboutDots,
    systemHost: systemHost.replace(/\s+/g, ' ').trim().slice(0, 240),
    systemVersionBadges,
    systemPayloadCards,
    editorButtons,
    addLinkTop,
    addLinkBottom,
    addLinkEnabled,
    editorChrome,
    nodeCountBeforeBatch,
    nodeCountAfterBatch,
    batchNodes,
    resizeFrames,
    heightBefore,
    heightAfter,
    cssHeightBefore,
    cssHeightAfter,
    dockStoredHeight,
    chromeLayoutConsole,
    initialNodes,
    addedNodes,
    savedText,
    conflictText: conflictText.replace(/\s+/g, ' ').trim(),
    consoleTab,
    nodeFields,
    imageRow: imageRow.replace(/\s+/g, ' ').trim(),
    imageOptions,
    addedImageValue,
    addedImageOptions,
    batchNodeImage: batchNode?.image,
    iolNodeImage: iolNode?.image,
    renamePrefill,
    dockPersists,
    tableRows,
    xtermCount,
    startToast: startToast.replace(/\s+/g, ' ').trim(),
    fiftyMs,
    largeMs,
    linkedBefore,
    linkedAfter,
    linkedRemaining,
    linkedNetworksAfterSave,
    imagesAfterDelete,
    pageErrors: errors
  }
  console.log(JSON.stringify(result, null, 2))
  if (errors.length) throw new Error(`page errors: ${errors.join('; ')}`)
  if (addedNodes !== initialNodes + 1) throw new Error('palette add failed')
  if (!/saved/i.test(savedText)) throw new Error('autosave did not complete')
  if (systemVersionBadges !== 0) throw new Error('system page still shows a version badge')
  if (systemPayloadCards !== 0 || systemHost.includes('Host status payload')) throw new Error('system host tab still shows the raw JSON payload card')
  if (!/CPU usage/i.test(systemHost) || !/\d+(?:\.\d+)?%/.test(systemHost)) throw new Error(`system host tab has no CPU usage percentage: ${systemHost}`)
  if (aboutDots !== 0) throw new Error('about page still shows status dots')
  if (!/^v\d+\.\d+\.\d+$/i.test(aboutBadge)) throw new Error(`about page version badge missing: ${aboutBadge}`)
  if (!editorButtons.includes('+ Add link')) throw new Error(`top bar missing + Add link: ${editorButtons}`)
  if (editorButtons.includes('Save') || editorButtons.includes('Validate') || editorButtons.some((text) => /^Align/.test(text))) {
    throw new Error(`removed editor controls still present: ${editorButtons}`)
  }
  if (addLinkTop !== 1 || addLinkBottom !== 0) throw new Error(`+ Add link is not in the top bar only: top=${addLinkTop} bottom=${addLinkBottom}`)
  if (!addLinkEnabled) throw new Error('+ Add link is disabled')
  if (batchNodes !== addedNodes + 3) throw new Error(`picker count=3 added ${batchNodes - addedNodes} nodes`)
  if (Number.parseInt(nodeCountBeforeBatch, 10) + 3 !== Number.parseInt(nodeCountAfterBatch, 10)) {
    throw new Error(`status bar node count did not rise by 3: ${nodeCountBeforeBatch} -> ${nodeCountAfterBatch}`)
  }
  if (editorChrome.overlap) throw new Error(`Vue Flow chrome overlaps the toolbar/status bar: ${JSON.stringify(editorChrome)}`)
  if (chromeLayoutConsole.overlap) throw new Error(`Vue Flow chrome overlaps the toolbar with the console open: ${JSON.stringify(chromeLayoutConsole)}`)
  if (resizeFrames.length) throw new Error(`console sent resize frames: ${JSON.stringify(resizeFrames)}`)
  if (!(heightAfter > heightBefore + 50)) throw new Error(`dock drag did not resize the console body: ${heightBefore} -> ${heightAfter}`)
  if (!(Number.parseFloat(cssHeightAfter) > Number.parseFloat(cssHeightBefore))) throw new Error(`--console-h did not change: ${cssHeightBefore} -> ${cssHeightAfter}`)
  if (!dockStoredHeight || Number(dockStoredHeight) !== Math.round(Number.parseFloat(cssHeightAfter))) {
    throw new Error(`dock height was not persisted: ${dockStoredHeight} vs ${cssHeightAfter}`)
  }
  if (!canvasMenu.includes('Add node')) throw new Error(`canvas context menu missing Add node: ${canvasMenu}`)
  if (!nodeMenu.includes('Open console')) throw new Error('node context menu missing Open console')
  if (!nodeMenu.includes('Stop node') || nodeMenu.includes('Start node') || nodeMenu.includes('Wipe node')) {
    throw new Error(`running node menu offers the wrong controls: ${nodeMenu}`)
  }
  if (!edgeMenu.includes('Delete link')) throw new Error('link context menu missing Delete link')
  if (linkMenuRecreated !== 1) throw new Error('context-menu link creation failed')
  if (!linkInterfaceOptions.some((option) => option.includes('eth0'))) throw new Error(`link dialog missing interfaces: ${linkInterfaceOptions}`)
  if (!pickerReady.includes('Linux') || pickerReady.includes('Cisco IOL')) throw new Error('template picker ready group is wrong')
  if (!pickerNeedsImage.includes('Cisco IOL') || pickerNeedsImage.includes('Linux')) throw new Error('template picker needs-image group is wrong')
  if (!iolOptions.some((option) => option.startsWith('e0/0'))) throw new Error(`IOL interface labels missing: ${iolOptions}`)
  if (!iolOptions.some((option) => option.startsWith('e0/1'))) throw new Error(`IOL interface labels missing: ${iolOptions}`)
  if (!linkSummary.includes('creates a new link')) throw new Error(`link summary wrong: ${linkSummary}`)
  if (!linkTags.includes('e0/1')) throw new Error(`link end tag missing IOL port: ${linkTags}`)
  if (!interfaceLink.iol || interfaceLink.iol !== interfaceLink.r2) throw new Error(`chosen interfaces were not linked: ${JSON.stringify(interfaceLink)}`)
  if (stopButtonsBefore !== 0) throw new Error('stop button shown for a stopped lab')
  if (!/stopped/i.test(labsStatusBefore)) throw new Error(`labs status should start stopped: ${labsStatusBefore}`)
  if (!/legacy/i.test(formatBadge)) throw new Error(`lab format badge missing: ${formatBadge}`)
  if (!/image_unmapped/i.test(importReport) || !importReport.includes('pnet0')) throw new Error(`import report incomplete: ${importReport}`)
  if (!/running/i.test(labsStatusRunning) || stopButtonsRunning !== 1) throw new Error('running lab status/stop button missing')
  if (!/stopped/i.test(labsStatusAfterStop) || stopButtonsAfter !== 0) throw new Error('stopping the lab did not clear the status/button')
  if (!/AGPL-3.0-or-later/.test(aboutText)) throw new Error('about page missing licence')
  if (terminalBounds.screenBottom > terminalBounds.paneBottom + 1) throw new Error(`terminal clipped: ${JSON.stringify(terminalBounds)}`)
  if (nodeFields.nic !== 'virtio' || nodeFields.serial !== 2) throw new Error('node nic/serial were not saved')
  if (!imageRow.includes('Renamed base')) throw new Error('image rename failed')
  if (renamePrefill.name !== 'base' || renamePrefill.reference !== 'base.qcow2') {
    throw new Error(`rename dialog was not prefilled: ${JSON.stringify(renamePrefill)}`)
  }
  if (!imageOptions.some((option) => option.includes('Renamed base'))) throw new Error('inspector image picker missing uploaded image')
  if (imageOptions.some((option) => option.includes('Other image'))) {
    throw new Error(`inspector lists an image that is not mapped to the template: ${JSON.stringify(imageOptions)}`)
  }
  if (addedImageValue !== 'base.qcow2') throw new Error(`added node did not get the mapped image: ${addedImageValue}`)
  if (!addedImageOptions.some((option) => option.includes('Renamed base'))) {
    throw new Error(`inspector image picker missing the mapped image: ${JSON.stringify(addedImageOptions)}`)
  }
  if (addedImageOptions.some((option) => option.includes('Other image'))) {
    throw new Error(`inspector lists a non-mapped image: ${JSON.stringify(addedImageOptions)}`)
  }
  if (batchNode?.image !== 'base.qcow2') throw new Error(`saved batch node has the wrong image: ${batchNode?.image}`)
  if (iolNode?.image !== '') throw new Error(`unmapped template got an image: ${iolNode?.image}`)
  if (!/save conflict/i.test(conflictText)) throw new Error('conflict dialog missing')
  if (!consoleTab.trim()) throw new Error('console tab missing')
  if (xtermCount < 1) throw new Error('xterm not mounted')
  if (tableRows < 5) throw new Error('table fallback missing nodes')
  if (dockPersists !== 1) throw new Error('console dock did not persist across routes')
  if (fiftyMs > 5000) throw new Error(`50-node editor open took ${fiftyMs}ms`)
  if (largeMs > 10000) throw new Error(`100-node editor open took ${largeMs}ms`)
  if (linkedBefore[0] !== '2' || linkedBefore[1] !== '1') throw new Error(`linked lab did not load 2 nodes / 1 link: ${linkedBefore}`)
  if (linkedAfter[0] !== '1' || linkedAfter[1] !== '0') throw new Error(`node deletion did not prune the cable: ${linkedAfter}`)
  if (linkedRemaining.length !== 1) throw new Error(`node deletion left ${linkedRemaining.length} nodes: ${JSON.stringify(linkedRemaining)}`)
  if (linkedRemaining[0].network_id !== '') throw new Error(`peer port still attached after delete: ${JSON.stringify(linkedRemaining)}`)
  if (linkedNetworksAfterSave !== 0) throw new Error(`saved lab has an orphan network after delete: ${JSON.stringify(prunedState.network_list)}`)
  if (imagesAfterDelete !== 0) throw new Error('image delete failed')
  if (!/start/i.test(startToast)) throw new Error('start job toast missing')
  console.log('UI_E2E_OK')
} finally {
  clearTimeout(watchdog)
  await browser?.close()
  try { process.kill(-vite.pid, 'SIGTERM') } catch {}
  mock.close()
}
