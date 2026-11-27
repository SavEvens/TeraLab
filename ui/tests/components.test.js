// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AddLinkDialog from '../src/components/AddLinkDialog.vue'
import Inspector from '../src/components/Inspector.vue'
import TemplatePicker from '../src/components/TemplatePicker.vue'
import ToastHost from '../src/components/ToastHost.vue'
import ImagesView from '../src/views/ImagesView.vue'
import SystemView from '../src/views/SystemView.vue'
import PasswordChangeDialog from '../src/components/PasswordChangeDialog.vue'
import { api, uploadImageWithProgress } from '../src/api'
import { session } from '../src/stores/session'
import { notify, toasts, dismiss } from '../src/stores/toasts'

vi.mock('../src/api', () => ({
  api: {
    images: vi.fn(),
    templates: vi.fn(),
    importLegacyImages: vi.fn(),
    deleteImage: vi.fn(),
    renameImage: vi.fn(),
    setImageReference: vi.fn(),
    mapImageTemplate: vi.fn(),
    host: vi.fn(),
    settings: vi.fn(),
    saveSettings: vi.fn(),
    demoStatus: vi.fn(),
    demoSweep: vi.fn(),
    logs: vi.fn(),
    tokens: vi.fn(),
    createToken: vi.fn(),
    revokeToken: vi.fn(),
    users: vi.fn(),
    createUser: vi.fn(),
    updateUser: vi.fn(),
    resetUserPassword: vi.fn(),
    revokeUserSessions: vi.fn(),
    deleteUser: vi.fn(),
    changePassword: vi.fn(),
    version: vi.fn()
  },
  uploadImageWithProgress: vi.fn(),
  setCsrf: vi.fn(),
  ApiError: class ApiError extends Error {}
}))

beforeEach(() => {
  vi.resetAllMocks()
  api.images.mockResolvedValue({ data: [] })
  api.templates.mockResolvedValue({ data: [{ id: 'csr1000v', name: 'CSR1000V', kind: 'qemu' }] })
  session.user = null
})

const labNode = (id, name, kind, ports, networks = []) => ({
  id: String(id),
  type: 'lab',
  position: { x: 0, y: 0 },
  data: {
    id: String(id),
    name,
    kind,
    interfaces: Array.from({ length: ports }, (_, index) => ({
      id: index,
      name: kind === 'iol' ? `Ethernet0/${index}` : `eth${index}`,
      type: 'ethernet',
      network_id: networks[index] || ''
    }))
  }
})

describe('TemplatePicker', () => {
  const templates = [
    { id: 'linux', name: 'Linux', kind: 'qemu' },
    { id: 'iol', name: 'Cisco IOL', kind: 'iol' },
    { id: 'vpcs', name: 'VPCS', kind: 'vpcs' }
  ]
  const images = [{ id: 'img1', name: 'Base image', reference: 'base.qcow2', template: 'linux' }]

  it('groups templates by image mapping and emits add', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })
    const ready = wrapper.find('[data-group="ready"]')
    const needsImage = wrapper.find('[data-group="needs-image"]')
    expect(ready.text()).toContain('Linux')
    expect(ready.text()).toContain('Base image')
    expect(ready.text()).not.toContain('base.qcow2')
    expect(ready.text()).not.toContain('VPCS')
    expect(needsImage.text()).toContain('VPCS')
    expect(needsImage.text()).toContain('Cisco IOL')

    const vpcs = wrapper.findAll('[data-group="needs-image"] li').find((item) => item.text().includes('VPCS'))
    await vpcs.find('button').trigger('click')
    expect(wrapper.emitted('add')[0][0].template.id).toBe('vpcs')
    expect(wrapper.emitted('add')[0][0].count).toBe(1)
    expect(wrapper.emitted('add')[0][0].image).toBe('')
  })

  it('carries the mapped image reference in the add payload', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })
    const linux = wrapper.findAll('[data-group="ready"] li').find((item) => item.text().includes('Linux'))
    await linux.find('button').trigger('click')
    expect(wrapper.emitted('add')[0][0]).toMatchObject({ image: 'base.qcow2', count: 1 })
  })

  it('assigns the first mapped image by name when several are mapped', async () => {
    const mapped = [
      { id: 'img2', name: 'Zulu image', reference: 'zeta.qcow2', template: 'linux' },
      { id: 'img1', name: 'Alpha image', reference: 'alpha.qcow2', template: 'linux' }
    ]
    const wrapper = mount(TemplatePicker, { props: { templates, images: mapped } })
    const ready = wrapper.find('[data-group="ready"]')
    expect(ready.text()).toContain('Zulu image')
    expect(ready.text()).toContain('Alpha image')
    const linux = wrapper.findAll('[data-group="ready"] li').find((item) => item.text().includes('Linux'))
    await linux.find('button').trigger('click')
    expect(wrapper.emitted('add')[0][0].image).toBe('alpha.qcow2')
    expect(wrapper.emitted('add')[0][0].image).not.toBe('zeta.qcow2')
  })

  it('clamps the count to 1-20 and forwards it', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })
    const count = wrapper.find('input[type="number"]')
    const linux = () => wrapper.findAll('[data-group="ready"] li').find((item) => item.text().includes('Linux'))

    await count.setValue('25')
    await linux().find('button').trigger('click')
    expect(wrapper.emitted('add').at(-1)[0].template.id).toBe('linux')
    expect(wrapper.emitted('add').at(-1)[0].count).toBe(20)
    expect(wrapper.emitted('add').at(-1)[0].image).toBe('base.qcow2')

    await count.setValue('0')
    await linux().find('button').trigger('click')
    expect(wrapper.emitted('add').at(-1)[0].count).toBe(1)
  })

  it('treats docker templates as ready and carries the container image', async () => {
    const dockerTemplates = [
      { id: 'frr', name: 'FRR Router', kind: 'docker', options: { docker: { image: 'frrouting/frr:v8.4.1' } } },
      { id: 'busybox', name: 'Busybox docker', kind: 'docker' }
    ]
    const wrapper = mount(TemplatePicker, { props: { templates: [...templates, ...dockerTemplates], images } })
    const ready = wrapper.find('[data-group="ready"]')
    const needsImage = wrapper.find('[data-group="needs-image"]')

    expect(ready.text()).toContain('FRR Router')
    expect(ready.text()).toContain('docker · frrouting/frr:v8.4.1')
    expect(ready.text()).toContain('docker · no default image')
    expect(ready.text()).toContain('container image')
    expect(needsImage.text()).not.toContain('FRR Router')
    expect(needsImage.text()).not.toContain('Busybox')

    const add = async (name) => {
      const tile = wrapper.findAll('[data-group="ready"] li').find((item) => item.text().includes(name))
      await tile.find('button').trigger('click')
      return wrapper.emitted('add').at(-1)[0]
    }
    expect(await add('FRR Router')).toMatchObject({ image: 'frrouting/frr:v8.4.1', count: 1 })
    expect((await add('Busybox')).image).toBe('')
  })

  it('filters across both groups', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })
    await wrapper.find('input').setValue('vpcs')
    expect(wrapper.findAll('.template-grid li')).toHaveLength(1)
    expect(wrapper.find('[data-group="needs-image"]').text()).toContain('VPCS')
    expect(wrapper.find('[data-group="ready"]').exists()).toBe(false)
  })
})

describe('AddLinkDialog', () => {
  const nodes = [
    labNode(1, 'R1', 'iol', 4, ['0']),
    labNode(2, 'R2', 'qemu', 2)
  ]
  const networks = [{ id: '0', name: 'Net-1' }]

  it('lists compact interface labels and emits the chosen pair', async () => {
    const wrapper = mount(AddLinkDialog, { props: { nodes, networks } })
    const source = wrapper.find('select[aria-label="Interface A"]')
    expect(source.findAll('option')[0].text()).toBe('e0/0 · Net-1 (0)')
    expect(source.findAll('option')[1].text()).toBe('e0/1 · free')

    await wrapper.find('select[aria-label="Node B"]').setValue('2')
    const target = wrapper.find('select[aria-label="Interface B"]')
    expect(target.findAll('option')[0].text()).toBe('eth0 · free')
    await target.setValue('1')
    await wrapper.findAll('button').find((button) => button.text() === 'Create link').trigger('click')
    expect(wrapper.emitted('create')[0][0]).toEqual({ sourceId: '1', sourceIndex: 1, targetId: '2', targetIndex: 1 })
  })

  it('keeps the two ends on different nodes', async () => {
    const wrapper = mount(AddLinkDialog, { props: { nodes, networks } })
    await wrapper.find('select[aria-label="Node B"]').setValue('1')
    expect(wrapper.find('select[aria-label="Node A"]').element.value).toBe('2')
    expect(wrapper.find('select[aria-label="Node B"]').element.value).toBe('1')
    expect(wrapper.text()).not.toContain('Pick two different nodes.')
  })

  it('swaps both ends including interfaces', async () => {
    const wrapper = mount(AddLinkDialog, { props: { nodes, networks } })
    await wrapper.find('select[aria-label="Interface A"]').setValue('2')
    await wrapper.find('select[aria-label="Interface B"]').setValue('1')
    await wrapper.find('button[aria-label="Swap ends"]').trigger('click')
    expect(wrapper.find('select[aria-label="Node A"]').element.value).toBe('2')
    expect(wrapper.find('select[aria-label="Interface A"]').element.value).toBe('1')
    expect(wrapper.find('select[aria-label="Node B"]').element.value).toBe('1')
    expect(wrapper.find('select[aria-label="Interface B"]').element.value).toBe('2')
  })
})

