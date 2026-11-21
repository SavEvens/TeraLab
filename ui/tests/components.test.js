// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AddLinkDialog from '../src/components/AddLinkDialog.vue'
import Inspector from '../src/components/Inspector.vue'
import TemplatePicker from '../src/components/TemplatePicker.vue'
import ToastHost from '../src/components/ToastHost.vue'
import ImagesView from '../src/views/ImagesView.vue'
import { api } from '../src/api'
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
    mapImageTemplate: vi.fn()
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
  const images = [{ id: 'img1', name: 'base.qcow2', template: 'linux' }]

  it('groups templates by image mapping and emits add', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })
    const ready = wrapper.find('[data-group="ready"]')
    const needsImage = wrapper.find('[data-group="needs-image"]')
    expect(ready.text()).toContain('Linux')
    expect(ready.text()).toContain('base.qcow2')
    expect(ready.text()).not.toContain('VPCS')
    expect(needsImage.text()).toContain('VPCS')
    expect(needsImage.text()).toContain('Cisco IOL')

    const vpcs = wrapper.findAll('[data-group="needs-image"] li').find((item) => item.text().includes('VPCS'))
    await vpcs.find('button').trigger('click')
    expect(wrapper.emitted('add')[0][0].template.id).toBe('vpcs')
    expect(wrapper.emitted('add')[0][0].count).toBe(1)
  })

  it('clamps the count to 1-20 and forwards it', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })
    const count = wrapper.find('input[type="number"]')
    const linux = () => wrapper.findAll('[data-group="ready"] li').find((item) => item.text().includes('Linux'))

    await count.setValue('25')
    await linux().find('button').trigger('click')
    expect(wrapper.emitted('add').at(-1)[0].template.id).toBe('linux')
    expect(wrapper.emitted('add').at(-1)[0].count).toBe(20)

    await count.setValue('0')
    await linux().find('button').trigger('click')
    expect(wrapper.emitted('add').at(-1)[0].count).toBe(1)
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

  it('marks an unmapped image and emits nvram', async () => {
    const node = { id: '1', position: { x: 0, y: 0 }, data: { name: 'R1', template: 'iol', kind: 'iol', image: 'missing.bin', console: 'telnet', cpu: 1, ram: 512, serial: 0, nvram: 0, interfaces: [{ id: 0 }] } }
    const wrapper = mount(Inspector, { props: { node, templates: [], images: [] } })
    expect(wrapper.text()).toContain('missing.bin (unmapped)')
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
})
