// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../src/api', () => ({
  api: {
    labs: vi.fn(),
    trash: vi.fn(),
    createLab: vi.fn(),
    moveLab: vi.fn(),
    deleteLab: vi.fn(),
    duplicateLab: vi.fn(),
    importLab: vi.fn(),
    exportLab: vi.fn(),
    restoreLab: vi.fn(),
    purgeLab: vi.fn(),
    stopLab: vi.fn(),
    startLab: vi.fn(),
    wipeLab: vi.fn(),
    job: vi.fn(),
    host: vi.fn(),
    settings: vi.fn(),
    logs: vi.fn(),
    tokens: vi.fn(),
    users: vi.fn(),
    images: vi.fn(),
    templates: vi.fn(),
    template: vi.fn(),
    version: vi.fn(),
    me: vi.fn(),
    logout: vi.fn()
  },
  setCsrf: vi.fn(),
  ApiError: class ApiError extends Error {}
}))

import { api } from '../src/api'
import router from '../src/router'
import { session } from '../src/stores/session'
import AboutView from '../src/views/AboutView.vue'
import AppliancesView from '../src/views/AppliancesView.vue'
import LabsView from '../src/views/LabsView.vue'
import SystemView from '../src/views/SystemView.vue'

beforeEach(() => {
  vi.resetAllMocks()
  session.ready = true
  session.user = { id: 'user1', username: 'admin', role: 'admin' }
  api.labs.mockResolvedValue({ data: [] })
  api.trash.mockResolvedValue({ data: [] })
  api.images.mockResolvedValue({ data: [] })
  api.templates.mockResolvedValue({ data: [{ id: 'linux', name: 'Linux', kind: 'qemu', source: 'builtin' }] })
  api.host.mockResolvedValue({
    data: { load1: 0.1, load5: 0.1, load15: 0.1, cpu_usage_percent: 5, disk_free_bytes: 1, disk_total_bytes: 2, nodes: [], engines: [], interfaces: [] }
  })
  api.settings.mockResolvedValue({ data: { timezone: 'UTC', console_default: 'telnet', session_ttl: 3600, max_upload_bytes: 33554432 } })
  api.logs.mockResolvedValue({ data: { lines: [] } })
  api.tokens.mockResolvedValue({ data: [] })
  api.users.mockResolvedValue({ data: [] })
  api.version.mockResolvedValue({
    product: 'TeraLab',
    version: '1.6-dev',
    license: 'AGPL-3.0-or-later',
    source: 'https://example.test/source',
    components: []
  })
})

function testRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/labs', name: 'labs', component: { template: '<div />' } },
      { path: '/labs/:id', name: 'editor', component: { template: '<div />' } },
      { path: '/appliances', name: 'appliances', component: { template: '<div />' } },
      { path: '/system', name: 'system', component: { template: '<div />' } }
    ]
  })
}

async function mountAt(component, path) {
  const localRouter = testRouter()
  await localRouter.push(path)
  await localRouter.isReady()
  const wrapper = mount(component, { global: { plugins: [localRouter] } })
  await flushPromises()
  return { wrapper, router: localRouter }
}

const clickButton = async (wrapper, label) => {
  const button = wrapper.findAll('button').find((candidate) => candidate.text() === label)
  expect(button, `button "${label}"`).toBeTruthy()
  await button.trigger('click')
  await flushPromises()
}

describe('router redirects', () => {
  it('redirects legacy deep links to the new destinations', async () => {
    await router.push('/templates?from=bookmark')
    expect(router.currentRoute.value.name).toBe('appliances')
    expect(router.currentRoute.value.path).toBe('/appliances')
    expect(router.currentRoute.value.query.tab).toBe('templates')
    expect(router.currentRoute.value.query.from).toBe('bookmark')

    await router.push('/images?from=bookmark')
    expect(router.currentRoute.value.name).toBe('appliances')
    expect(router.currentRoute.value.query.tab).toBe('images')
    expect(router.currentRoute.value.query.from).toBe('bookmark')

    await router.push('/about?from=bookmark')
    expect(router.currentRoute.value.name).toBe('system')
    expect(router.currentRoute.value.path).toBe('/system')
    expect(router.currentRoute.value.query.tab).toBe('About')
    expect(router.currentRoute.value.query.from).toBe('bookmark')
  })

  it('keeps the legacy route names usable', async () => {
    await router.push({ name: 'templates', query: { from: 'nav' } })
    expect(router.currentRoute.value.name).toBe('appliances')
    expect(router.currentRoute.value.query.tab).toBe('templates')
    expect(router.currentRoute.value.query.from).toBe('nav')

    await router.push({ name: 'images', query: { from: 'nav' } })
    expect(router.currentRoute.value.query.tab).toBe('images')
    expect(router.currentRoute.value.query.from).toBe('nav')

    await router.push({ name: 'about', query: { from: 'nav' } })
    expect(router.currentRoute.value.query.tab).toBe('About')
    expect(router.currentRoute.value.query.from).toBe('nav')
  })
})

