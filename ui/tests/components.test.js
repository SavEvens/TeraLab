// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Palette from '../src/components/Palette.vue'
import Inspector from '../src/components/Inspector.vue'
import ToastHost from '../src/components/ToastHost.vue'
import { notify, toasts, dismiss } from '../src/stores/toasts'

describe('Palette', () => {
  it('filters templates and emits add on double click', async () => {
    const wrapper = mount(Palette, {
      props: { templates: [
        { id: 'linux', name: 'Linux', kind: 'qemu' },
        { id: 'vpcs', name: 'VPCS', kind: 'vpcs' }
      ] }
    })
    expect(wrapper.findAll('li')).toHaveLength(2)
    await wrapper.find('input').setValue('vpc')
    expect(wrapper.findAll('li')).toHaveLength(1)
    await wrapper.find('li').trigger('dblclick')
    expect(wrapper.emitted('add')[0][0].id).toBe('vpcs')
  })
})

describe('Inspector', () => {
  it('emits updates and actions', async () => {
    const node = { id: '1', position: { x: 0, y: 0 }, data: { name: 'R1', kind: 'qemu', image: '', console: 'telnet', cpu: 1, ram: 512, interfaces: [{ id: 0 }] } }
    const wrapper = mount(Inspector, { props: { node } })
    await wrapper.findAll('input')[0].setValue('R2')
    expect(wrapper.emitted('update')[0][0]).toEqual({ key: 'name', value: 'R2' })
    const buttons = wrapper.findAll('button')
    await buttons[0].trigger('click')
    expect(wrapper.emitted('console')).toBeTruthy()
    await buttons[1].trigger('click')
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
