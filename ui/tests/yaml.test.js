// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@vue-flow/core', async () => {
  const { ref } = await import('vue')

  return {
    VueFlow: { name: 'VueFlow', props: ['nodes', 'edges'], template: '<div class="vflow" />' },
    useVueFlow: () => ({
      screenToFlowCoordinate: ({ x, y }) => ({ x, y }),
      fitView: () => {},
      getSelectedNodes: ref([]),
      getViewport: () => ({ x: 0, y: 0, zoom: 1 }),
      setViewport: () => {},
      viewport: ref({ x: 0, y: 0, zoom: 1 })
    })
  }
})

vi.mock('../src/api', () => ({
  api: {
    labs: vi.fn(),
    trash: vi.fn(),
    importLab: vi.fn(),
    moveLab: vi.fn(),
    validateLabYaml: vi.fn(),
    applyLabYaml: vi.fn(),
    createLab: vi.fn(),
    deleteLab: vi.fn(),
    duplicateLab: vi.fn(),
    exportLab: vi.fn(),
    restoreLab: vi.fn(),
    purgeLab: vi.fn(),
    stopLab: vi.fn(),
    startLab: vi.fn(),
    wipeLab: vi.fn(),
    job: vi.fn(),
    lab: vi.fn(),
    labNodes: vi.fn(),
    saveLab: vi.fn(),
    snapshots: vi.fn(),
    templates: vi.fn(),
    images: vi.fn(),
    dockerImages: vi.fn(),
    health: vi.fn()
  },
  setCsrf: vi.fn(),
  ApiError: class ApiError extends Error {
    constructor(status, message, details = {}) {
      super(message)
      this.status = status
      this.details = details
    }
  }
}))

import { api, ApiError } from '../src/api'
import LabsView from '../src/views/LabsView.vue'
import EditorView from '../src/views/EditorView.vue'
import YamlSourcePanel from '../src/components/YamlSourcePanel.vue'
import { dismiss, toasts } from '../src/stores/toasts'
import { session } from '../src/stores/session'

const SOURCE = `schema: 1
name: YAML triangle
nodes:
  R1:
    template: iol
  R2:
    template: iol
networks:
  lan-a: {}
links:
  - [R1.e0/0, R2.e0/0]
`

beforeEach(() => {
  vi.resetAllMocks()
  for (const item of [...toasts]) dismiss(item.id)
  api.labs.mockResolvedValue({ data: [] })
  api.trash.mockResolvedValue({ data: [] })
  api.importLab.mockResolvedValue({ status: 'success', data: { id: 'yamllab1', name: 'YAML Lab' }, meta: { warnings: [] } })
  api.moveLab.mockResolvedValue({ data: { id: 'yamllab1' } })
  api.validateLabYaml.mockResolvedValue({
    data: { valid: true, errors: [], warnings: [], counts: { nodes: 0, networks: 0, links: 0 } }
  })
  api.applyLabYaml.mockResolvedValue({ status: 'success', data: { id: 'lab1', revision: 3 }, meta: { warnings: [] } })
  api.lab.mockResolvedValue({
    data: { name: 'Lab', revision: 1, node_list: [], network_list: [] }
  })
  api.labNodes.mockResolvedValue({ data: { nodes: [] } })
  api.templates.mockResolvedValue({ data: [] })
  api.images.mockResolvedValue({ data: [] })
  api.dockerImages.mockResolvedValue({ data: [] })
  api.health.mockResolvedValue({ status: 'ok', demo: { enabled: false, landing: 'login', templates: [] } })
  session.user = null
})

async function mountLabs() {
  const localRouter = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/labs', name: 'labs', component: { template: '<div />' } },
      { path: '/labs/:id', name: 'editor', component: { template: '<div />' } }
    ]
  })
  await localRouter.push('/labs')
  await localRouter.isReady()
  const wrapper = mount(LabsView, {
    global: {
      plugins: [localRouter],
      stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }
    }
  })
  await flushPromises()

  return { wrapper, router: localRouter }
}

async function openYamlMode(wrapper) {
  const button = wrapper.findAll('.create-mode [role="tab"]').find((tab) => tab.text() === 'From YAML')
  await button.trigger('click')
  await flushPromises()
}