describe('Inspector', () => {
  it('uses portable image references', async () => {
    const node = { id: '1', position: { x: 0, y: 0 }, data: { name: 'R1', template: 'linux', kind: 'qemu', image: 'base.qcow2', console: 'telnet', cpu: 1, ram: 512, serial: 0, interfaces: [{ id: 0 }] } }
    const wrapper = mount(Inspector, {
      props: {
        node,
        templates: [],
        images: [{ id: 'img1', kind: 'qemu', name: 'Base', reference: 'base.qcow2', format: 'qcow2', path: '/var/lib/teralab/images/qemu/base.qcow2' }]
      }
    })
    const select = wrapper.findAll('select').find((candidate) => candidate.findAll('option').some((option) => option.text().includes('Base')))
    expect(select.findAll('option').map((option) => option.element.value)).toContain('base.qcow2')
    expect(wrapper.text()).not.toContain('unmapped')
  })

  it('lists only the template-mapped images and keeps a stale value visible', () => {
    const node = {
      id: '1',
      position: { x: 0, y: 0 },
      data: { name: 'R1', template: 'linux', kind: 'qemu', image: 'old.qcow2', console: 'telnet', cpu: 1, ram: 512, serial: 0, interfaces: [{ id: 0 }] }
    }
    const images = [
      { id: 'img1', kind: 'qemu', name: 'Base', reference: 'base.qcow2', format: 'qcow2', template: 'linux' },
      { id: 'img2', kind: 'qemu', name: 'Other', reference: 'other.qcow2', format: 'qcow2', template: 'vpcs' },
      { id: 'img3', kind: 'iol', name: 'IOL', reference: 'iol.bin', format: 'bin', template: 'iol' }
    ]
    const wrapper = mount(Inspector, { props: { node, templates: [], images } })
    const imageSelect = wrapper.findAll('label').find((label) => label.text().includes('Image')).find('select')
    expect(imageSelect.findAll('option').map((option) => option.element.value)).toEqual(['', 'old.qcow2', 'base.qcow2'])
    expect(imageSelect.text()).toContain('⚠ old.qcow2 (not mapped to this template)')
    expect(imageSelect.text()).not.toContain('Other')
    expect(imageSelect.text()).not.toContain('IOL')
  })

  it('falls back to all images of the kind when the template has no mapping', () => {
    const node = {
      id: '1',
      position: { x: 0, y: 0 },
      data: { name: 'R1', template: 'unmapped', kind: 'qemu', image: '', console: 'telnet', cpu: 1, ram: 512, serial: 0, interfaces: [{ id: 0 }] }
    }
    const images = [
      { id: 'img1', kind: 'qemu', name: 'Base', reference: 'base.qcow2', format: 'qcow2', template: 'linux' },
      { id: 'img2', kind: 'qemu', name: 'Other', reference: 'other.qcow2', format: 'qcow2', template: 'vpcs' },
      { id: 'img3', kind: 'iol', name: 'IOL', reference: 'iol.bin', format: 'bin', template: 'iol' }
    ]
    const wrapper = mount(Inspector, { props: { node, templates: [], images } })
    const imageSelect = wrapper.findAll('label').find((label) => label.text().includes('Image')).find('select')
    expect(imageSelect.findAll('option').map((option) => option.element.value)).toEqual(['', 'base.qcow2', 'other.qcow2'])
  })

  it('marks an unmapped image and emits nvram', async () => {
    const node = { id: '1', position: { x: 0, y: 0 }, data: { name: 'R1', template: 'iol', kind: 'iol', image: 'missing.bin', console: 'telnet', cpu: 1, ram: 512, serial: 0, nvram: 0, interfaces: [{ id: 0 }] } }
    const wrapper = mount(Inspector, { props: { node, templates: [], images: [] } })
    expect(wrapper.text()).toContain('missing.bin (not mapped to this template)')
    const nvram = wrapper.findAll('label').find((label) => label.text().includes('NVRAM')).find('input')
    await nvram.setValue('1024')
    expect(wrapper.emitted('update').some((entry) => entry[0].key === 'nvram' && entry[0].value === 1024)).toBe(true)
  })

  it('offers the agent NIC drivers and maps legacy aliases', () => {
    const node = {
      id: '1',
      position: { x: 0, y: 0 },
      data: {
        name: 'R1', template: 'linux', kind: 'qemu', image: '', console: 'telnet',
        cpu: 1, ram: 512, serial: 0, nic: 'virtio-net-pci', interfaces: [{ id: 0 }]
      }
    }
    const wrapper = mount(Inspector, { props: { node, templates: [], images: [] } })
    const nicSelect = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'vmxnet3'))
    expect(nicSelect.findAll('option').map((option) => option.element.value)).toEqual(['virtio', 'vmxnet3', 'e1000', 'rtl8139'])
    expect(nicSelect.element.value).toBe('virtio')
    expect(node.data.nic).toBe('virtio-net-pci')

    const legacy = {
      id: '2',
      position: { x: 0, y: 0 },
      data: {
        name: 'R2', template: 'linux', kind: 'qemu', image: '', console: 'telnet',
        cpu: 1, ram: 512, serial: 0, nic: 'i82559er', interfaces: [{ id: 0 }]
      }
    }
    const legacyWrapper = mount(Inspector, { props: { node: legacy, templates: [], images: [] } })
    const legacySelect = legacyWrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'vmxnet3'))
    expect(legacySelect.element.value).toBe('e1000')
    expect(legacy.data.nic).toBe('i82559er')

    const custom = { ...legacy, data: { ...legacy.data, nic: 'ne2k_pci' } }
    const customWrapper = mount(Inspector, { props: { node: custom, templates: [], images: [] } })
    const customSelect = customWrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'vmxnet3'))
    expect(customSelect.element.value).toBe('')
    expect(custom.data.nic).toBe('ne2k_pci')
  })

  it('edits a docker container reference with pulled-image suggestions', async () => {
    const templates = [{ id: 'frr', name: 'FRR Router', kind: 'docker', options: { docker: { image: 'frrouting/frr:v8.4.1' } } }]
    const node = {
      id: '7',
      position: { x: 0, y: 0 },
      data: {
        name: 'FRR7', template: 'frr', kind: 'docker', image: '', iso: '', console: 'telnet',
        cpu: 1, ram: 256, serial: 0, interfaces: [{ id: 0 }]
      }
    }
    const dockerImages = [
      { reference: 'frrouting/frr:v8.4.1', id: 'sha256:a', size: '1MB', created: '' },
      { reference: 'alpine:3.20', id: 'sha256:b', size: '7.81MB', created: '' }
    ]
    const wrapper = mount(Inspector, { props: { node, templates, images: [], dockerImages } })

    const input = wrapper.find('input[list="docker-image-options"]')
    expect(input.exists()).toBe(true)
    expect(input.element.value).toBe('')
    expect(input.attributes('placeholder')).toBe('frrouting/frr:v8.4.1')
    expect(wrapper.findAll('datalist#docker-image-options option').map((option) => option.element.value))
      .toEqual(['frrouting/frr:v8.4.1', 'alpine:3.20'])
    expect(wrapper.text()).toContain('Empty uses the template default (frrouting/frr:v8.4.1)')
    expect(wrapper.text()).toContain('Pulled images are offered as you type')

    await input.setValue('alpine:3.20')
    expect(wrapper.emitted('update')).toContainEqual([{ key: 'image', value: 'alpine:3.20' }])

    const typeSelect = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'docker'))
    expect(typeSelect.element.value).toBe('docker')
    const consoleSelect = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'telnet'))
    expect(consoleSelect.findAll('option').map((option) => option.element.value)).toEqual(['telnet'])

    const labels = wrapper.findAll('label').map((label) => label.text())
    expect(labels.some((text) => text.includes('NIC driver'))).toBe(false)
    expect(labels.some((text) => text.includes('Serial ports'))).toBe(false)
    expect(labels.some((text) => text.includes('ISO'))).toBe(false)
    expect(labels.some((text) => text.includes('Startup config'))).toBe(false)
  })

  it('hides pulled-image suggestions when the caller may not list them', () => {
    const templates = [{ id: 'frr', name: 'FRR Router', kind: 'docker', options: { docker: { image: 'frrouting/frr:v8.4.1' } } }]
    const node = {
      id: '7',
      position: { x: 0, y: 0 },
      data: {
        name: 'FRR7', template: 'frr', kind: 'docker', image: '', iso: '', console: 'telnet',
        cpu: 1, ram: 256, serial: 0, interfaces: [{ id: 0 }]
      }
    }
    const wrapper = mount(Inspector, {
      props: {
        node,
        templates,
        images: [],
        dockerImages: [{ reference: 'frrouting/frr:v8.4.1' }],
        dockerImageSuggestions: false
      }
    })

    const input = wrapper.find('input[list="docker-image-options"]')
    expect(input.exists()).toBe(true)
    expect(input.attributes('placeholder')).toBe('frrouting/frr:v8.4.1')
    expect(wrapper.find('datalist#docker-image-options').exists()).toBe(false)
    expect(wrapper.text()).toContain('Empty uses the template default (frrouting/frr:v8.4.1)')
    expect(wrapper.text()).not.toContain('Pulled images are offered')
  })

  it('falls back to a generic container placeholder without a template image', () => {
    const node = {
      id: '8',
      position: { x: 0, y: 0 },
      data: { name: 'C8', template: 'custom', kind: 'docker', image: '', console: 'telnet', cpu: 1, ram: 128, serial: 0, interfaces: [{ id: 0 }] }
    }
    const wrapper = mount(Inspector, { props: { node, templates: [{ id: 'custom', name: 'Custom', kind: 'docker' }], images: [] } })

    expect(wrapper.find('input[list="docker-image-options"]').attributes('placeholder')).toBe('e.g. frrouting/frr:v8.4.1')
    expect(wrapper.text()).toContain('No container image set — enter a container reference. Pulled images are offered as you type.')
    expect(wrapper.text()).not.toContain('Empty uses the template default')
    expect(wrapper.findAll('datalist#docker-image-options option')).toHaveLength(0)
  })

  it('keeps a stored vnc console visible on a docker node', async () => {
    const node = {
      id: '9',
      position: { x: 0, y: 0 },
      data: { name: 'D9', template: 'frr', kind: 'docker', image: '', console: 'vnc', cpu: 1, ram: 128, serial: 0, interfaces: [{ id: 0 }] }
    }
    const templates = [{ id: 'frr', name: 'FRR Router', kind: 'docker', options: { docker: { image: 'frrouting/frr:v8.4.1' } } }]
    const wrapper = mount(Inspector, { props: { node, templates, images: [] } })

    const consoleSelect = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'telnet'))
    expect(consoleSelect.findAll('option').map((option) => option.element.value)).toEqual(['telnet', 'vnc'])
    expect(consoleSelect.element.value).toBe('vnc')
    expect(consoleSelect.text()).toContain('vnc (docker uses telnet)')
    expect(node.data.console).toBe('vnc')

    await consoleSelect.setValue('telnet')
    expect(wrapper.emitted('update')).toContainEqual([{ key: 'console', value: 'telnet' }])
  })

  it('emits updates and actions', async () => {
    const node = { id: '1', position: { x: 0, y: 0 }, data: { name: 'R1', template: 'linux', kind: 'qemu', image: '', console: 'telnet', cpu: 1, ram: 512, serial: 0, interfaces: [{ id: 0 }] } }
    const wrapper = mount(Inspector, {
      props: { node, templates: [{ id: 'linux', name: 'Linux' }, { id: 'vpcs', name: 'VPCS' }] }
    })
    await wrapper.findAll('input')[0].setValue('R2')
    expect(wrapper.emitted('update')[0][0]).toEqual({ key: 'name', value: 'R2' })
    const nic = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'e1000'))
    await nic.setValue('virtio')
    expect(wrapper.emitted('update').some((entry) => entry[0].key === 'nic' && entry[0].value === 'virtio')).toBe(true)
    const template = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'vpcs'))
    await template.setValue('vpcs')
    expect(wrapper.emitted('template')[0][0]).toBe('vpcs')
    const consoleButton = wrapper.findAll('button').find((button) => button.text() === 'Console')
    const deleteButton = wrapper.findAll('button').find((button) => button.text() === 'Delete')
    await consoleButton.trigger('click')
    expect(wrapper.emitted('console')).toBeTruthy()
    await deleteButton.trigger('click')
    expect(wrapper.emitted('delete')).toBeTruthy()
  })
})

