// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AddLinkDialog from '../src/components/AddLinkDialog.vue'
import Inspector from '../src/components/Inspector.vue'
import TemplatePicker from '../src/components/TemplatePicker.vue'
import ToastHost from '../src/components/ToastHost.vue'
import { notify, toasts, dismiss } from '../src/stores/toasts'

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
    expect(wrapper.emitted('add')[0][0].id).toBe('vpcs')
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

  it('emits updates and actions', async () => {
    const node = { id: '1', position: { x: 0, y: 0 }, data: { name: 'R1', template: 'linux', kind: 'qemu', image: '', console: 'telnet', cpu: 1, ram: 512, serial: 0, interfaces: [{ id: 0 }] } }
    const wrapper = mount(Inspector, {
      props: { node, templates: [{ id: 'linux', name: 'Linux' }, { id: 'vpcs', name: 'VPCS' }] }
    })
    await wrapper.findAll('input')[0].setValue('R2')
    expect(wrapper.emitted('update')[0][0]).toEqual({ key: 'name', value: 'R2' })
    const nic = wrapper.findAll('select').find((select) => select.findAll('option').some((option) => option.element.value === 'e1000'))
    await nic.setValue('virtio-net-pci')
    expect(wrapper.emitted('update').some((entry) => entry[0].key === 'nic' && entry[0].value === 'virtio-net-pci')).toBe(true)
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