describe('LabsView create-project dialog', () => {
  const mountLabs = async () => {
    const localRouter = testRouter()
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

  it('creates a project with a folder, closes the dialog, and opens the editor', async () => {
    api.createLab.mockResolvedValue({ data: { id: 'new1', name: 'Dialog Lab' } })
    api.moveLab.mockResolvedValue({ data: { id: 'new1' } })
    const { wrapper, router: localRouter } = await mountLabs()
    expect(wrapper.find('.empty').text()).toContain('Create a project, drop a `.unl` file, or import one.')

    await clickButton(wrapper, 'Create project')
    expect(wrapper.find('.create-project').exists()).toBe(true)

    const inputs = wrapper.find('.create-project').findAll('input')
    await inputs[0].setValue('Dialog Lab')
    await inputs[1].setValue('e2e-folder')
    await wrapper.find('.create-project form').trigger('submit')
    await flushPromises()

    expect(api.createLab).toHaveBeenCalledWith({ name: 'Dialog Lab', networks: [], nodes: [] })
    expect(api.moveLab).toHaveBeenCalledWith('new1', 'e2e-folder')
    expect(wrapper.find('.create-project').exists()).toBe(false)
    expect(localRouter.currentRoute.value.name).toBe('editor')
    expect(localRouter.currentRoute.value.params.id).toBe('new1')

    await localRouter.push('/labs')
    await flushPromises()
    await clickButton(wrapper, 'Create project')
    const reopened = wrapper.find('.create-project').findAll('input')
    expect(reopened[0].element.value).toBe('')
    expect(reopened[1].element.value).toBe('')
    wrapper.unmount()
  })

  it('opens the editor after creating a project from YAML', async () => {
    api.importLab.mockResolvedValue({ data: { id: 'yaml1', name: 'Yaml Lab' }, meta: { warnings: [] } })
    const { wrapper, router: localRouter } = await mountLabs()

    await clickButton(wrapper, 'Create project')
    await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'From YAML').trigger('click')
    await flushPromises()
    await wrapper.find('textarea[aria-label="Lab YAML"]').setValue('name: Yaml Lab\nnodes: {}\n')
    await wrapper.find('.create-project form.yaml-form').trigger('submit')
    await flushPromises()

    expect(api.importLab).toHaveBeenCalled()
    expect(wrapper.find('.create-project').exists()).toBe(false)
    expect(localRouter.currentRoute.value.name).toBe('editor')
    expect(localRouter.currentRoute.value.params.id).toBe('yaml1')
    wrapper.unmount()
  })

  it('reloads the list instead of navigating when the created lab has no id', async () => {
    api.createLab.mockResolvedValue({ data: { name: 'Untitled' } })
    const { wrapper, router: localRouter } = await mountLabs()

    await clickButton(wrapper, 'Create project')
    await wrapper.find('.create-project').findAll('input')[0].setValue('Untitled')
    await wrapper.find('.create-project form').trigger('submit')
    await flushPromises()

    expect(localRouter.currentRoute.value.name).toBe('labs')
    expect(api.labs).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('dismisses the dialog with Cancel and resets the fields', async () => {
    const { wrapper } = await mountLabs()

    await clickButton(wrapper, 'Create project')
    const inputs = wrapper.find('.create-project').findAll('input')
    await inputs[0].setValue('Cancelled Lab')
    await inputs[1].setValue('cancelled-folder')
    await clickButton(wrapper, 'Cancel')

    expect(wrapper.find('.create-project').exists()).toBe(false)

    await clickButton(wrapper, 'Create project')
    const reopened = wrapper.find('.create-project').findAll('input')
    expect(reopened[0].element.value).toBe('')
    expect(reopened[1].element.value).toBe('')
    wrapper.unmount()
  })

  it('closes the dialog on Escape', async () => {
    const { wrapper } = await mountLabs()

    await clickButton(wrapper, 'Create project')
    expect(wrapper.find('.create-project').exists()).toBe(true)

    await wrapper.find('.modal-backdrop').trigger('keydown', { key: 'Escape' })
    await flushPromises()

    expect(wrapper.find('.create-project').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps a create error inside the dialog', async () => {
    api.createLab.mockRejectedValue(new Error('name already exists'))
    const { wrapper } = await mountLabs()

    await clickButton(wrapper, 'Create project')
    await wrapper.find('.create-project').findAll('input')[0].setValue('Duplicate')
    await wrapper.find('.create-project form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.create-project').exists()).toBe(true)
    expect(wrapper.find('.create-project .error[role="alert"]').text()).toBe('name already exists')
    expect(api.moveLab).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

describe('SystemView About tab', () => {
  it('initializes the active tab from the query and updates the query on click', async () => {
    const { wrapper, router: localRouter } = await mountAt(SystemView, '/system?tab=About')

    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toBe('About')
    expect(wrapper.find('.about-view').exists()).toBe(true)
    expect(wrapper.find('.about-view .page-head').exists()).toBe(false)
    expect(api.version).toHaveBeenCalled()

    await clickButton(wrapper, 'Host')
    expect(localRouter.currentRoute.value.query.tab).toBe('Host')
    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toBe('Host')
    wrapper.unmount()
  })

  it('falls back to Host for unknown or hidden tabs', async () => {
    session.user = { id: 'user3', username: 'viewer', role: 'user' }
    const { wrapper } = await mountAt(SystemView, '/system?tab=nope')
    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toBe('Host')
    wrapper.unmount()

    const second = await mountAt(SystemView, '/system?tab=Users')
    expect(second.wrapper.find('[role="tab"][aria-selected="true"]').text()).toBe('Host')
    second.wrapper.unmount()
  })
})

describe('AboutView embedded mode', () => {
  it('hides only its own page head when embedded', async () => {
    const embedded = mount(AboutView, { props: { embedded: true } })
    await flushPromises()
    expect(embedded.find('.about-view').classes()).toContain('embedded')
    expect(embedded.find('.page-head').exists()).toBe(false)
    expect(embedded.find('.about-grid').exists()).toBe(true)
    embedded.unmount()

    const standalone = mount(AboutView)
    await flushPromises()
    expect(standalone.find('.page-head').exists()).toBe(true)
    expect(standalone.find('.page-head h1').text()).toBe('About')
    standalone.unmount()
  })
})

describe('AppliancesView tabs', () => {
  it('renders the tab selected by the query and syncs clicks back to it', async () => {
    const { wrapper, router: localRouter } = await mountAt(AppliancesView, '/appliances?tab=images')

    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toBe('Images')
    expect(wrapper.find('.images-view').exists()).toBe(true)
    expect(wrapper.find('.images-view h1').exists()).toBe(false)
    expect(wrapper.find('.templates').exists()).toBe(false)

    await clickButton(wrapper, 'Templates')
    expect(localRouter.currentRoute.value.query.tab).toBe('templates')
    expect(wrapper.find('.templates').exists()).toBe(true)
    expect(wrapper.find('.templates .page-head').exists()).toBe(false)
    expect(wrapper.find('.images-view').exists()).toBe(false)

    await clickButton(wrapper, 'Images')
    expect(localRouter.currentRoute.value.query.tab).toBe('images')
    expect(wrapper.find('.images-view').exists()).toBe(true)
    wrapper.unmount()
  })

  it('defaults to Templates without a tab query', async () => {
    const { wrapper } = await mountAt(AppliancesView, '/appliances')
    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toBe('Templates')
    expect(wrapper.find('.templates').exists()).toBe(true)
    wrapper.unmount()
  })
})