describe('ToastHost', () => {
  it('renders and dismisses notifications', async () => {
    for (const item of [...toasts]) dismiss(item.id)
    const id = notify('success', 'Saved', 0)
    const wrapper = mount(ToastHost)
    expect(wrapper.text()).toContain('Saved')
    await wrapper.find('button').trigger('click')
    expect(toasts.find((item) => item.id === id)).toBeUndefined()
  })
})

describe('ImagesView', () => {
  it('shows the adopt action only to admins', async () => {
    const wrapper = mount(ImagesView)
    await flushPromises()
    expect(wrapper.text()).not.toContain('Adopt images')

    session.user = { username: 'admin', role: 'admin' }
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('Adopt images')
    wrapper.unmount()
  })

  it('marks linked images and labels their delete action Unlink', async () => {
    session.user = { username: 'admin', role: 'admin' }
    api.images.mockResolvedValue({
      data: [
        { id: '1', name: 'hda.qcow2', kind: 'qemu', path: '/var/lib/teralab/images/qemu/a/hda.qcow2', external: 1, created_at: 0 },
        { id: '2', name: 'base.qcow2', kind: 'qemu', path: '/var/lib/teralab/images/qemu/b/base.qcow2', external: 0, created_at: 0 }
      ]
    })
    const wrapper = mount(ImagesView)
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    const linked = rows[0]
    expect(linked.find('.badge.linked').exists()).toBe(true)
    expect(linked.find('.badge.linked').attributes('title')).toBe('linked from the legacy addons tree; no copy in the store')
    expect(linked.findAll('button').some((button) => button.text() === 'Unlink')).toBe(true)
    expect(linked.findAll('button').some((button) => button.text() === 'Delete')).toBe(false)

    const internal = rows[1]
    expect(internal.find('.badge.linked').exists()).toBe(false)
    expect(internal.findAll('button').some((button) => button.text() === 'Delete')).toBe(true)
    wrapper.unmount()
  })

  it('scans legacy addons and imports the selected new rows', async () => {
    session.user = { username: 'admin', role: 'admin' }
    api.importLegacyImages
      .mockResolvedValueOnce({
        data: {
          root: '/opt/unetlab/addons',
          dry_run: true,
          entries: [
            { path: 'qemu/csr1000vng/hda.qcow2', kind: 'qemu', size: 2048, reference: 'csr1000vng', template: 'csr1000v', template_suggestion: 'csr1000v', status: 'new', warnings: [], error: null },
            { path: 'qemu/legacy/hda.qcow2', kind: 'qemu', size: 1024, reference: 'legacy', template: null, template_suggestion: '', status: 'existing', warnings: [], error: null }
          ],
          warnings: [],
          summary: { new: 1, existing: 1, duplicate: 0, unreadable: 0, skipped: 0 }
        }
      })
      .mockResolvedValueOnce({
        data: {
          root: '/opt/unetlab/addons',
          dry_run: false,
          results: [{ path: 'qemu/csr1000vng/hda.qcow2', status: 'imported', image: { id: 'img1' }, error: null }],
          summary: { imported: 1, duplicate: 0, skipped: 0, failed: 0 }
        }
      })

    const wrapper = mount(ImagesView)
    await flushPromises()
    await wrapper.findAll('button').find((button) => button.text().startsWith('Adopt images')).trigger('click')
    await wrapper.findAll('button').find((button) => button.text() === 'Scan').trigger('click')
    await flushPromises()

    expect(api.importLegacyImages).toHaveBeenCalledWith({ root: '/opt/unetlab/addons', dry_run: true })
    expect(wrapper.findAll('.adopt-table tbody tr')).toHaveLength(2)
    expect(wrapper.find('input[aria-label="Select all new images"]').element.checked).toBe(true)
    expect(wrapper.find('input[aria-label="Include qemu/legacy/hda.qcow2"]').element.disabled).toBe(true)
    expect(wrapper.find('select[aria-label="Template for qemu/csr1000vng/hda.qcow2"]').element.value).toBe('csr1000v')

    await wrapper.findAll('button').find((button) => button.text() === 'Import 1').trigger('click')
    await flushPromises()

    expect(api.importLegacyImages).toHaveBeenLastCalledWith({
      root: '/opt/unetlab/addons',
      dry_run: false,
      files: ['qemu/csr1000vng/hda.qcow2'],
      templates: { 'qemu/csr1000vng/hda.qcow2': 'csr1000v' }
    })
    expect(wrapper.text()).toContain('1 imported · 0 duplicate · 0 skipped · 0 failed')
    expect(wrapper.find('.adopt-results .badge.status-imported').exists()).toBe(true)
    wrapper.unmount()
  })

  it('renames an image from one dialog with name and reference', async () => {
    session.user = { username: 'editor', role: 'editor' }
    api.images.mockResolvedValue({
      data: [{ id: 'img1', name: 'base', reference: 'base.qcow2', kind: 'qemu', path: '/var/lib/teralab/images/qemu/base.qcow2', created_at: 0 }]
    })
    api.renameImage.mockResolvedValue({ data: { id: 'img1' } })
    api.setImageReference.mockResolvedValue({ data: { id: 'img1' } })
    const wrapper = mount(ImagesView)
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Rename').trigger('click')
    expect(wrapper.find('.rename-modal').exists()).toBe(true)
    expect(wrapper.find('input[aria-label="Display name"]').element.value).toBe('base')
    expect(wrapper.find('input[aria-label="Reference"]').element.value).toBe('base.qcow2')

    await wrapper.find('input[aria-label="Display name"]').setValue('Base image')
    await wrapper.find('input[aria-label="Reference"]').setValue('base-v2.qcow2')
    await wrapper.find('.rename-modal form').trigger('submit')
    await flushPromises()

    expect(api.renameImage).toHaveBeenCalledTimes(1)
    expect(api.renameImage).toHaveBeenCalledWith('img1', 'Base image')
    expect(api.setImageReference).toHaveBeenCalledTimes(1)
    expect(api.setImageReference).toHaveBeenCalledWith('img1', 'base-v2.qcow2')
    expect(wrapper.find('.rename-modal').exists()).toBe(false)
    expect(api.images).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('only sends the fields that changed', async () => {
    session.user = { username: 'editor', role: 'editor' }
    api.images.mockResolvedValue({
      data: [{ id: 'img1', name: 'base', reference: 'base.qcow2', kind: 'qemu', created_at: 0 }]
    })
    api.setImageReference.mockResolvedValue({ data: { id: 'img1' } })
    const wrapper = mount(ImagesView)
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Rename').trigger('click')
    await wrapper.find('input[aria-label="Reference"]').setValue('base-v2.qcow2')
    await wrapper.find('.rename-modal form').trigger('submit')
    await flushPromises()

    expect(api.renameImage).not.toHaveBeenCalled()
    expect(api.setImageReference).toHaveBeenCalledWith('img1', 'base-v2.qcow2')
    wrapper.unmount()
  })

  it('closes without calling the API when nothing changed', async () => {
    session.user = { username: 'editor', role: 'editor' }
    api.images.mockResolvedValue({
      data: [{ id: 'img1', name: 'base', reference: 'base.qcow2', kind: 'qemu', created_at: 0 }]
    })
    const wrapper = mount(ImagesView)
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Rename').trigger('click')
    await wrapper.find('.rename-modal form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.rename-modal').exists()).toBe(false)
    expect(api.renameImage).not.toHaveBeenCalled()
    expect(api.setImageReference).not.toHaveBeenCalled()
    expect(api.images).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('validates both rename fields inline', async () => {
    session.user = { username: 'editor', role: 'editor' }
    api.images.mockResolvedValue({
      data: [{ id: 'img1', name: 'base', reference: 'base.qcow2', kind: 'qemu', created_at: 0 }]
    })
    const wrapper = mount(ImagesView)
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Rename').trigger('click')
    const display = wrapper.find('input[aria-label="Display name"]')
    const reference = wrapper.find('input[aria-label="Reference"]')
    await display.setValue('   ')
    expect(wrapper.text()).toContain('Display name is required.')
    expect(wrapper.find('.rename-modal button[type="submit"]').element.disabled).toBe(true)

    await display.setValue('é'.repeat(65))
    expect(wrapper.text()).toContain('Display name must be 128 characters or fewer.')
    expect(wrapper.find('.rename-modal button[type="submit"]').element.disabled).toBe(true)

    await display.setValue('Base')
    await reference.setValue('paths/are/not/portable')
    expect(wrapper.text()).toContain('Reference must be a single name, without slashes.')
    expect(wrapper.find('.rename-modal button[type="submit"]').element.disabled).toBe(true)

    await reference.setValue('two words')
    expect(wrapper.text()).toContain('Reference cannot contain spaces.')
    expect(wrapper.find('.rename-modal button[type="submit"]').element.disabled).toBe(true)

    await reference.setValue('é'.repeat(65))
    expect(wrapper.text()).toContain('Reference must be 128 characters or fewer.')
    expect(wrapper.find('.rename-modal button[type="submit"]').element.disabled).toBe(true)

    await reference.setValue('base-v2.qcow2')
    expect(wrapper.find('.rename-modal button[type="submit"]').element.disabled).toBe(false)
    wrapper.unmount()
  })

  it('keeps the dialog open and refreshes when the reference fails after the name', async () => {
    session.user = { username: 'editor', role: 'editor' }
    api.images.mockResolvedValue({
      data: [{ id: 'img1', name: 'base', reference: 'base.qcow2', kind: 'qemu', created_at: 0 }]
    })
    api.renameImage.mockResolvedValue({ data: { id: 'img1' } })
    api.setImageReference.mockRejectedValue(new Error('Another image already uses that reference.'))
    const wrapper = mount(ImagesView)
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Rename').trigger('click')
    await wrapper.find('input[aria-label="Display name"]').setValue('Base image')
    await wrapper.find('input[aria-label="Reference"]').setValue('taken.qcow2')
    await wrapper.find('.rename-modal form').trigger('submit')
    await flushPromises()

    expect(api.renameImage).toHaveBeenCalledWith('img1', 'Base image')
    expect(api.setImageReference).toHaveBeenCalledWith('img1', 'taken.qcow2')
    expect(wrapper.find('.rename-modal').exists()).toBe(true)
    expect(wrapper.find('input[aria-label="Reference"]').element.value).toBe('taken.qcow2')
    expect(toasts.some((toast) => toast.kind === 'error' && toast.message.includes('Another image already uses that reference.'))).toBe(true)
    expect(api.images).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  const submitUpload = async (wrapper) => {
    const input = wrapper.find('form.upload input[type="file"]')
    const file = new File(['image'], 'base.qcow2', { type: 'application/octet-stream' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await wrapper.find('form.upload').trigger('submit')
    await flushPromises()
  }

  it('maps an uploaded image to the chosen template', async () => {
    session.user = { username: 'editor', role: 'editor' }
    api.templates.mockResolvedValue({
      data: [
        { id: 'csr1000v', name: 'CSR1000V', kind: 'qemu' },
        { id: 'iol', name: 'Cisco IOL', kind: 'iol' }
      ]
    })
    uploadImageWithProgress.mockResolvedValue({ data: { id: 'img-new', name: 'base' } })
    api.mapImageTemplate.mockResolvedValue({ status: 'success' })

    const wrapper = mount(ImagesView)
    await flushPromises()

    const templateSelect = wrapper.find('select[aria-label="Upload template"]')
    expect(templateSelect.exists()).toBe(true)
    expect(templateSelect.findAll('option').map((option) => option.element.value)).toEqual(['', 'csr1000v'])
    await templateSelect.setValue('csr1000v')
    await submitUpload(wrapper)

    expect(uploadImageWithProgress).toHaveBeenCalledTimes(1)
    expect(api.mapImageTemplate).toHaveBeenCalledWith('img-new', 'csr1000v')
    expect(toasts.some((toast) => toast.kind === 'success' && toast.message === 'Image uploaded and mapped to csr1000v')).toBe(true)
    expect(wrapper.find('select[aria-label="Upload template"]').element.value).toBe('')

    await wrapper.find('select[aria-label="Image kind"]').setValue('iso')
    expect(wrapper.find('select[aria-label="Upload template"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps the uploaded image when the template mapping fails', async () => {
    session.user = { username: 'editor', role: 'editor' }
    uploadImageWithProgress.mockResolvedValue({ data: { id: 'img-new', name: 'base' } })
    api.mapImageTemplate.mockRejectedValue(new Error('template not found'))

    const wrapper = mount(ImagesView)
    await flushPromises()

    await wrapper.find('select[aria-label="Upload template"]').setValue('csr1000v')
    await submitUpload(wrapper)

    expect(api.mapImageTemplate).toHaveBeenCalledWith('img-new', 'csr1000v')
    expect(toasts.some((toast) => toast.kind === 'warning'
      && toast.message === 'Image uploaded but the template mapping failed: template not found')).toBe(true)
    expect(wrapper.find('p.error[role="alert"]').exists()).toBe(false)
    expect(api.images).toHaveBeenCalledTimes(2)
    expect(wrapper.find('select[aria-label="Upload template"]').element.value).toBe('')
    wrapper.unmount()
  })
})


const settingsFixture = () => ({
  timezone: 'UTC',
  console_default: 'telnet',
  session_ttl: 3600,
  max_upload_bytes: 33554432,
  demo_enabled: 1,
  demo_landing: 'demo',
  demo_max_guests: 25,
  demo_guest_ttl_hours: 12,
  demo_guest_idle_hours: 2,
  demo_max_nodes_per_lab: 4,
  demo_allowed_templates: 'vpcs,linux',
  demo_max_ram_per_node: 768,
  demo_memory_floor_mb: 4096,
  demo_idle_wipe_minutes: 60,
  demo_sweep_interval: 120,
  turnstile_enabled: 1,
  turnstile_site_key: 'site-key',
  turnstile_secret_key: 'configured-secret'
})

const usersFixture = () => [
  {
    id: 'user1', username: 'admin', email: 'admin@example.test', name: 'Ada Admin', role: 'admin',
    expiration: -1, created_at: 1700000000, updated_at: 1700000000,
    session_count: 2, last_seen_at: 1700000500, lab_count: 1
  },
  {
    id: 'user2', username: 'editor', email: 'editor@example.test', name: '', role: 'editor',
    expiration: 1800000000, created_at: 1700000000, updated_at: 1700000000,
    session_count: 0, last_seen_at: null, lab_count: 2
  },
  {
    id: 'user3', username: 'viewer', email: '', name: 'Vera Viewer', role: 'user',
    expiration: -1, created_at: 1700000000, updated_at: 1700000000,
    session_count: 0, last_seen_at: null, lab_count: 0
  }
]

// Mirror the view's rule: a date-only value expires at 23:59:59 local time.
const endOfDayUnix = (value) => Math.floor(new Date(`${value}T23:59:59`).getTime() / 1000)

const dateInputValue = (unix) => {
  const when = new Date(unix * 1000)
  const pad = (part) => String(part).padStart(2, '0')
  return `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}`
}

function systemTestRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/system', name: 'system', component: { template: '<div />' } }]
  })
}

async function mountSystemApp() {
  const router = systemTestRouter()
  await router.push('/system')
  await router.isReady()
  const wrapper = mount(SystemView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

async function openSystemTab(wrapper, name) {
  const tab = wrapper.findAll('[role="tab"]').find((candidate) => candidate.text() === name)
  await tab.trigger('click')
  await flushPromises()
}

async function mountSystemView() {
  const wrapper = await mountSystemApp()
  await openSystemTab(wrapper, 'Users')
  return wrapper
}

async function mountSystemDemoView() {
  const wrapper = await mountSystemApp()
  await openSystemTab(wrapper, 'Demo')
  return wrapper
}

function rowButton(wrapper, username, label) {
  return wrapper.find(`tr[data-user="${username}"]`).findAll('button').find((button) => button.text() === label)
}

describe('SystemView users', () => {
  beforeEach(() => {
    session.user = { id: 'user1', username: 'admin', role: 'admin' }
    api.host.mockResolvedValue({
      data: { load1: 0.1, load5: 0.1, load15: 0.1, cpu_usage_percent: 5, disk_free_bytes: 1, disk_total_bytes: 2, nodes: [], engines: [], interfaces: [] }
    })
    api.settings.mockResolvedValue({ data: settingsFixture() })
    api.logs.mockResolvedValue({ data: { lines: [] } })
    api.tokens.mockResolvedValue({ data: [] })
    api.users.mockResolvedValue({ data: usersFixture() })
    api.version.mockResolvedValue({ product: 'TeraLab', version: '1.6-dev', license: 'AGPL-3.0-or-later', source: 'https://example.test', components: [] })
  })

  it('hides the Users and Demo tabs from non-admins', async () => {
    session.user = { id: 'user3', username: 'viewer', role: 'user' }
    const router = systemTestRouter()
    await router.push('/system')
    await router.isReady()
    const wrapper = mount(SystemView, { global: { plugins: [router] } })
    await flushPromises()
    const tabNames = wrapper.findAll('[role="tab"]').map((tab) => tab.text())
    expect(tabNames).not.toContain('Users')
    expect(tabNames).not.toContain('Demo')
    expect(api.users).not.toHaveBeenCalled()
    expect(api.templates).not.toHaveBeenCalled()
    expect(api.demoStatus).not.toHaveBeenCalled()
    expect(api.demoSweep).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('renders roles, expiration, session and lab counts for an admin', async () => {
    const wrapper = await mountSystemView()
    expect(wrapper.findAll('[role="tab"]').map((tab) => tab.text())).toContain('Users')
    expect(api.users).toHaveBeenCalledTimes(1)

    const rows = wrapper.findAll('.users-table tbody tr')
    expect(rows).toHaveLength(3)
    const adminRow = wrapper.find('tr[data-user="admin"]')
    expect(adminRow.text()).toContain('(you)')
    expect(adminRow.text()).toContain('never')
    expect(adminRow.text()).toContain('Ada Admin · admin@example.test')
    expect(adminRow.find('.badge.role').text()).toBe('admin')
    const cells = adminRow.findAll('td').map((cell) => cell.text())
    expect(cells[3]).toBe('2')
    expect(cells[5]).toBe('1')

    const editorRow = wrapper.find('tr[data-user="editor"]')
    expect(editorRow.findAll('td')[2].text()).toBe(new Date(1800000000 * 1000).toLocaleDateString())

    const viewerRow = wrapper.find('tr[data-user="viewer"]')
    expect(viewerRow.text()).toContain('Vera Viewer')
    expect(viewerRow.findAll('td')[4].text()).toBe('never')
    expect(viewerRow.find('.badge.you').exists()).toBe(false)
    wrapper.unmount()
  })

  it('sends the add-user payload and shows a generated password once', async () => {
    api.createUser
      .mockResolvedValueOnce({ data: { user: { id: 'user4', username: 'newuser' } } })
      .mockResolvedValueOnce({ data: { user: { id: 'user5', username: 'genuser' }, password: 'Generated-Pass-1234' } })
    const wrapper = await mountSystemView()

    await wrapper.findAll('button').find((button) => button.text() === 'Add user').trigger('click')
    await wrapper.find('input[aria-label="Username"]').setValue('newuser')
    await wrapper.find('select[aria-label="Role"]').setValue('editor')
    await wrapper.find('input[aria-label="Email"]').setValue('new@example.test')
    await wrapper.find('input[aria-label="Display name"]').setValue('New User')
    const expiration = '2027-01-02'
    const expiryInput = wrapper.find('input[aria-label="Expires on"]')
    expect(expiryInput.element.type).toBe('date')
    await expiryInput.setValue(expiration)
    await wrapper.find('input[aria-label="Password"]').setValue('Typed-Password-123!')
    await wrapper.find('.add-user-modal form').trigger('submit')
    await flushPromises()

    expect(api.createUser).toHaveBeenNthCalledWith(1, {
      username: 'newuser',
      role: 'editor',
      email: 'new@example.test',
      name: 'New User',
      expiration: endOfDayUnix(expiration),
      password: 'Typed-Password-123!'
    })
    expect(wrapper.find('.add-user-modal').exists()).toBe(false)
    expect(wrapper.find('.add-user-modal .token-once').exists()).toBe(false)

    await wrapper.findAll('button').find((button) => button.text() === 'Add user').trigger('click')
    await wrapper.find('input[aria-label="Username"]').setValue('genuser')
    await wrapper.find('.add-user-modal form').trigger('submit')
    await flushPromises()

    expect(api.createUser).toHaveBeenNthCalledWith(2, { username: 'genuser', role: 'user' })
    expect(wrapper.find('.add-user-modal .token-once code').text()).toBe('Generated-Pass-1234')
    expect(wrapper.find('.add-user-modal form').exists()).toBe(false)

    await wrapper.find('.add-user-modal').findAll('button').find((button) => button.text() === 'Close').trigger('click')
    expect(wrapper.find('.add-user-modal').exists()).toBe(false)
    wrapper.unmount()
  })

  it('edits email, name, expiration and role, but not your own role', async () => {
    api.updateUser.mockResolvedValue({ data: { user: { id: 'user2' } } })
    const wrapper = await mountSystemView()

    await rowButton(wrapper, 'editor', 'Edit').trigger('click')
    expect(wrapper.find('.edit-user-modal input[aria-label="Email"]').element.value).toBe('editor@example.test')
    const expiryInput = wrapper.find('.edit-user-modal input[aria-label="Expires on"]')
    expect(expiryInput.element.type).toBe('date')
    expect(expiryInput.element.value).toBe(dateInputValue(1800000000))
    await wrapper.find('.edit-user-modal input[aria-label="Display name"]').setValue('Edited Editor')
    await expiryInput.setValue('2027-03-04')
    await wrapper.find('.edit-user-modal select[aria-label="Role"]').setValue('admin')
    await wrapper.find('.edit-user-modal form').trigger('submit')
    await flushPromises()

    expect(api.updateUser).toHaveBeenNthCalledWith(1, 'user2', {
      email: 'editor@example.test',
      name: 'Edited Editor',
      expiration: endOfDayUnix('2027-03-04'),
      role: 'admin'
    })
    expect(wrapper.find('.edit-user-modal').exists()).toBe(false)

    // Clearing the date resets the account to never expiring.
    await rowButton(wrapper, 'editor', 'Edit').trigger('click')
    await wrapper.find('.edit-user-modal input[aria-label="Expires on"]').setValue('2027-03-04')
    await wrapper.find('.edit-user-modal button[aria-label="Clear expiration"]').trigger('click')
    expect(wrapper.find('.edit-user-modal input[aria-label="Expires on"]').element.value).toBe('')
    await wrapper.find('.edit-user-modal form').trigger('submit')
    await flushPromises()

    expect(api.updateUser).toHaveBeenNthCalledWith(2, 'user2', {
      email: 'editor@example.test',
      name: '',
      expiration: -1,
      role: 'editor'
    })

    await rowButton(wrapper, 'admin', 'Edit').trigger('click')
    expect(wrapper.find('.edit-user-modal select[aria-label="Role"]').element.disabled).toBe(true)
    expect(wrapper.find('.edit-user-modal').text()).toContain('You cannot change your own role.')
    wrapper.unmount()
  })

  it('resets a password with a typed value and shows a generated one once', async () => {
    api.resetUserPassword
      .mockResolvedValueOnce({ data: { password: 'Reset-Generated-1234' } })
      .mockResolvedValueOnce({ data: {} })
    const wrapper = await mountSystemView()

    await rowButton(wrapper, 'editor', 'Reset password').trigger('click')
    await wrapper.find('.reset-password-modal form').trigger('submit')
    await flushPromises()
    expect(api.resetUserPassword).toHaveBeenNthCalledWith(1, 'user2', {})
    expect(wrapper.find('.reset-password-modal .token-once code').text()).toBe('Reset-Generated-1234')
    await wrapper.find('.reset-password-modal').findAll('button').find((button) => button.text() === 'Close').trigger('click')

    await rowButton(wrapper, 'viewer', 'Reset password').trigger('click')
    await wrapper.find('.reset-password-modal input[aria-label="New password"]').setValue('Typed-Reset-1234!')
    await wrapper.find('.reset-password-modal form').trigger('submit')
    await flushPromises()
    expect(api.resetUserPassword).toHaveBeenNthCalledWith(2, 'user3', { password: 'Typed-Reset-1234!' })
    expect(wrapper.find('.reset-password-modal').exists()).toBe(false)
    expect(wrapper.find('.reset-password-modal .token-once').exists()).toBe(false)
    expect(toasts.some((toast) => toast.kind === 'success' && toast.message.includes('Password reset for viewer'))).toBe(true)
    wrapper.unmount()
  })

  it('force logs out after confirmation', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    api.revokeUserSessions.mockResolvedValue({ data: { revoked: 3 } })
    const wrapper = await mountSystemView()

    await rowButton(wrapper, 'editor', 'Force logout').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalled()
    expect(api.revokeUserSessions).toHaveBeenCalledWith('user2')
    expect(toasts.some((toast) => toast.kind === 'success' && toast.message.includes('3 sessions revoked'))).toBe(true)
    confirm.mockRestore()
    wrapper.unmount()
  })

  it('deletes with and without the delete_labs option', async () => {
    api.deleteUser.mockResolvedValue({ data: { deleted: true, labs_trashed: 0 } })
    const wrapper = await mountSystemView()

    await rowButton(wrapper, 'editor', 'Delete').trigger('click')
    await wrapper.find('input[aria-label="Also delete their labs"]').setValue(true)
    await wrapper.find('.delete-user-modal').findAll('button').find((button) => button.text() === 'Delete user').trigger('click')
    await flushPromises()
    expect(api.deleteUser).toHaveBeenNthCalledWith(1, 'user2', { delete_labs: true })
    expect(wrapper.find('.delete-user-modal').exists()).toBe(false)

    await rowButton(wrapper, 'viewer', 'Delete').trigger('click')
    await wrapper.find('.delete-user-modal').findAll('button').find((button) => button.text() === 'Delete user').trigger('click')
    await flushPromises()
    expect(api.deleteUser).toHaveBeenNthCalledWith(2, 'user3', { delete_labs: false })
    wrapper.unmount()
  })

  it('keeps the dialog open and toasts the server guard message', async () => {
    api.deleteUser.mockRejectedValue(new Error('You cannot delete your own account.'))
    const wrapper = await mountSystemView()

    await rowButton(wrapper, 'admin', 'Delete').trigger('click')
    await wrapper.find('.delete-user-modal').findAll('button').find((button) => button.text() === 'Delete user').trigger('click')
    await flushPromises()

    expect(api.deleteUser).toHaveBeenCalledWith('user1', { delete_labs: false })
    expect(wrapper.find('.delete-user-modal').exists()).toBe(true)
    expect(toasts.some((toast) => toast.kind === 'error' && toast.message.includes('You cannot delete your own account.'))).toBe(true)
    wrapper.unmount()
  })

  it('explains the last-admin guard when a role update is rejected', async () => {
    api.updateUser.mockRejectedValue(new Error('The last administrator cannot be demoted.'))
    const wrapper = await mountSystemView()

    await rowButton(wrapper, 'editor', 'Edit').trigger('click')
    await wrapper.find('.edit-user-modal select[aria-label="Role"]').setValue('user')
    await wrapper.find('.edit-user-modal form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.edit-user-modal').exists()).toBe(true)
    expect(toasts.some((toast) => toast.kind === 'error' && toast.message.includes('last administrator cannot be demoted'))).toBe(true)
    wrapper.unmount()
  })
})

describe('SystemView demo', () => {
  beforeEach(() => {
    session.user = { id: 'user1', username: 'admin', role: 'admin' }
    api.host.mockResolvedValue({
      data: { load1: 0.1, load5: 0.1, load15: 0.1, cpu_usage_percent: 5, disk_free_bytes: 1, disk_total_bytes: 2, nodes: [], engines: [], interfaces: [] }
    })
    api.settings.mockResolvedValue({ data: settingsFixture() })
    api.logs.mockResolvedValue({ data: { lines: [] } })
    api.tokens.mockResolvedValue({ data: [] })
    api.users.mockResolvedValue({ data: usersFixture() })
    api.version.mockResolvedValue({ product: 'TeraLab', version: '1.6-dev', license: 'AGPL-3.0-or-later', source: 'https://example.test', components: [] })
    api.templates.mockResolvedValue({
      data: [
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs' },
        { id: 'linux', name: 'Linux', kind: 'qemu' },
        { id: 'iol', name: 'Cisco IOL', kind: 'iol' }
      ]
    })
    api.saveSettings.mockResolvedValue({ data: settingsFixture() })
    api.demoStatus.mockResolvedValue({
      data: { last_sweep_at: 1800000000, mem_available_mb: 4096, guests_active: 1, demo_nodes_running: 2 }
    })
    api.demoSweep.mockResolvedValue({
      data: { guests_purged: 0, labs_wiped: 0, nodes_reaped: 0, mem_available_mb: 4096 }
    })
  })

  it('shows the demo tab to admins and loads templates lazily', async () => {
    const wrapper = await mountSystemApp()
    expect(wrapper.findAll('[role="tab"]').map((tab) => tab.text())).toContain('Demo')
    expect(api.templates).not.toHaveBeenCalled()

    await openSystemTab(wrapper, 'Demo')

    expect(api.templates).toHaveBeenCalledTimes(1)
    expect(wrapper.find('select[aria-label="Demo mode"]').element.value).toBe('1')
    expect(wrapper.find('select[aria-label="Landing page for signed-out visitors"]').element.value).toBe('demo')
    expect(wrapper.find('input[aria-label="Max guest sessions"]').element.value).toBe('25')
    expect(wrapper.find('input[aria-label="Guest lifetime (hours)"]').element.value).toBe('12')
    expect(wrapper.find('input[aria-label="Guest idle timeout (hours)"]').element.value).toBe('2')
    expect(wrapper.find('input[aria-label="Max nodes per guest lab"]').element.value).toBe('4')
    expect(wrapper.find('input[aria-label="Max RAM per node (MB)"]').element.value).toBe('768')
    expect(wrapper.find('input[aria-label="Host memory floor (MB)"]').element.value).toBe('4096')
    expect(wrapper.find('input[aria-label="Idle lab wipe (minutes)"]').element.value).toBe('60')
    expect(wrapper.find('input[aria-label="Guest sweep interval (seconds)"]').element.value).toBe('120')
    expect(wrapper.find('select[aria-label="Turnstile"]').element.value).toBe('1')
    expect(wrapper.find('input[aria-label="Turnstile site key"]').element.value).toBe('site-key')

    const labels = wrapper.findAll('.demo-panel label').map((label) => label.text())
    for (const text of [
      'Max guest sessions', 'Guest lifetime (hours)', 'Guest idle timeout (hours)',
      'Max nodes per guest lab', 'Max RAM per node (MB)', 'Host memory floor (MB)',
      'Idle lab wipe (minutes)', 'Guest sweep interval (seconds)'
    ]) {
      expect(labels.some((label) => label.includes(text))).toBe(true)
    }

    const allowlist = wrapper.find('[role="group"][aria-label="Allowed templates"]')
    expect(allowlist.text()).toContain('VPCS')
    expect(allowlist.text()).toContain('Linux')
    expect(allowlist.text()).toContain('Cisco IOL')
    expect(wrapper.find('#demo-template-vpcs').element.checked).toBe(true)
    expect(wrapper.find('#demo-template-linux').element.checked).toBe(true)
    expect(wrapper.find('#demo-template-iol').element.checked).toBe(false)

    const secret = wrapper.find('input[aria-label="Turnstile secret key"]')
    expect(secret.element.value).toBe('')
    expect(wrapper.find('.secret-field .badge').text()).toBe('configured')
    expect(wrapper.text()).toContain("Cloudflare's public testing keys")
    wrapper.unmount()
  })

  it('keeps both save buttons disabled until settings load', async () => {
    let resolveSettings
    api.settings.mockReturnValue(new Promise((resolve) => { resolveSettings = resolve }))

    const router = systemTestRouter()
    await router.push('/system')
    await router.isReady()
    const wrapper = mount(SystemView, { global: { plugins: [router] } })
    await flushPromises()

    const settingsSave = wrapper.find('.settings-panel:not(.demo-panel) button[type="submit"]')
    const demoSave = wrapper.find('.demo-panel button[type="submit"]')
    expect(settingsSave.element.disabled).toBe(true)
    expect(demoSave.element.disabled).toBe(true)

    resolveSettings({ data: settingsFixture() })
    await flushPromises()

    expect(settingsSave.element.disabled).toBe(false)
    expect(demoSave.element.disabled).toBe(false)
    wrapper.unmount()
  })

  it('saves numeric, enum and csv fields and omits an unchanged secret', async () => {
    const wrapper = await mountSystemDemoView()

    await wrapper.find('select[aria-label="Demo mode"]').setValue('0')
    await wrapper.find('select[aria-label="Landing page for signed-out visitors"]').setValue('login')
    await wrapper.find('input[aria-label="Max guest sessions"]').setValue('100')
    await wrapper.find('input[aria-label="Guest lifetime (hours)"]').setValue('24')
    await wrapper.find('input[aria-label="Guest idle timeout (hours)"]').setValue('3')
    await wrapper.find('input[aria-label="Max nodes per guest lab"]').setValue('6')
    await wrapper.find('input[aria-label="Max RAM per node (MB)"]').setValue('512')
    await wrapper.find('input[aria-label="Host memory floor (MB)"]').setValue('8192')
    await wrapper.find('input[aria-label="Idle lab wipe (minutes)"]').setValue('90')
    await wrapper.find('input[aria-label="Guest sweep interval (seconds)"]').setValue('600')
    await wrapper.find('select[aria-label="Turnstile"]').setValue('0')
    await wrapper.find('input[aria-label="Turnstile site key"]').setValue('new-site-key')
    await wrapper.find('#demo-template-vpcs').setValue(false)
    await wrapper.find('#demo-template-iol').setValue(true)
    await wrapper.find('.demo-panel form').trigger('submit')
    await flushPromises()

    expect(api.saveSettings).toHaveBeenCalledTimes(1)
    const payload = api.saveSettings.mock.calls[0][0]
    expect(payload).toMatchObject({
      demo_enabled: 0,
      demo_landing: 'login',
      demo_max_guests: 100,
      demo_guest_ttl_hours: 24,
      demo_guest_idle_hours: 3,
      demo_max_nodes_per_lab: 6,
      demo_max_ram_per_node: 512,
      demo_memory_floor_mb: 8192,
      demo_idle_wipe_minutes: 90,
      demo_sweep_interval: 600,
      turnstile_enabled: 0,
      turnstile_site_key: 'new-site-key'
    })
    expect(payload.demo_allowed_templates).toBe('linux,iol')
    expect('turnstile_secret_key' in payload).toBe(false)
    expect(toasts.some((toast) => toast.kind === 'success' && toast.message.includes('Demo settings saved'))).toBe(true)
    wrapper.unmount()
  })

  it('sends the secret only when the admin types a replacement', async () => {
    const wrapper = await mountSystemDemoView()
    const secret = wrapper.find('input[aria-label="Turnstile secret key"]')

    await wrapper.find('.demo-panel form').trigger('submit')
    await flushPromises()
    expect(api.saveSettings).toHaveBeenCalledTimes(1)
    expect('turnstile_secret_key' in api.saveSettings.mock.calls[0][0]).toBe(false)

    await secret.setValue('replacement-secret')
    await wrapper.find('.demo-panel form').trigger('submit')
    await flushPromises()

    expect(api.saveSettings).toHaveBeenCalledTimes(2)
    expect(api.saveSettings.mock.calls[1][0].turnstile_secret_key).toBe('replacement-secret')
    expect(wrapper.find('input[aria-label="Turnstile secret key"]').element.value).toBe('')
    wrapper.unmount()
  })

  it('keeps invalid server values visible', async () => {
    api.saveSettings.mockRejectedValue(new Error('demo_guest_ttl_hours must be between 1 and 720.'))
    const wrapper = await mountSystemDemoView()

    await wrapper.find('input[aria-label="Guest lifetime (hours)"]').setValue('0')
    await wrapper.find('.demo-panel form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('p.error[role="alert"]').text()).toContain('demo_guest_ttl_hours must be between 1 and 720.')
    expect(wrapper.find('input[aria-label="Guest lifetime (hours)"]').element.value).toBe('0')
    expect(toasts.some((toast) => toast.kind === 'error' && toast.message.includes('demo_guest_ttl_hours'))).toBe(true)
    wrapper.unmount()
  })

  it('renders the sweep status, including never and unknown values', async () => {
    api.demoStatus
      .mockResolvedValueOnce({
        data: { last_sweep_at: null, mem_available_mb: null, guests_active: 3, demo_nodes_running: 0 }
      })
      .mockResolvedValueOnce({
        data: { last_sweep_at: 1800000000, mem_available_mb: 6144, guests_active: 0, demo_nodes_running: 1 }
      })

    const wrapper = await mountSystemDemoView()
    expect(api.demoStatus).toHaveBeenCalledTimes(1)
    const status = wrapper.find('[data-test="demo-status"]')
    expect(status.find('[data-test="demo-last-sweep"]').text()).toBe('never')
    expect(status.find('[data-test="demo-memory"]').text()).toBe('unknown')
    expect(status.find('[data-test="demo-guests"]').text()).toBe('3')
    expect(status.find('[data-test="demo-nodes"]').text()).toBe('0')

    await openSystemTab(wrapper, 'Host')
    await openSystemTab(wrapper, 'Demo')

    expect(api.demoStatus).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-test="demo-last-sweep"]').text()).toBe(new Date(1800000000 * 1000).toLocaleString())
    expect(wrapper.find('[data-test="demo-memory"]').text()).toBe('6144 MB')
    expect(wrapper.find('[data-test="demo-guests"]').text()).toBe('0')
    expect(wrapper.find('[data-test="demo-nodes"]').text()).toBe('1')
    wrapper.unmount()
  })

  it('sweeps once, summarises the result in a toast, and refreshes the status', async () => {
    api.demoStatus
      .mockResolvedValueOnce({
        data: { last_sweep_at: null, mem_available_mb: 8192, guests_active: 1, demo_nodes_running: 1 }
      })
      .mockResolvedValueOnce({
        data: { last_sweep_at: 1800000123, mem_available_mb: 6144, guests_active: 0, demo_nodes_running: 0 }
      })
      .mockResolvedValueOnce({
        data: { last_sweep_at: 1800000456, mem_available_mb: 6144, guests_active: 0, demo_nodes_running: 0 }
      })
    api.demoSweep.mockResolvedValue({
      data: { guests_purged: 2, labs_wiped: 3, nodes_reaped: 1, mem_available_mb: 6144 }
    })

    const wrapper = await mountSystemDemoView()
    const button = wrapper.find('[data-test="demo-sweep"]')
    expect(button.text()).toBe('Sweep now')

    await button.trigger('click')
    await flushPromises()

    expect(api.demoSweep).toHaveBeenCalledTimes(1)
    expect(toasts.some((toast) => toast.kind === 'success'
      && toast.message === 'Sweep complete: 2 guests purged, 3 labs wiped, 1 node stopped.')).toBe(true)
    expect(api.demoStatus).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-test="demo-last-sweep"]').text()).toBe(new Date(1800000123 * 1000).toLocaleString())
    expect(wrapper.find('[data-test="demo-memory"]').text()).toBe('6144 MB')
    expect(wrapper.find('[data-test="demo-sweep"]').element.disabled).toBe(false)

    api.demoSweep.mockResolvedValueOnce({
      data: { guests_purged: 0, labs_wiped: 0, nodes_reaped: 1, labs_failed: 2, mem_available_mb: 6144 }
    })
    await wrapper.find('[data-test="demo-sweep"]').trigger('click')
    await flushPromises()

    expect(api.demoSweep).toHaveBeenCalledTimes(2)
    expect(toasts.some((toast) => toast.kind === 'warn'
      && toast.message === 'Sweep complete: 0 guests purged, 0 labs wiped, 1 node stopped; 2 labs could not be wiped and will be retried.')).toBe(true)
    expect(api.demoStatus).toHaveBeenCalledTimes(3)
    expect(wrapper.find('[data-test="demo-last-sweep"]').text()).toBe(new Date(1800000456 * 1000).toLocaleString())
    expect(wrapper.find('[data-test="demo-sweep"]').element.disabled).toBe(false)
    wrapper.unmount()
  })

  it('shows a sweep error, keeps the status, and re-enables the button', async () => {
    api.demoSweep.mockRejectedValue(new Error('The sweeper is unavailable.'))

    const wrapper = await mountSystemDemoView()
    const statusBefore = wrapper.find('[data-test="demo-status"]').text()
    await wrapper.find('[data-test="demo-sweep"]').trigger('click')
    await flushPromises()

    expect(api.demoSweep).toHaveBeenCalledTimes(1)
    expect(toasts.some((toast) => toast.kind === 'error' && toast.message.includes('The sweeper is unavailable.'))).toBe(true)
    expect(api.demoStatus).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="demo-status"]').text()).toBe(statusBefore)
    expect(wrapper.find('[data-test="demo-sweep"]').element.disabled).toBe(false)
    expect(wrapper.find('[data-test="demo-sweep"]').text()).toBe('Sweep now')
    wrapper.unmount()
  })
})

describe('SystemView account settings', () => {
  beforeEach(() => {
    session.user = { id: 'user1', username: 'admin', role: 'admin' }
    api.host.mockResolvedValue({
      data: { load1: 0.1, load5: 0.1, load15: 0.1, cpu_usage_percent: 5, disk_free_bytes: 1, disk_total_bytes: 2, nodes: [], engines: [], interfaces: [] }
    })
    api.settings.mockResolvedValue({ data: settingsFixture() })
    api.logs.mockResolvedValue({ data: { lines: [] } })
    api.tokens.mockResolvedValue({ data: [] })
    api.users.mockResolvedValue({ data: usersFixture() })
    api.version.mockResolvedValue({ product: 'TeraLab', version: '1.6-dev', license: 'AGPL-3.0-or-later', source: 'https://example.test', components: [] })
  })

  it('offers the password dialog from Settings to non-admins', async () => {
    session.user = { id: 'user3', username: 'viewer', role: 'user' }
    const wrapper = await mountSystemApp()
    await openSystemTab(wrapper, 'Settings')

    const account = wrapper.find('.account-block')
    expect(account.exists()).toBe(true)
    expect(account.text()).toContain('Account')
    expect(account.text()).toContain('Change the password for your own account.')
    expect(wrapper.find('.settings-panel form button[type="submit"]').element.disabled).toBe(true)

    const changePassword = account.findAll('button').find((button) => button.text() === 'Change password')
    expect(changePassword).toBeTruthy()
    await changePassword.trigger('click')
    expect(wrapper.find('#password-modal-title').exists()).toBe(true)
    expect(wrapper.find('.password-modal').exists()).toBe(true)

    await wrapper.find('.password-modal').findAll('button').find((button) => button.text() === 'Cancel').trigger('click')
    expect(wrapper.find('.password-modal').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the Account block to admins as well', async () => {
    const wrapper = await mountSystemApp()
    await openSystemTab(wrapper, 'Settings')

    const account = wrapper.find('.account-block')
    expect(account.exists()).toBe(true)
    expect(account.findAll('button').some((button) => button.text() === 'Change password')).toBe(true)
    expect(wrapper.find('.settings-panel form button[type="submit"]').element.disabled).toBe(false)
    wrapper.unmount()
  })
})

describe('PasswordChangeDialog', () => {
  it('validates the confirmation and calls the API with the auth field names', async () => {
    api.changePassword.mockResolvedValue({ status: 'success' })
    const wrapper = mount(PasswordChangeDialog, { props: { open: true } })
    expect(wrapper.text()).toContain('Your other sessions will be signed out; this session stays signed in.')

    await wrapper.find('input[aria-label="Current password"]').setValue('Old-Password-123!')
    await wrapper.find('input[aria-label="New password"]').setValue('New-Password-123!')
    await wrapper.find('input[aria-label="Confirm new password"]').setValue('Mismatch-123!')
    await wrapper.find('form').trigger('submit')
    expect(api.changePassword).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('The new passwords do not match.')

    await wrapper.find('input[aria-label="Confirm new password"]').setValue('New-Password-123!')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.changePassword).toHaveBeenCalledWith({
      current_password: 'Old-Password-123!',
      new_password: 'New-Password-123!'
    })
    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(toasts.some((toast) => toast.kind === 'success' && toast.message.includes('Password changed'))).toBe(true)
    wrapper.unmount()
  })

  it('shows the server error and stays open', async () => {
    api.changePassword.mockRejectedValue(new Error('Current password is incorrect.'))
    const wrapper = mount(PasswordChangeDialog, { props: { open: true } })

    await wrapper.find('input[aria-label="Current password"]').setValue('Wrong-Password-123!')
    await wrapper.find('input[aria-label="New password"]').setValue('New-Password-123!')
    await wrapper.find('input[aria-label="Confirm new password"]').setValue('New-Password-123!')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.error').text()).toContain('Current password is incorrect.')
    expect(wrapper.emitted('close')).toBeUndefined()
    wrapper.unmount()
  })
})
