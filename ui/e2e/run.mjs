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

// Generic, distributable YAML: template ids and a store reference only.
const yamlDocument = (name, { nodes = 2, networks = 1, image = false } = {}) => {
  const lines = ['schema: 1', `name: ${name}`]
  if (image) lines.push('defaults:', '  template: iol', '  image: base.qcow2')
  lines.push('nodes:')
  for (let index = 1; index <= nodes; index += 1) lines.push(`  R${index}:`, '    template: iol')
  if (networks) lines.push('networks:', '  lan-a: {}')
  lines.push('links:', '  - [R1.e0/0, R2.e0/0]')
  return `${lines.join('\n')}\n`
}
const YAML_FILE_DOCUMENT = yamlDocument('YAML File Lab', { image: true })
const YAML_PASTE_DOCUMENT = yamlDocument('Paste YAML Lab', { nodes: 3, image: true })
const YAML_APPLIED_DOCUMENT = yamlDocument('YAML Applied Lab', { nodes: 3 })

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

  // Navigation shell: exactly three rail destinations, version only in the
  // rail footer, and the footer survives the narrow/collapsed rail.
  const railLinks = (await page.locator('.rail .rail-link').allTextContents()).map((text) => text.trim())
  const railFooter = (await page.locator('.rail-footer').innerText()).replace(/\s+/g, ' ').trim()
  const topbarVersionBadges = await page.locator('.topbar .badge.version').count()
  const versionBadges = await page.locator('.badge.version').count()
  const workspaceNames = await page.locator('.workspace-name').count()

  await page.setViewportSize({ width: 900, height: 1000 })
  const narrowRailVisible = await page.locator('.rail').isVisible()
  const narrowFooterVisible = await page.locator('.rail-footer').isVisible()
  const narrowFooterText = (await page.locator('.rail-footer').innerText()).replace(/\s+/g, ' ').trim()
  await page.setViewportSize({ width: 1440, height: 1000 })

  // Legacy deep links land on the new destinations with the right tab.
  await page.goto(`http://127.0.0.1:${uiPort}/ui/templates`)
  await page.waitForURL(/\/ui\/appliances\?tab=templates$/)
  const templatesTabSelected = await page.getByRole('tab', { name: 'Templates' }).getAttribute('aria-selected')
  const templatesOwnHead = await page.locator('.templates .page-head').count()
  await page.getByRole('tab', { name: 'Images' }).click()
  await page.waitForURL(/\/ui\/appliances\?tab=images$/)
  const imagesOwnHeading = await page.locator('.images-view h1').count()

  await page.goto(`http://127.0.0.1:${uiPort}/ui/about`)
  await page.waitForURL(/\/ui\/system\?tab=About$/)
  const aboutTabSelected = await page.getByRole('tab', { name: 'About' }).getAttribute('aria-selected')
  const aboutText = await page.locator('.system-view .about-view').innerText()
  const aboutOwnHead = await page.locator('.about-view .page-head').count()
  const aboutDots = await page.locator('.about-view .dot').count()

  // System page smoke: host, settings, logs, and tokens.
  await page.getByRole('link', { name: 'System' }).click()
  await page.waitForURL('**/ui/system')
  await page.getByRole('tab', { name: 'Host' }).click()
  const systemHost = await page.locator('.system-grid').first().innerText()
  const systemVersionBadges = await page.locator('.system-view .page-head .badge').count()
  const systemPayloadCards = await page.locator('.system-grid').first().locator('pre.log-view').count()
  await page.getByRole('tab', { name: 'Settings' }).click()
  const adminDemoTabCount = await page.getByRole('tab', { name: 'Demo', exact: true }).count()
  await page.getByRole('tab', { name: 'Demo', exact: true }).click()
  await page.locator('.demo-panel select[aria-label="Demo mode"]').waitFor({ state: 'visible' })
  await page.waitForFunction(() => document.querySelectorAll('.demo-templates input').length >= 2, null, { timeout: 10000 })
  const demoPanelTemplates = await page.locator('.demo-templates input').count()
  const demoSecretStatus = (await page.locator('.demo-panel .secret-field .badge').innerText()).trim()

  // The sweeper panel shows the last sweep plus host/guest counts, and the
  // action refreshes both the toast summary and the status block.
  await page.locator('[data-test="demo-status"]').waitFor({ state: 'visible' })
  const demoLastSweepBefore = (await page.locator('[data-test="demo-last-sweep"]').innerText()).trim()
  const demoMemoryBefore = (await page.locator('[data-test="demo-memory"]').innerText()).trim()
  const demoGuestsBefore = (await page.locator('[data-test="demo-guests"]').innerText()).trim()
  const demoNodesBefore = (await page.locator('[data-test="demo-nodes"]').innerText()).trim()
  await page.getByRole('button', { name: 'Sweep now' }).click()
  await page.waitForFunction(
    () => document.querySelector('[data-test="demo-last-sweep"]')?.textContent.trim() !== 'never',
    null,
    { timeout: 10000 }
  )
  const demoLastSweepAfter = (await page.locator('[data-test="demo-last-sweep"]').innerText()).trim()
  const demoMemoryAfter = (await page.locator('[data-test="demo-memory"]').innerText()).trim()
  await page.waitForFunction(
    () => [...document.querySelectorAll('.toast')].some((toast) => /Sweep complete: 2 guests purged, 3 labs wiped, 1 node stopped\./.test(toast.textContent || '')),
    null,
    { timeout: 10000 }
  )
  const demoSweepToast = (await page.locator('.toasts').innerText()).replace(/\s+/g, ' ').trim()
  const demoSweepSummary = (await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()).lastDemoSweep
  await page.getByRole('tab', { name: 'Tokens' }).click()
  await page.locator('.token-form input').first().fill('e2e token')
  await page.getByRole('button', { name: 'Create token' }).click()
  await page.waitForFunction(() => document.querySelector('.token-once')?.textContent.includes('tl_mock_token'), null, { timeout: 10000 })
  await page.getByRole('tab', { name: 'About' }).click()
  await page.waitForURL(/\/ui\/system\?tab=About$/)
  const aboutTabAfterTokens = await page.getByRole('tab', { name: 'About' }).getAttribute('aria-selected')
  await page.getByRole('link', { name: 'Labs', exact: true }).click()
  await page.waitForURL('**/ui/labs')
  await page.waitForSelector('.lab-table tbody tr')

  // Create project dialog: name plus optional folder.
  await page.getByRole('button', { name: 'Create project' }).click()
  await page.waitForSelector('.create-project')
  await page.locator('.create-project input[aria-label="Project name"]').fill('Dialog Lab')
  await page.locator('.create-project input[aria-label="Project folder"]').fill('e2e-folder')
  await page.locator('.create-project').getByRole('button', { name: 'Create', exact: true }).click()
  await page.waitForFunction(() => !document.querySelector('.create-project'), null, { timeout: 10000 })
  await page.waitForURL((url) => /\/ui\/labs\/[^/]+$/.test(url.pathname), { timeout: 10000 })
  const createdEditorURL = page.url()
  await page.waitForFunction(() => document.querySelector('input[aria-label="Lab name"]')?.value === 'Dialog Lab', null, { timeout: 10000 })
  await page.waitForFunction(() => !document.querySelector('.editor .error.banner'), null, { timeout: 10000 })
  const createdState = await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()
  const createdLab = (createdState.createdLabs || []).find((candidate) => candidate.name === 'Dialog Lab')
  const createdLabPath = createdLab?.path
  const createdLabFolder = createdState.lastMove?.folder
  await page.locator('.ed-back').click()
  await page.waitForURL('**/ui/labs')
  await page.waitForFunction(() => document.querySelector('.lab-table tbody')?.textContent.includes('Dialog Lab'), null, { timeout: 10000 })
  const createDialogClosed = await page.locator('.create-project').count()

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

  // The same import control accepts YAML; detection is server-side.
  await page.locator('.toolbar input[type=file]').setInputFiles({
    name: 'generic-lab.yaml',
    mimeType: 'application/yaml',
    buffer: Buffer.from(YAML_FILE_DOCUMENT)
  })
  await page.waitForSelector('.import-report')
  const yamlImportReport = (await page.locator('.import-report').innerText()).replace(/\s+/g, ' ').trim()
  await page.locator('.import-report').getByRole('button', { name: 'Close' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.lab-table tbody tr')].some((row) => row.textContent.includes('YAML File Lab')), null, { timeout: 10000 })

  // Paste-YAML is the second mode of the Create project dialog: validate in
  // place, then create through the virtual-file import path and move it.
  await page.getByRole('button', { name: 'Create project' }).click()
  await page.getByRole('tab', { name: 'From YAML' }).click()
  const yamlNameFieldHidden = await page.locator('.create-project input[aria-label="Project name"]').count()
  await page.locator('.create-project textarea[aria-label="Lab YAML"]').fill(YAML_PASTE_DOCUMENT)
  await page.locator('.create-project input[aria-label="Project folder"]').fill('yaml-folder')
  await page.locator('.create-project').getByRole('button', { name: 'Validate' }).click()
  await page.waitForFunction(() => document.querySelector('.yaml-counts')?.textContent.includes('3 nodes'), null, { timeout: 10000 })
  const pasteValidationText = (await page.locator('.create-project .yaml-validation').innerText()).replace(/\s+/g, ' ').trim()
  const pasteWarningCount = await page.locator('.create-project .yaml-warnings li').count()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].length === 0, null, { timeout: 10000 }).catch(() => {})
  await page.locator('.create-project').getByRole('button', { name: 'Create from YAML' }).click()
  await page.waitForFunction(() => !document.querySelector('.create-project'), null, { timeout: 10000 })
  await page.waitForURL((url) => /\/ui\/labs\/[^/]+$/.test(url.pathname), { timeout: 10000 })
  const pasteEditorURL = page.url()
  await page.waitForFunction(() => document.querySelector('input[aria-label="Lab name"]')?.value === 'Paste YAML Lab', null, { timeout: 10000 })
  await page.waitForFunction(() => !document.querySelector('.editor .error.banner'), null, { timeout: 10000 })
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /created from YAML with 1 warning/i.test(toast.textContent || '')), null, { timeout: 10000 })
  const pasteToast = (await page.locator('.toasts').innerText()).replace(/\s+/g, ' ').trim()
  const pasteState = await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()
  const pasteLab = (pasteState.createdLabs || []).find((candidate) => candidate.name === 'Paste YAML Lab')
  const pasteFolder = pasteState.lastMove?.folder
  const pasteDialogClosed = await page.locator('.create-project').count()
  await page.locator('.ed-back').click()
  await page.waitForURL('**/ui/labs')
  await page.waitForFunction(() => document.querySelector('.lab-table tbody')?.textContent.includes('Paste YAML Lab'), null, { timeout: 10000 })

  // Images: upload, rename, map a template.
  await page.getByRole('link', { name: 'Appliances' }).click()
  await page.waitForURL('**/ui/appliances')
  await page.getByRole('tab', { name: 'Images' }).click()
  await page.waitForURL(/\/ui\/appliances\?tab=images$/)
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
  const yamlButtonWithoutMarker = await page.locator('.ed-actions').getByRole('button', { name: 'YAML', exact: true }).count()
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

  // Docker nodes: the ready group always lists them, the picker seeds the
  // node's container reference from the template, and the Inspector offers the
  // locally pulled images while the store image row disappears.
  await page.locator('.add-node').click()
  await page.waitForSelector('.template-picker')
  const dockerTile = page.locator('[data-group="ready"] li').filter({ hasText: 'FRR Router' })
  const dockerTileText = (await dockerTile.innerText()).replace(/\s+/g, ' ').trim()
  await dockerTile.getByRole('button', { name: 'Add' }).click()
  await page.waitForFunction((count) => document.querySelectorAll('.lab-node').length > count, batchNodes)
  await page.locator('.lab-node').last().evaluate((element) => element.dispatchEvent(new MouseEvent('click', { bubbles: true })))
  await page.waitForFunction(() => document.querySelector('.inspector input')?.value === 'FRR Router7', null, { timeout: 10000 })
  const dockerImageInput = page.locator('.inspector input[list="docker-image-options"]')
  const dockerImageValue = await dockerImageInput.inputValue()
  const dockerImagePlaceholder = await dockerImageInput.getAttribute('placeholder')
  const dockerImageHint = (await page.locator('.inspector p.hint').first().innerText()).replace(/\s+/g, ' ').trim()
  const dockerImageOptions = await page.locator('datalist#docker-image-options option').evaluateAll((options) => options.map((option) => option.value))
  const dockerTypeValue = await page.locator('.inspector label').filter({ hasText: 'Type' }).locator('select').inputValue()
  const dockerConsoleOptions = await page.locator('.inspector label').filter({ hasText: 'Console' }).locator('select option').evaluateAll((options) => options.map((option) => option.value))
  const dockerHiddenRows = {
    nic: await page.locator('.inspector label').filter({ hasText: 'NIC driver' }).count(),
    serial: await page.locator('.inspector label').filter({ hasText: 'Serial ports' }).count(),
    iso: await page.locator('.inspector label').filter({ hasText: 'ISO' }).count(),
    config: await page.locator('.inspector label').filter({ hasText: 'Startup config' }).count()
  }
  await dockerImageInput.fill('alpine:3.20')
  await page.waitForTimeout(1800)
  const dockerLab = (await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()).lab
  const dockerNode = dockerLab.node_list.find((node) => node.id === '7')

  // Switching an existing qemu node to docker clears the store image and swaps
  // the image row to the free-text container field; switching back clears it
  // so no container reference can leak into the store-image select.
  await page.locator('.lab-node').filter({ hasText: 'Linux4' }).evaluate((element) => element.dispatchEvent(new MouseEvent('click', { bubbles: true })))
  await page.waitForFunction(() => document.querySelector('.inspector input')?.value === 'Linux4', null, { timeout: 10000 })
  const kindSelect = page.locator('.inspector label').filter({ hasText: 'Type' }).locator('select')
  await kindSelect.selectOption('docker')
  await page.waitForSelector('.inspector input[list="docker-image-options"]')
  const switchedDockerImage = await page.locator('.inspector input[list="docker-image-options"]').inputValue()
  const switchedConsoleOptions = await page.locator('.inspector label').filter({ hasText: 'Console' }).locator('select option').evaluateAll((options) => options.map((option) => option.value))
  await kindSelect.selectOption('qemu')
  await page.waitForSelector('.inspector label:has-text("Image") select')
  const switchedBackImage = await page.locator('.inspector label').filter({ hasText: 'Image' }).locator('select').inputValue()

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

  // YAML source panel: hidden without a marker, Apply greyed while a node runs
  // or the text is unchanged, then apply and staleness after a canvas edit.
  await fetch(`http://127.0.0.1:${mockPort}/__yaml`, { method: 'POST' })
  await page.goto(`http://127.0.0.1:${uiPort}/ui/labs/lab1`)
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 2, null, { timeout: 15000 })
  const yamlButtonWithMarker = await page.locator('.ed-actions').getByRole('button', { name: 'YAML', exact: true }).count()
  await page.locator('.ed-actions').getByRole('button', { name: 'YAML', exact: true }).click()
  await page.waitForSelector('.yaml-panel')
  const yamlPanelMarker = (await page.locator('.yaml-panel .yaml-marker').innerText()).replace(/\s+/g, ' ').trim()
  const yamlRunningHint = (await page.locator('.yaml-panel .yaml-running').innerText()).trim()
  const yamlApplyDisabledRunning = await page.locator('.yaml-panel .yaml-apply').isDisabled()
  await page.locator('.yaml-panel').getByRole('button', { name: 'Close' }).click()
  await page.waitForFunction(() => !document.querySelector('.yaml-panel'))

  await fetch(`http://127.0.0.1:${mockPort}/__yaml-stopped`, { method: 'POST' })
  await page.goto(`http://127.0.0.1:${uiPort}/ui/labs/lab1`)
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 2, null, { timeout: 15000 })
  await page.locator('.ed-actions').getByRole('button', { name: 'YAML', exact: true }).click()
  await page.waitForSelector('.yaml-panel')
  const yamlApplyDisabledUnchanged = await page.locator('.yaml-panel .yaml-apply').isDisabled()
  await page.locator('.yaml-panel textarea[aria-label="Lab YAML source"]').fill(YAML_APPLIED_DOCUMENT)
  await page.locator('.yaml-panel').getByRole('button', { name: 'Validate' }).click()
  await page.waitForFunction(() => document.querySelector('.yaml-panel .yaml-counts')?.textContent.includes('3 nodes'), null, { timeout: 10000 })
  const yamlValidateText = (await page.locator('.yaml-panel .yaml-validation').innerText()).replace(/\s+/g, ' ').trim()
  const yamlApplyEnabled = await page.locator('.yaml-panel .yaml-apply').isEnabled()
  await page.locator('.yaml-panel .yaml-apply').click()
  await page.waitForFunction(() => !document.querySelector('.yaml-panel'), null, { timeout: 15000 })
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 3, null, { timeout: 15000 })
  const appliedState = await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()
  const appliedLabName = appliedState.lab.name
  const appliedRevision = appliedState.lab.revision
  const appliedSourceName = appliedState.lab.extensions?.source?.name
  const appliedNodeCount = appliedState.lab.node_list.length

  // A canvas edit bumps the lab revision; the marker stays behind, so the panel
  // warns and a later Apply replaces the canvas state.
  await page.locator('.ed-topbar input.lab-name').fill('Canvas Edited')
  const staleDeadline = Date.now() + 12000
  let staleState = appliedState
  while (staleState.lab.revision <= appliedRevision && Date.now() < staleDeadline) {
    await sleep(250)
    staleState = await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()
  }
  await page.locator('.ed-actions').getByRole('button', { name: 'YAML', exact: true }).click()
  await page.waitForSelector('.yaml-panel .yaml-stale', { timeout: 10000 })
  const yamlStaleText = (await page.locator('.yaml-panel .yaml-stale').innerText()).trim()
  const yamlReapplyText = (await page.locator('.yaml-panel .yaml-reapply').innerText()).trim()
  const yamlStaleApplyEnabled = await page.locator('.yaml-panel .yaml-apply').isEnabled()
  const staleSource = await page.locator('.yaml-panel textarea[aria-label="Lab YAML source"]').inputValue()
  await page.locator('.yaml-panel .yaml-apply').click()
  await page.waitForFunction(() => !document.querySelector('.yaml-panel'), null, { timeout: 15000 })
  const resolvedDeadline = Date.now() + 12000
  let resolvedState = staleState
  while (resolvedState.lab.revision <= staleState.lab.revision && Date.now() < resolvedDeadline) {
    await sleep(250)
    resolvedState = await (await fetch(`http://127.0.0.1:${mockPort}/__state`)).json()
  }
  const canvasEditOverwritten = resolvedState.lab.name === 'YAML Applied Lab'
  const resolvedRevision = resolvedState.lab.revision
  const reappliedUnchanged = resolvedState.lastYamlApply?.yaml === staleSource
  await page.waitForFunction(() => document.querySelectorAll('.lab-node').length === 3, null, { timeout: 15000 })
  await page.locator('.ed-actions').getByRole('button', { name: 'YAML', exact: true }).click()
  await page.waitForSelector('.yaml-panel')
  const yamlStaleAfterReapply = await page.locator('.yaml-panel .yaml-stale').count()
  const yamlReapplyAfterReapply = await page.locator('.yaml-panel .yaml-reapply').count()
  await page.locator('.yaml-panel').getByRole('button', { name: 'Close' }).click()
  await page.waitForFunction(() => !document.querySelector('.yaml-panel'))

  await page.getByRole('link', { name: '← Labs' }).click()
  await page.waitForURL('**/ui/labs')
  await page.getByRole('link', { name: 'Appliances' }).click()
  await page.waitForURL('**/ui/appliances')
  await page.getByRole('tab', { name: 'Images' }).click()
  await page.waitForURL(/\/ui\/appliances\?tab=images$/)
  await page.waitForSelector('.images-view tbody tr', { timeout: 10000 })
  let remainingImages = await page.locator('.images-view tbody tr').count()
  while (remainingImages > 0) {
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('button', { name: 'Delete' }).first().click()
    await page.waitForFunction((count) => document.querySelectorAll('.images-view tbody tr').length < count, remainingImages, { timeout: 10000 })
    remainingImages = await page.locator('.images-view tbody tr').count()
  }
  const imagesAfterDelete = await page.locator('.images-view tbody tr').count()

  // System -> Users: full lifecycle, guards, and the self-service password dialog.
  await page.getByRole('link', { name: 'System' }).click()
  await page.waitForURL('**/ui/system')
  await page.getByRole('tab', { name: 'Users' }).click()
  await page.waitForSelector('.users-table tbody tr')
  const usersRowsAdmin = await page.locator('.users-table tbody tr').count()
  const usersAdminRow = (await page.locator('.users-table tbody tr[data-user="admin"]').innerText()).replace(/\s+/g, ' ').trim()
  const usersSelfBadge = (await page.locator('.users-table tbody tr[data-user="admin"] .badge.you').innerText()).trim()

  await page.locator('tr[data-user="admin"]').getByRole('button', { name: 'Edit' }).click()
  await page.waitForSelector('.edit-user-modal')
  const selfRoleDisabled = await page.locator('.edit-user-modal select[aria-label="Role"]').isDisabled()
  await page.locator('.edit-user-modal').getByRole('button', { name: 'Cancel' }).click()

  await page.getByRole('button', { name: 'Add user' }).click()
  await page.waitForSelector('.add-user-modal')
  await page.locator('.add-user-modal input[aria-label="Username"]').fill('e2e-editor')
  await page.locator('.add-user-modal select[aria-label="Role"]').selectOption('editor')
  await page.locator('.add-user-modal input[aria-label="Email"]').fill('e2e-editor@example.test')
  await page.locator('.add-user-modal input[aria-label="Display name"]').fill('E2E Editor')
  await page.locator('.add-user-modal input[aria-label="Expires on"]').fill('2026-12-31')
  await page.locator('.add-user-modal button[type="submit"]').click()
  await page.waitForSelector('.add-user-modal .token-once code', { timeout: 10000 })
  const addGeneratedPassword = (await page.locator('.add-user-modal .token-once code').innerText()).trim()
  const addPasswordOnceVisible = await page.locator('.add-user-modal form').count()
  await page.locator('.add-user-modal').getByRole('button', { name: 'Close' }).click()
  await page.waitForFunction(() => !document.querySelector('.add-user-modal'))
  await page.waitForFunction(() => [...document.querySelectorAll('.users-table tbody tr')].some((row) => row.dataset.user === 'e2e-editor'))
  const createdUsersState = await (await fetch(`http://127.0.0.1:${mockPort}/__users-state`)).json()
  const createdUserExpiration = createdUsersState.users.find((user) => user.username === 'e2e-editor')?.expiration
  await page.getByRole('button', { name: 'Add user' }).click()
  await page.waitForSelector('.add-user-modal')
  const addPasswordCleared = await page.locator('.add-user-modal .token-once').count()
  await page.locator('.add-user-modal').getByRole('button', { name: 'Cancel' }).click()

  const newUserRow = page.locator('.users-table tbody tr[data-user="e2e-editor"]')
  await newUserRow.getByRole('button', { name: 'Edit' }).click()
  await page.waitForSelector('.edit-user-modal')
  await page.locator('.edit-user-modal input[aria-label="Display name"]').fill('E2E Renamed')
  await page.locator('.edit-user-modal select[aria-label="Role"]').selectOption('user')
  await page.locator('.edit-user-modal input[aria-label="Expires on"]').fill('2027-06-01')
  await page.locator('.edit-user-modal button[type="submit"]').click()
  await page.waitForFunction(() => !document.querySelector('.edit-user-modal'))
  await page.waitForFunction(() => document.querySelector('tr[data-user="e2e-editor"]')?.textContent.includes('E2E Renamed'))
  const editedUserRole = (await newUserRow.locator('.badge.role').innerText()).trim()

  await newUserRow.getByRole('button', { name: 'Reset password' }).click()
  await page.waitForSelector('.reset-password-modal')
  await page.locator('.reset-password-modal button[type="submit"]').click()
  await page.waitForSelector('.reset-password-modal .token-once code')
  const resetGeneratedPassword = (await page.locator('.reset-password-modal .token-once code').innerText()).trim()
  await page.locator('.reset-password-modal').getByRole('button', { name: 'Close' }).click()
  await page.waitForFunction(() => !document.querySelector('.reset-password-modal'))

  page.once('dialog', (dialog) => dialog.accept())
  await newUserRow.getByRole('button', { name: 'Force logout' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /revoked/i.test(toast.textContent || '')), null, { timeout: 10000 })

  await newUserRow.getByRole('button', { name: 'Delete' }).click()
  await page.waitForSelector('.delete-user-modal')
  const deleteDialogText = (await page.locator('.delete-user-modal').innerText()).replace(/\s+/g, ' ').trim()
  await page.locator('.delete-user-modal input[aria-label="Also delete their labs"]').check()
  await page.locator('.delete-user-modal').getByRole('button', { name: 'Delete user' }).click()
  await page.waitForFunction(() => !document.querySelector('.delete-user-modal'))
  await page.waitForFunction(() => !document.querySelector('tr[data-user="e2e-editor"]'))
  const deleteWithLabs = (await (await fetch(`http://127.0.0.1:${mockPort}/__users-state`)).json()).lastUserAction

  await page.getByRole('button', { name: 'Add user' }).click()
  await page.waitForSelector('.add-user-modal')
  await page.locator('.add-user-modal input[aria-label="Username"]').fill('e2e-temp')
  await page.locator('.add-user-modal input[aria-label="Password"]').fill('Typed-Password-123!')
  await page.locator('.add-user-modal button[type="submit"]').click()
  await page.waitForFunction(() => !document.querySelector('.add-user-modal'))
  await page.waitForFunction(() => [...document.querySelectorAll('.users-table tbody tr')].some((row) => row.dataset.user === 'e2e-temp'))
  const typedCreateAction = (await (await fetch(`http://127.0.0.1:${mockPort}/__users-state`)).json()).lastUserAction
  const typedAddSecretPanel = await page.locator('.add-user-modal .token-once').count()
  const tempUserRow = page.locator('.users-table tbody tr[data-user="e2e-temp"]')
  await tempUserRow.getByRole('button', { name: 'Reset password' }).click()
  await page.waitForSelector('.reset-password-modal')
  await page.locator('.reset-password-modal input[aria-label="New password"]').fill('Typed-Reset-1234!')
  await page.locator('.reset-password-modal button[type="submit"]').click()
  await page.waitForFunction(() => !document.querySelector('.reset-password-modal'))
  const typedResetAction = (await (await fetch(`http://127.0.0.1:${mockPort}/__users-state`)).json()).lastUserAction
  const typedResetSecretPanel = await page.locator('.reset-password-modal .token-once').count()
  await tempUserRow.getByRole('button', { name: 'Delete' }).click()
  await page.locator('.delete-user-modal').getByRole('button', { name: 'Delete user' }).click()
  await page.waitForFunction(() => !document.querySelector('tr[data-user="e2e-temp"]'))
  const deleteWithoutLabs = (await (await fetch(`http://127.0.0.1:${mockPort}/__users-state`)).json()).lastUserAction

  await page.locator('tr[data-user="admin"]').getByRole('button', { name: 'Delete' }).click()
  await page.locator('.delete-user-modal').getByRole('button', { name: 'Delete user' }).click()
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /own account/i.test(toast.textContent || '')), null, { timeout: 10000 })
  const selfDeleteDialogOpen = await page.locator('.delete-user-modal').count()
  await page.locator('.delete-user-modal').getByRole('button', { name: 'Cancel' }).click()

  await page.getByRole('tab', { name: 'Settings' }).click()
  await page.waitForURL(/\/ui\/system\?tab=Settings$/)
  await page.getByRole('button', { name: 'Change password' }).click()
  await page.waitForSelector('.password-modal')
  await page.locator('.password-modal input[aria-label="Current password"]').fill('Teralab-Admin-2026!')
  await page.locator('.password-modal input[aria-label="New password"]').fill('New-Admin-Password-2026!')
  await page.locator('.password-modal input[aria-label="Confirm new password"]').fill('Mismatch-Password-2026!')
  await page.locator('.password-modal button[type="submit"]').click()
  const passwordMismatchText = (await page.locator('.password-modal .error').innerText()).trim()
  await page.locator('.password-modal input[aria-label="Confirm new password"]').fill('New-Admin-Password-2026!')
  await page.locator('.password-modal button[type="submit"]').click()
  await page.waitForFunction(() => !document.querySelector('.password-modal'))
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((toast) => /Password changed/i.test(toast.textContent || '')), null, { timeout: 10000 })

  await page.getByRole('button', { name: 'Log out' }).click()
  await page.waitForURL('**/ui/login')
  await page.getByLabel('Username').fill('viewer')
  await page.getByLabel('Password').fill('Teralab-Viewer-2026!')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL('**/ui/labs')
  await page.getByRole('link', { name: 'System' }).click()
  await page.waitForURL('**/ui/system')
  await page.waitForSelector('[role="tab"]')
  const viewerHasHostTab = await page.getByRole('tab', { name: 'Host', exact: true }).count()
  const viewerHasUsersTab = await page.getByRole('tab', { name: 'Users' }).count()
  const viewerHasDemoTab = await page.getByRole('tab', { name: 'Demo', exact: true }).count()
  const usersForbidden = await page.evaluate(async () => (await fetch('/api/v2/users', { credentials: 'same-origin' })).status)
  const remainingUsernames = (await (await fetch(`http://127.0.0.1:${mockPort}/__users-state`)).json()).users.map((user) => user.username)

  // Demo entry: disabled returns 404, the concurrency cap returns 403, and the
  // enabled flow mints a guest with an isolated empty lab list.
  const guestDisabledStatus = (await fetch(`http://127.0.0.1:${mockPort}/api/v2/auth/guest`, { method: 'POST' })).status
  await page.getByRole('button', { name: 'Log out' }).click()
  await page.waitForURL('**/ui/login')
  const setDemo = (payload) => fetch(`http://127.0.0.1:${mockPort}/__demo`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  await setDemo({ enabled: true, landing: 'demo', max_guests: 0, turnstile: false })
  const guestFullStatus = (await fetch(`http://127.0.0.1:${mockPort}/api/v2/auth/guest`, { method: 'POST' })).status
  await setDemo({ enabled: true, landing: 'demo', max_guests: 10, turnstile: true })
  const guestWithoutTokenStatus = (await fetch(`http://127.0.0.1:${mockPort}/api/v2/auth/guest`, { method: 'POST' })).status

  // The shipped 3x site key is an interactive widget: it emits no token until
  // execute() starts a challenge. The mock refuses a tokenless guest request
  // while Turnstile is on, so reaching the labs list through this stubbed
  // widget proves the UI asked it to execute and forwarded the callback token.
  const captchaContext = await browser.newContext()
  let executeOnSubmitCount = 0
  let executeOnSubmitWidgetId = ''
  let renderedTurnstileActions = []
  try {
    const captchaPage = await captchaContext.newPage()
    await captchaPage.addInitScript(() => {
      window.__turnstileExecutions = 0
      window.__turnstileActionOptions = {}
      window.turnstile = {
        render: (_element, options) => {
          const action = options.action || 'default'
          window.__turnstileActionOptions[action] = options
          return `e2e-turnstile-${action}`
        },
        execute: (widgetId) => {
          window.__turnstileExecutions += 1
          window.__turnstileWidgetId = widgetId
          const action = widgetId.replace(/^e2e-turnstile-/, '')
          setTimeout(() => window.__turnstileActionOptions[action]?.callback?.('e2e-turnstile-token'), 0)
        },
        reset: () => {},
        remove: () => {}
      }
    })
    await captchaPage.goto(`http://127.0.0.1:${uiPort}/ui/login`)
    await captchaPage.getByRole('button', { name: 'Enter demo' }).waitFor({ timeout: 10000 })
    renderedTurnstileActions = await captchaPage.evaluate(() => Object.keys(window.__turnstileActionOptions))
    await captchaPage.getByRole('button', { name: 'Enter demo' }).click()
    await captchaPage.waitForURL('**/ui/labs', { timeout: 10000 })
    await captchaPage.waitForFunction(() => document.querySelector('.labs'), null, { timeout: 10000 })
    executeOnSubmitCount = await captchaPage.evaluate(() => window.__turnstileExecutions)
    executeOnSubmitWidgetId = await captchaPage.evaluate(() => window.__turnstileWidgetId)
  } finally {
    await captchaContext.close()
  }
  await setDemo({ enabled: true, landing: 'demo', max_guests: 10, turnstile: false })
  await page.reload()
  await page.getByRole('button', { name: 'Enter demo' }).waitFor({ timeout: 10000 })
  await page.waitForFunction(() => new URL(window.location.href).searchParams.get('mode') === 'demo', null, { timeout: 10000 })
  const demoLandingUrl = page.url()
  const demoPanelPrimary = await page.locator('[data-test="demo-panel"]').evaluate((element) => element.classList.contains('is-primary'))
  await page.getByRole('button', { name: 'Enter demo' }).click()
  await page.waitForURL('**/ui/labs')
  await page.waitForFunction(() => document.querySelector('.labs .empty')?.textContent.includes('No labs yet'), null, { timeout: 10000 })
  const guestEmpty = (await page.locator('.labs .empty').innerText()).trim()
  const guestMe = await page.evaluate(async () => (await fetch('/api/v2/auth/me', { credentials: 'same-origin' })).json())

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
    dockerTileText,
    dockerImageValue,
    dockerImagePlaceholder,
    dockerImageHint,
    dockerImageOptions,
    dockerTypeValue,
    dockerConsoleOptions,
    dockerHiddenRows,
    dockerNodeImage: dockerNode?.image,
    dockerNodeType: dockerNode?.type,
    dockerNodeConsole: dockerNode?.console,
    switchedDockerImage,
    switchedConsoleOptions,
    switchedBackImage,
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
    railLinks,
    railFooter,
    topbarVersionBadges,
    versionBadges,
    workspaceNames,
    narrowRailVisible,
    narrowFooterVisible,
    narrowFooterText,
    templatesTabSelected,
    templatesOwnHead,
    imagesOwnHeading,
    aboutTabSelected,
    aboutTabAfterTokens,
    aboutOwnHead,
    aboutText: aboutText.replace(/\s+/g, ' ').trim().slice(0, 120),
    aboutDots,
    createdLabPath,
    createdLabFolder,
    createdEditorURL,
    createDialogClosed,
    yamlImportReport,
    yamlNameFieldHidden,
    pasteValidationText,
    pasteWarningCount,
    pasteToast,
    pasteEditorURL,
    pasteLabPath: pasteLab?.path,
    pasteFolder,
    pasteDialogClosed,
    yamlButtonWithoutMarker,
    yamlButtonWithMarker,
    yamlPanelMarker,
    yamlRunningHint,
    yamlApplyDisabledRunning,
    yamlApplyDisabledUnchanged,
    yamlValidateText,
    yamlApplyEnabled,
    appliedLabName,
    appliedRevision,
    appliedSourceName,
    appliedNodeCount,
    yamlStaleText,
    yamlReapplyText,
    yamlStaleApplyEnabled,
    canvasEditOverwritten,
    resolvedRevision,
    reappliedUnchanged,
    yamlStaleAfterReapply,
    yamlReapplyAfterReapply,
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
    usersRowsAdmin,
    usersAdminRow,
    usersSelfBadge,
    selfRoleDisabled,
    addGeneratedOnce: addGeneratedPassword.length >= 16,
    createdUserExpiration,
    addPasswordOnceVisible,
    addPasswordCleared,
    editedUserRole,
    resetGeneratedOnce: resetGeneratedPassword.length >= 16,
    deleteDialogText,
    deleteWithLabs,
    deleteWithoutLabs,
    selfDeleteDialogOpen,
    passwordMismatchText,
    typedCreateAction,
    typedAddSecretPanel,
    typedResetAction,
    typedResetSecretPanel,
    adminDemoTabCount,
    demoPanelTemplates,
    demoSecretStatus,
    demoLastSweepBefore,
    demoLastSweepAfter,
    demoMemoryBefore,
    demoMemoryAfter,
    demoGuestsBefore,
    demoNodesBefore,
    demoSweepToast,
    demoSweepSummary,
    viewerHasHostTab,
    viewerHasUsersTab,
    viewerHasDemoTab,
    usersForbidden,
    remainingUsernames,
    guestDisabledStatus,
    guestFullStatus,
    guestWithoutTokenStatus,
    executeOnSubmitCount,
    executeOnSubmitWidgetId,
    demoLandingUrl,
    demoPanelPrimary,
    guestEmpty,
    guestMe: guestMe.user,
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
  const normalizedRailLinks = railLinks.map((text) => text.toUpperCase())
  if (normalizedRailLinks.length !== 3 || normalizedRailLinks.join(',') !== 'LABS,APPLIANCES,SYSTEM') {
    throw new Error(`rail should list exactly Labs/Appliances/System: ${JSON.stringify(railLinks)}`)
  }
  if (topbarVersionBadges !== 0 || versionBadges !== 0) throw new Error(`version badge still rendered outside the rail footer: ${topbarVersionBadges}/${versionBadges}`)
  if (workspaceNames !== 0) throw new Error('workspace name chrome is still rendered')
  if (!/1\.6-dev/i.test(railFooter)) throw new Error(`rail footer is missing the version: ${railFooter}`)
  if (!narrowRailVisible || !narrowFooterVisible) throw new Error('collapsed rail hides its footer')
  if (!/1\.6-dev/i.test(narrowFooterText)) throw new Error(`collapsed rail footer is missing the version: ${narrowFooterText}`)
  if (/system online/i.test(narrowFooterText)) throw new Error(`collapsed rail footer still shows the status words: ${narrowFooterText}`)
  if (templatesTabSelected !== 'true' || templatesOwnHead !== 0) throw new Error(`/templates redirect did not select the embedded Templates tab: ${templatesTabSelected}/${templatesOwnHead}`)
  if (imagesOwnHeading !== 0) throw new Error('embedded Images view still renders its own heading')
  if (aboutTabSelected !== 'true' || aboutTabAfterTokens !== 'true' || aboutOwnHead !== 0) {
    throw new Error(`/about redirect did not select the embedded About tab: ${aboutTabSelected}/${aboutTabAfterTokens}/${aboutOwnHead}`)
  }
  if (createDialogClosed !== 0) throw new Error('create project dialog stayed open after success')
  if (!createdLab || !createdEditorURL.endsWith(`/ui/labs/${createdLab.id}`)) {
    throw new Error(`create project did not open the editor: ${createdEditorURL}`)
  }
  if (!createdLab || !createdLabPath?.includes('e2e-folder/')) throw new Error(`created project landed in the wrong folder: ${createdLabPath}`)
  if (createdLabFolder !== 'e2e-folder') throw new Error(`move request used the wrong folder: ${createdLabFolder}`)
  if (!/image_unmapped/i.test(yamlImportReport)) {
    throw new Error(`YAML file import report incomplete: ${yamlImportReport}`)
  }
  if (yamlNameFieldHidden !== 0) throw new Error('paste-YAML mode still shows the project name field')
  if (pasteWarningCount < 1 || !/image_unmapped/.test(pasteValidationText)) {
    throw new Error(`paste-YAML validation did not show warnings: ${pasteValidationText}`)
  }
  if (!/3 nodes/.test(pasteValidationText) || !/1 networks/.test(pasteValidationText) || !/1 links/.test(pasteValidationText)) {
    throw new Error(`paste-YAML validation counts are wrong: ${pasteValidationText}`)
  }
  if (!pasteLab?.path?.includes('yaml-folder/')) throw new Error(`paste-YAML project landed in the wrong folder: ${pasteLab?.path}`)
  if (pasteFolder !== 'yaml-folder') throw new Error(`paste-YAML move used the wrong folder: ${pasteFolder}`)
  if (pasteDialogClosed !== 0) throw new Error('paste-YAML dialog stayed open after success')
  if (!pasteLab || !pasteEditorURL.endsWith(`/ui/labs/${pasteLab.id}`)) {
    throw new Error(`paste-YAML did not open the editor: ${pasteEditorURL}`)
  }
  if (!/created from YAML with 1 warning/i.test(pasteToast)) throw new Error(`paste-YAML warning toast missing: ${pasteToast}`)
  if (yamlButtonWithoutMarker !== 0) throw new Error('YAML button is shown for a lab without a source marker')
  if (yamlButtonWithMarker !== 1) throw new Error('YAML button is missing for a lab with a source marker')
  if (!/triangle\.yaml/.test(yamlPanelMarker) || !/sha256:aaaaaaaaaaaa/.test(yamlPanelMarker)) {
    throw new Error(`YAML panel marker metadata is wrong: ${yamlPanelMarker}`)
  }
  if (!/Stop and wipe/.test(yamlRunningHint) || !yamlApplyDisabledRunning) {
    throw new Error(`Apply is not disabled while a node runs: ${yamlRunningHint}/${yamlApplyDisabledRunning}`)
  }
  if (!yamlApplyDisabledUnchanged) throw new Error('Apply is enabled while the YAML text is unchanged')
  if (!/3 nodes/.test(yamlValidateText) || !/1 networks/.test(yamlValidateText)) {
    throw new Error(`editor YAML validation counts are wrong: ${yamlValidateText}`)
  }
  if (!yamlApplyEnabled) throw new Error('Apply is disabled for a valid, changed, stopped lab')
  if (appliedLabName !== 'YAML Applied Lab' || appliedRevision < 3 || appliedNodeCount !== 3) {
    throw new Error(`apply did not replace the lab: ${appliedLabName}/${appliedRevision}/${appliedNodeCount}`)
  }
  if (appliedSourceName !== 'triangle.yaml') throw new Error(`apply did not restamp the source name: ${appliedSourceName}`)
  if (!/canvas has changed since this YAML was applied/.test(yamlStaleText)) {
    throw new Error(`staleness warning missing: ${yamlStaleText}`)
  }
  if (!yamlStaleApplyEnabled) throw new Error('Apply is disabled for an unchanged stale source')
  if (!/replace the canvas changes/.test(yamlReapplyText)) throw new Error(`re-apply confirmation missing: ${yamlReapplyText}`)
  if (!canvasEditOverwritten) throw new Error('apply did not win over the canvas edit')
  if (!reappliedUnchanged) throw new Error('re-apply did not post the unchanged stored YAML')
  if (!(resolvedRevision > appliedRevision)) throw new Error(`resolve apply did not bump the revision: ${appliedRevision} -> ${resolvedRevision}`)
  if (yamlStaleAfterReapply !== 0 || yamlReapplyAfterReapply !== 0) {
    throw new Error(`staleness warning did not clear after re-apply: ${yamlStaleAfterReapply}/${yamlReapplyAfterReapply}`)
  }
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
  if (!pickerReady.includes('FRR Router') || !pickerReady.includes('frrouting/frr:v8.4.1')) {
    throw new Error(`docker template is not in the ready group: ${pickerReady}`)
  }
  if (pickerNeedsImage.includes('FRR Router')) throw new Error(`docker template asked for an image mapping: ${pickerNeedsImage}`)
  if (!dockerTileText.includes('docker · frrouting/frr:v8.4.1')) {
    throw new Error(`docker tile does not name the container image: ${dockerTileText}`)
  }
  if (dockerImageValue !== 'frrouting/frr:v8.4.1') throw new Error(`added docker node has the wrong image: ${dockerImageValue}`)
  if (dockerImagePlaceholder !== 'frrouting/frr:v8.4.1') throw new Error(`docker image placeholder is wrong: ${dockerImagePlaceholder}`)
  if (!/frrouting\/frr:v8\.4\.1/.test(dockerImageHint) || !/Pulled images are offered/.test(dockerImageHint)) {
    throw new Error(`docker image hint is wrong: ${dockerImageHint}`)
  }
  if (!dockerImageOptions.includes('frrouting/frr:v8.4.1') || !dockerImageOptions.includes('alpine:3.20')) {
    throw new Error(`docker datalist is missing pulled images: ${JSON.stringify(dockerImageOptions)}`)
  }
  if (dockerTypeValue !== 'docker') throw new Error(`docker node type is wrong: ${dockerTypeValue}`)
  if (dockerConsoleOptions.includes('vnc') || !dockerConsoleOptions.includes('telnet')) {
    throw new Error(`docker console offers the wrong kinds: ${JSON.stringify(dockerConsoleOptions)}`)
  }
  if (dockerHiddenRows.nic || dockerHiddenRows.serial || dockerHiddenRows.iso || dockerHiddenRows.config) {
    throw new Error(`docker node still shows qemu-only rows: ${JSON.stringify(dockerHiddenRows)}`)
  }
  if (dockerNode?.image !== 'alpine:3.20' || dockerNode?.type !== 'docker' || dockerNode?.console !== 'telnet') {
    throw new Error(`docker node was not saved correctly: ${JSON.stringify(dockerNode)}`)
  }
  if (switchedDockerImage !== '') throw new Error(`container image was not cleared on kind switch: ${switchedDockerImage}`)
  if (switchedConsoleOptions.includes('vnc')) throw new Error(`switched docker console offers vnc: ${JSON.stringify(switchedConsoleOptions)}`)
  if (switchedBackImage !== '') throw new Error(`container reference leaked into the store image: ${switchedBackImage}`)
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
  if (usersRowsAdmin !== 3) throw new Error(`users tab listed ${usersRowsAdmin} users`)
  if (!usersAdminRow.includes('(you)') || !usersAdminRow.includes('admin')) throw new Error(`admin row is missing the self marker or role: ${usersAdminRow}`)
  if (usersSelfBadge !== '(you)') throw new Error(`self marker is wrong: ${usersSelfBadge}`)
  if (!selfRoleDisabled) throw new Error('role select is editable for your own account')
  if (addGeneratedPassword.length < 16) throw new Error('created user did not get a generated one-time password')
  const expectedCreatedExpiration = Math.floor(new Date('2026-12-31T23:59:59').getTime() / 1000)
  if (createdUserExpiration !== expectedCreatedExpiration) {
    throw new Error(`create sent expiration ${createdUserExpiration}, expected end-of-day ${expectedCreatedExpiration}`)
  }
  if (addPasswordOnceVisible !== 0) throw new Error('create form is still visible after generating a password')
  if (addPasswordCleared !== 0) throw new Error('generated password survived closing the add dialog')
  if (editedUserRole.toLowerCase() !== 'user') throw new Error(`edited user role was not applied: ${editedUserRole}`)
  if (resetGeneratedPassword.length < 16) throw new Error('password reset did not return a generated password')
  if (!/kept/i.test(deleteDialogText) || !/running/i.test(deleteDialogText)) {
    throw new Error(`delete dialog does not explain the lab policy: ${deleteDialogText}`)
  }
  if (deleteWithLabs?.type !== 'delete' || deleteWithLabs.delete_labs !== true) {
    throw new Error(`delete with labs sent the wrong flag: ${JSON.stringify(deleteWithLabs)}`)
  }
  if (deleteWithoutLabs?.type !== 'delete' || deleteWithoutLabs.delete_labs !== false) {
    throw new Error(`delete without labs sent the wrong flag: ${JSON.stringify(deleteWithoutLabs)}`)
  }
  if (selfDeleteDialogOpen !== 1) throw new Error('self-delete guard closed the dialog')
  if (!/do not match/i.test(passwordMismatchText)) throw new Error(`password confirmation was not validated: ${passwordMismatchText}`)
  if (typedCreateAction?.type !== 'create' || typedCreateAction.password_generated !== false) {
    throw new Error(`typed create reported a generated password: ${JSON.stringify(typedCreateAction)}`)
  }
  if (typedAddSecretPanel !== 0) throw new Error('typed create showed a one-time password panel')
  if (typedResetAction?.type !== 'password' || typedResetAction.password_generated !== false) {
    throw new Error(`typed reset reported a generated password: ${JSON.stringify(typedResetAction)}`)
  }
  if (typedResetSecretPanel !== 0) throw new Error('typed reset showed a one-time password panel')
  if (adminDemoTabCount !== 1) throw new Error(`admin Demo tab count is ${adminDemoTabCount}`)
  if (demoPanelTemplates < 2) throw new Error(`demo allowlist loaded ${demoPanelTemplates} templates`)
  if (demoSecretStatus !== 'configured') throw new Error(`demo secret status is ${demoSecretStatus}`)
  if (demoLastSweepBefore !== 'never') throw new Error(`demo last sweep did not start empty: ${demoLastSweepBefore}`)
  if (!demoLastSweepAfter || demoLastSweepAfter === 'never') {
    throw new Error(`demo last sweep did not refresh after sweeping: ${demoLastSweepAfter}`)
  }
  if (demoMemoryBefore !== '8192 MB' || demoMemoryAfter !== '6144 MB') {
    throw new Error(`demo memory status did not refresh: ${demoMemoryBefore} -> ${demoMemoryAfter}`)
  }
  if (demoGuestsBefore !== '0' || demoNodesBefore !== '1') {
    throw new Error(`demo counts are wrong: guests=${demoGuestsBefore} nodes=${demoNodesBefore}`)
  }
  if (!/Sweep complete: 2 guests purged, 3 labs wiped, 1 node stopped\./.test(demoSweepToast)) {
    throw new Error(`demo sweep toast is wrong: ${demoSweepToast}`)
  }
  if (
    demoSweepSummary?.guests_purged !== 2 ||
    demoSweepSummary?.labs_wiped !== 3 ||
    demoSweepSummary?.nodes_reaped !== 1
  ) {
    throw new Error(`demo sweep summary is wrong: ${JSON.stringify(demoSweepSummary)}`)
  }
  if (viewerHasHostTab !== 0) throw new Error('non-admin still sees the admin-only Host tab')
  if (viewerHasUsersTab !== 0) throw new Error('non-admin still sees the Users tab')
  if (viewerHasDemoTab !== 0) throw new Error('non-admin still sees the Demo tab')
  if (usersForbidden !== 403) throw new Error(`GET /api/v2/users returned ${usersForbidden} for a non-admin`)
  if (remainingUsernames.some((username) => username === 'e2e-editor' || username === 'e2e-temp')) {
    throw new Error(`deleted users are still listed: ${JSON.stringify(remainingUsernames)}`)
  }
  if (guestDisabledStatus !== 404) throw new Error(`guest endpoint returned ${guestDisabledStatus} while demo was disabled`)
  if (guestFullStatus !== 403) throw new Error(`guest endpoint returned ${guestFullStatus} when the demo was full`)
  if (guestWithoutTokenStatus !== 422) {
    throw new Error(`guest endpoint returned ${guestWithoutTokenStatus} without a Turnstile token`)
  }
  if (executeOnSubmitCount !== 1) {
    throw new Error(`Turnstile execute ran ${executeOnSubmitCount} times on submit`)
  }
  if (executeOnSubmitWidgetId !== 'e2e-turnstile-guest') {
    throw new Error(`Turnstile execute got widget id ${executeOnSubmitWidgetId}`)
  }
  if (!renderedTurnstileActions.includes('guest') || !renderedTurnstileActions.includes('login')) {
    throw new Error(`expected guest and login widgets, rendered ${JSON.stringify(renderedTurnstileActions)}`)
  }
  if (!demoLandingUrl.includes('mode=demo') || !demoPanelPrimary) {
    throw new Error(`demo landing did not select the demo panel: ${demoLandingUrl} primary=${demoPanelPrimary}`)
  }
  if (!/No labs yet/.test(guestEmpty)) throw new Error(`guest lab list is not empty: ${guestEmpty}`)
  if (guestMe.user?.role !== 'editor' || guestMe.user?.guest !== true || !/^g-[0-9a-f]{16}$/.test(guestMe.user?.username || '')) {
    throw new Error(`guest session shape is wrong: ${JSON.stringify(guestMe.user)}`)
  }
  if (!/start/i.test(startToast)) throw new Error('start job toast missing')
  console.log('UI_E2E_OK')
} finally {
  clearTimeout(watchdog)
  await browser?.close()
  try { process.kill(-vite.pid, 'SIGTERM') } catch {}
  mock.close()
}