function readFile(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(reader.error)
    reader.readAsText(file)
  })
}

describe('LabsView create-from-YAML mode', () => {
  it('validates inline and shows warnings plus counts', async () => {
    api.validateLabYaml.mockResolvedValue({
      data: {
        valid: true,
        errors: [],
        warnings: [{ type: 'image_unmapped', node: 'R1', message: 'image "base.qcow2" is not mapped.' }],
        counts: { nodes: 3, networks: 1, links: 2 }
      }
    })
    const { wrapper } = await mountLabs()
    await wrapper.findAll('button').find((button) => button.text() === 'Create project').trigger('click')
    await openYamlMode(wrapper)

    expect(wrapper.find('input[aria-label="Project name"]').exists()).toBe(false)
    expect(wrapper.find('textarea[aria-label="Lab YAML"]').exists()).toBe(true)
    await wrapper.find('textarea[aria-label="Lab YAML"]').setValue(SOURCE)
    await wrapper.findAll('.create-project button').find((button) => button.text() === 'Validate').trigger('click')
    await flushPromises()

    expect(api.validateLabYaml).toHaveBeenCalledWith({ yaml: SOURCE })
    expect(wrapper.find('.yaml-validation .ok').text()).toBe('Valid YAML')
    expect(wrapper.find('.yaml-warnings').text()).toContain('image_unmapped')
    expect(wrapper.find('.yaml-warnings').text()).toContain('R1: image "base.qcow2" is not mapped.')
    expect(wrapper.find('.yaml-counts').text()).toBe('3 nodes · 1 networks · 2 links')
    wrapper.unmount()
  })

  it('renders compile errors in an alert and disables submit for empty YAML', async () => {
    api.validateLabYaml.mockResolvedValue({
      data: {
        valid: false,
        errors: ['nodes.R1.template: unknown template "missing".'],
        warnings: [],
        counts: { nodes: 1, networks: 0, links: 0 }
      }
    })
    const { wrapper } = await mountLabs()
    await wrapper.findAll('button').find((button) => button.text() === 'Create project').trigger('click')
    await openYamlMode(wrapper)

    const submit = () => wrapper.find('.create-project button[type="submit"]')
    expect(submit().element.disabled).toBe(true)
    expect(wrapper.findAll('.create-project button').find((button) => button.text() === 'Validate').element.disabled).toBe(true)

    await wrapper.find('textarea[aria-label="Lab YAML"]').setValue('schema: 1\n')
    expect(submit().element.disabled).toBe(false)
    await wrapper.findAll('.create-project button').find((button) => button.text() === 'Validate').trigger('click')
    await flushPromises()

    expect(wrapper.find('.yaml-errors').attributes('role')).toBe('alert')
    expect(wrapper.find('.yaml-errors').text()).toContain('unknown template "missing"')
    expect(wrapper.find('.yaml-validation .ok').exists()).toBe(false)
    wrapper.unmount()
  })

  it('imports the text as a virtual lab.yaml and moves it into the folder', async () => {
    api.importLab.mockResolvedValue({
      status: 'success',
      data: { id: 'yamllab1', name: 'Pasted YAML Lab' },
      meta: { warnings: [{ type: 'image_unmapped', node: 'R1', message: 'not mapped' }] }
    })
    api.labs.mockResolvedValue({ data: [{ id: 'yamllab1', name: 'Pasted YAML Lab' }] })
    const { wrapper, router: localRouter } = await mountLabs()
    await wrapper.findAll('button').find((button) => button.text() === 'Create project').trigger('click')
    await openYamlMode(wrapper)

    await wrapper.find('textarea[aria-label="Lab YAML"]').setValue(SOURCE)
    await wrapper.find('input[aria-label="Project folder"]').setValue('yaml-folder')
    await wrapper.find('.create-project form.yaml-form').trigger('submit')
    await flushPromises()

    const file = api.importLab.mock.calls[0][0]
    expect(file).toBeInstanceOf(File)
    expect(file.name).toBe('lab.yaml')
    expect(file.type).toBe('application/yaml')
    expect(await readFile(file)).toBe(SOURCE)
    expect(api.moveLab).toHaveBeenCalledWith('yamllab1', 'yaml-folder')
    expect(wrapper.find('.create-project').exists()).toBe(false)
    expect(toasts.some((toast) => toast.kind === 'success' && /created from YAML with 1 warning/.test(toast.message))).toBe(true)
    expect(localRouter.currentRoute.value.name).toBe('editor')
    expect(localRouter.currentRoute.value.params.id).toBe('yamllab1')

    await wrapper.findAll('button').find((button) => button.text() === 'Create project').trigger('click')
    expect(wrapper.find('#create-mode-blank').attributes('aria-selected')).toBe('true')
    expect(wrapper.find('textarea[aria-label="Lab YAML"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('resets the mode and text when the dialog closes', async () => {
    const { wrapper } = await mountLabs()
    await wrapper.findAll('button').find((button) => button.text() === 'Create project').trigger('click')
    await openYamlMode(wrapper)
    await wrapper.find('textarea[aria-label="Lab YAML"]').setValue(SOURCE)
    await wrapper.findAll('.create-project button').find((button) => button.text() === 'Cancel').trigger('click')
    expect(wrapper.find('.create-project').exists()).toBe(false)

    await wrapper.findAll('button').find((button) => button.text() === 'Create project').trigger('click')
    expect(wrapper.find('#create-mode-blank').attributes('aria-selected')).toBe('true')
    expect(wrapper.find('textarea[aria-label="Lab YAML"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('YamlSourcePanel', () => {
  const baseProps = () => ({
    open: true,
    labId: 'lab1',
    source: SOURCE,
    filename: 'triangle.yaml',
    sha256: 'abcdef0123456789abcdef0123456789',
    markerRevision: 2,
    revision: 2,
    nodeStates: { 1: 'stopped', 2: 'stopped' }
  })

  it('disables Apply for a stale unchanged source while a node runs', async () => {
    const wrapper = mount(YamlSourcePanel, {
      props: {
        ...baseProps(),
        markerRevision: 2,
        revision: 4,
        nodeStates: { 1: 'running', 2: 'stopped' }
      }
    })
    await flushPromises()
    expect(wrapper.find('.yaml-running').text()).toContain('Stop and wipe the lab before applying YAML.')
    expect(wrapper.find('.yaml-stale').exists()).toBe(true)
    expect(wrapper.find('.yaml-apply').element.disabled).toBe(true)
    wrapper.unmount()
  })

  it('re-applies an unchanged stale source to discard canvas changes', async () => {
    const wrapper = mount(YamlSourcePanel, {
      props: { ...baseProps(), markerRevision: 2, revision: 4 }
    })
    await flushPromises()
    expect(wrapper.find('.yaml-stats').text()).toContain('unchanged')
    expect(wrapper.find('.yaml-reapply').text()).toContain('will replace the canvas changes')
    expect(wrapper.find('.yaml-apply').element.disabled).toBe(false)

    await wrapper.find('.yaml-apply').trigger('click')
    await flushPromises()

    expect(api.applyLabYaml).toHaveBeenCalledWith('lab1', {
      yaml: SOURCE,
      filename: 'triangle.yaml',
      revision: 4
    })
    expect(wrapper.emitted('applied')).toHaveLength(1)
    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })

  it('disables Apply while the text is unchanged', async () => {
    const wrapper = mount(YamlSourcePanel, { props: baseProps() })
    await flushPromises()
    expect(wrapper.find('.yaml-stats').text()).toContain('unchanged')
    expect(wrapper.find('.yaml-apply').element.disabled).toBe(true)
    expect(wrapper.find('.yaml-apply').attributes('title')).toBe('The YAML source has not changed.')
    wrapper.unmount()
  })

  it('disables Apply after an invalid validation result', async () => {
    api.validateLabYaml.mockResolvedValue({
      data: { valid: false, errors: ['schema must be 1'], warnings: [], counts: { nodes: 0, networks: 0, links: 0 } }
    })
    const wrapper = mount(YamlSourcePanel, { props: baseProps() })
    await flushPromises()
    await wrapper.find('textarea[aria-label="Lab YAML source"]').setValue('schema: 2\n')
    expect(wrapper.find('.yaml-apply').element.disabled).toBe(false)

    await wrapper.findAll('button').find((button) => button.text() === 'Validate').trigger('click')
    await flushPromises()

    expect(api.validateLabYaml).toHaveBeenCalledWith({ yaml: 'schema: 2\n', filename: 'triangle.yaml' })
    expect(wrapper.find('.yaml-errors').text()).toContain('schema must be 1')
    expect(wrapper.find('.yaml-apply').element.disabled).toBe(true)
    wrapper.unmount()
  })

  it('posts the apply payload and emits applied plus close', async () => {
    const wrapper = mount(YamlSourcePanel, { props: baseProps() })
    await flushPromises()
    const edited = `${SOURCE}# edited\n`
    await wrapper.find('textarea[aria-label="Lab YAML source"]').setValue(edited)
    await wrapper.find('.yaml-apply').trigger('click')
    await flushPromises()

    expect(api.applyLabYaml).toHaveBeenCalledWith('lab1', {
      yaml: edited,
      filename: 'triangle.yaml',
      revision: 2
    })
    expect(wrapper.emitted('applied')).toHaveLength(1)
    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(toasts.some((toast) => toast.kind === 'success' && toast.message === 'YAML applied')).toBe(true)
    wrapper.unmount()
  })

  it('warns when the canvas is newer than the marker and shows the marker metadata', async () => {
    const wrapper = mount(YamlSourcePanel, { props: { ...baseProps(), markerRevision: 2, revision: 5 } })
    await flushPromises()
    expect(wrapper.find('.yaml-stale').text()).toBe(
      'The canvas has changed since this YAML was applied; applying replaces those changes.'
    )
    expect(wrapper.find('.yaml-marker').text()).toContain('triangle.yaml')
    expect(wrapper.find('.yaml-marker').text()).toContain('sha256:abcdef012345')
    expect(wrapper.find('.yaml-marker').text()).toContain('applied at revision 2')
    expect(wrapper.find('.yaml-reapply').exists()).toBe(true)
    expect(wrapper.find('.yaml-apply').element.disabled).toBe(false)
    wrapper.unmount()
  })

  it('keeps the dialog open and shows a 409 inline', async () => {
    const conflict = new ApiError(409, 'Stop and wipe the lab before applying YAML.', { revision: 7 })
    api.applyLabYaml.mockRejectedValue(conflict)
    const wrapper = mount(YamlSourcePanel, { props: baseProps() })
    await flushPromises()
    await wrapper.find('textarea[aria-label="Lab YAML source"]').setValue(`${SOURCE}# edited\n`)
    await wrapper.find('.yaml-apply').trigger('click')
    await flushPromises()

    expect(wrapper.find('.yaml-apply-error').text()).toContain('Stop and wipe the lab before applying YAML.')
    expect(wrapper.emitted('applied')).toBeUndefined()
    expect(wrapper.find('.yaml-panel').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('EditorView YAML entry point', () => {
  async function mountEditor() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/labs/:id', name: 'lab', component: { template: '<div />' } }]
    })
    await router.push('/labs/lab1')
    await router.isReady()
    const wrapper = mount(EditorView, {
      props: { id: 'lab1' },
      global: {
        plugins: [router],
        stubs: {
          RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
          Background: true,
          Controls: true,
          MiniMap: true
        }
      }
    })
    await flushPromises()

    return wrapper
  }

  it('hides the YAML button when the lab has no source marker', async () => {
    const wrapper = await mountEditor()
    expect(wrapper.find('.ed-actions .yaml-source-button').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not request the admin-only docker image list for editors', async () => {
    session.user = { id: 'user1', username: 'editor', role: 'editor' }
    const wrapper = await mountEditor()
    expect(api.dockerImages).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('loads docker image suggestions for admins', async () => {
    session.user = { id: 'user1', username: 'admin', role: 'admin' }
    api.dockerImages.mockResolvedValue({ data: [{ reference: 'frrouting/frr:v8.4.1' }] })
    const wrapper = await mountEditor()
    expect(api.dockerImages).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('passes the health template allowlist to the picker for a guest', async () => {
    session.user = { id: 'guest1', username: 'g-0123456789abcdef', role: 'editor', guest: true }
    api.templates.mockResolvedValue({
      data: [
        { id: 'linux', name: 'Linux', kind: 'qemu' },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs' },
        { id: 'iol', name: 'Cisco IOL', kind: 'iol' }
      ]
    })
    api.health.mockResolvedValue({
      status: 'ok',
      demo: { enabled: true, landing: 'demo', templates: ['linux', 'vpcs'] }
    })

    const wrapper = await mountEditor()
    expect(api.health).toHaveBeenCalledTimes(1)

    await wrapper.find('.add-node').trigger('click')
    await flushPromises()

    const locked = wrapper.find('[data-group="demo-locked"]')
    expect(locked.exists()).toBe(true)
    expect(locked.text()).toContain('Cisco IOL')
    expect(locked.find('button').element.disabled).toBe(true)
    expect(wrapper.find('[data-group="needs-image"]').text()).toContain('Linux')
    expect(wrapper.find('[data-group="needs-image"]').text()).toContain('VPCS')
    wrapper.unmount()
  })

  it('passes no allowlist to the picker for a full account', async () => {
    session.user = { id: 'user1', username: 'editor', role: 'editor' }
    api.templates.mockResolvedValue({
      data: [
        { id: 'linux', name: 'Linux', kind: 'qemu' },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs' }
      ]
    })
    api.health.mockResolvedValue({
      status: 'ok',
      demo: { enabled: true, landing: 'demo', templates: ['linux'] }
    })

    const wrapper = await mountEditor()
    await wrapper.find('.add-node').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-group="demo-locked"]').exists()).toBe(false)
    expect(wrapper.findAll('.template-grid li')).toHaveLength(2)
    wrapper.unmount()
  })

  it('locks the whole catalogue when a guest receives an empty allowlist', async () => {
    session.user = { id: 'guest1', username: 'g-0123456789abcdef', role: 'editor', guest: true }
    api.templates.mockResolvedValue({
      data: [
        { id: 'linux', name: 'Linux', kind: 'qemu' },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs' }
      ]
    })
    api.health.mockResolvedValue({
      status: 'ok',
      demo: { enabled: true, landing: 'demo', templates: [] }
    })

    const wrapper = await mountEditor()
    await wrapper.find('.add-node').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-group="ready"]').exists()).toBe(false)
    expect(wrapper.find('[data-group="needs-image"]').exists()).toBe(false)
    const locked = wrapper.find('[data-group="demo-locked"]')
    expect(locked.exists()).toBe(true)
    expect(locked.text()).toContain('Linux')
    expect(locked.text()).toContain('VPCS')
    const buttons = locked.findAll('button')
    expect(buttons).toHaveLength(2)
    expect(buttons.every((button) => button.element.disabled)).toBe(true)
    wrapper.unmount()
  })

  it('keeps the picker unfiltered when the health allowlist fails', async () => {
    session.user = { id: 'guest1', username: 'g-0123456789abcdef', role: 'editor', guest: true }
    api.templates.mockResolvedValue({
      data: [
        { id: 'linux', name: 'Linux', kind: 'qemu' },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs' }
      ]
    })
    api.health.mockRejectedValue(new Error('health unavailable'))

    const wrapper = await mountEditor()
    await wrapper.find('.add-node').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-group="demo-locked"]').exists()).toBe(false)
    expect(wrapper.findAll('.template-grid li')).toHaveLength(2)
    wrapper.unmount()
  })

  it('shows the YAML button when the lab carries a source marker', async () => {
    api.lab.mockResolvedValue({
      data: {
        name: 'YAML Lab',
        revision: 2,
        node_list: [],
        network_list: [],
        extensions: {
          source: { kind: 'yaml', name: 'triangle.yaml', sha256: 'abcdef012345', revision: 2, text: SOURCE }
        }
      }
    })
    const wrapper = await mountEditor()
    const button = wrapper.find('.ed-actions .yaml-source-button')
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('YAML')

    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('.yaml-panel textarea').element.value).toBe(SOURCE)
    wrapper.unmount()
  })
})
