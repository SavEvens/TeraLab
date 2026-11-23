// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TemplatePicker from '../src/components/TemplatePicker.vue'

const templates = [
  { id: 'linux', name: 'Linux', kind: 'qemu' },
  { id: 'iol', name: 'Cisco IOL', kind: 'iol' },
  { id: 'vpcs', name: 'VPCS', kind: 'vpcs' }
]
const images = [{ id: 'img1', name: 'Base image', reference: 'base.qcow2', template: 'linux' }]

const tile = (wrapper, group, name) => wrapper
  .findAll(`[data-group="${group}"] li`)
  .find((item) => item.text().includes(name))

describe('TemplatePicker demo allowlist', () => {
  it('renders the existing groups unchanged without an allowlist', () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images } })

    expect(wrapper.find('[data-group="demo-locked"]').exists()).toBe(false)
    expect(wrapper.find('[data-group="ready"]').text()).toContain('Linux')
    expect(wrapper.find('[data-group="needs-image"]').text()).toContain('Cisco IOL')
    expect(wrapper.find('[data-group="needs-image"]').text()).toContain('VPCS')
    expect(wrapper.findAll('.template-grid li')).toHaveLength(3)
  })

  it('locks every template and disables every Add for an empty allowlist', async () => {
    const wrapper = mount(TemplatePicker, { props: { templates, images, allowedTemplates: [] } })

    expect(wrapper.find('[data-group="ready"]').exists()).toBe(false)
    expect(wrapper.find('[data-group="needs-image"]').exists()).toBe(false)
    const locked = wrapper.find('[data-group="demo-locked"]')
    expect(locked.exists()).toBe(true)
    expect(locked.text()).toContain('Linux')
    expect(locked.text()).toContain('Cisco IOL')
    expect(locked.text()).toContain('VPCS')
    expect(locked.findAll('h3 .badge')[0].text()).toBe('3')

    const buttons = locked.findAll('button')
    expect(buttons).toHaveLength(3)
    for (const button of buttons) {
      expect(button.element.disabled).toBe(true)
      expect(button.attributes('aria-disabled')).toBe('true')
      await button.trigger('click')
    }
    expect(wrapper.emitted('add')).toBeUndefined()
  })

  it('splits allowed templates into ready and needs-image and locks the rest', () => {
    const wrapper = mount(TemplatePicker, {
      props: { templates, images, allowedTemplates: ['linux', 'vpcs'] }
    })

    const ready = wrapper.find('[data-group="ready"]')
    const needsImage = wrapper.find('[data-group="needs-image"]')
    const locked = wrapper.find('[data-group="demo-locked"]')

    expect(ready.text()).toContain('Linux')
    expect(ready.text()).not.toContain('Cisco IOL')
    expect(needsImage.text()).toContain('VPCS')
    expect(needsImage.text()).not.toContain('Cisco IOL')
    expect(locked.exists()).toBe(true)
    expect(locked.text()).toContain('Cisco IOL')
    expect(locked.text()).not.toContain('Linux')
    expect(locked.text()).not.toContain('VPCS')
    expect(locked.text()).toContain('Available on the full platform')
    expect(locked.text()).toContain('The demo includes a starter set of templates. Sign in with a full account to use these.')
    expect(locked.find('h3 .badge').text()).toBe('1')
    expect(wrapper.findAll('.template-grid li')).toHaveLength(3)
  })

  it('disables Add on locked entries and refuses to emit', async () => {
    const wrapper = mount(TemplatePicker, {
      props: { templates, images, allowedTemplates: ['linux', 'vpcs'] }
    })

    const button = tile(wrapper, 'demo-locked', 'Cisco IOL').find('button')
    expect(button.text()).toBe('Add')
    expect(button.element.disabled).toBe(true)
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-disabled')).toBe('true')
    expect(button.attributes('title')).toContain('available on the full platform')

    await button.trigger('click')
    expect(wrapper.emitted('add')).toBeUndefined()
  })

  it('still emits the template and count for allowed entries', async () => {
    const wrapper = mount(TemplatePicker, {
      props: { templates, images, allowedTemplates: ['linux', 'vpcs'] }
    })

    await wrapper.find('input[type="number"]').setValue('2')
    await tile(wrapper, 'needs-image', 'VPCS').find('button').trigger('click')

    expect(wrapper.emitted('add')).toHaveLength(1)
    expect(wrapper.emitted('add')[0][0]).toMatchObject({ template: { id: 'vpcs' }, count: 2, image: '' })

    await tile(wrapper, 'ready', 'Linux').find('button').trigger('click')
    expect(wrapper.emitted('add')[1][0]).toMatchObject({ template: { id: 'linux' }, count: 2, image: 'base.qcow2' })
  })

  it('filters across allowed and locked groups', async () => {
    const wrapper = mount(TemplatePicker, {
      props: { templates, images, allowedTemplates: ['linux', 'vpcs'] }
    })

    await wrapper.find('input[aria-label="Filter templates"]').setValue('iol')
    expect(wrapper.find('[data-group="ready"]').exists()).toBe(false)
    expect(wrapper.find('[data-group="needs-image"]').exists()).toBe(false)
    expect(wrapper.find('[data-group="demo-locked"]').text()).toContain('Cisco IOL')
    expect(wrapper.find('.empty').text()).toContain('No templates included in the demo match')
    expect(wrapper.findAll('[data-group="demo-locked"] li')).toHaveLength(1)

    await wrapper.find('input[aria-label="Filter templates"]').setValue('vpcs')
    expect(wrapper.find('[data-group="needs-image"]').text()).toContain('VPCS')
    expect(wrapper.find('[data-group="demo-locked"]').exists()).toBe(false)

    await wrapper.find('input[aria-label="Filter templates"]').setValue('nothing-here')
    expect(wrapper.findAll('.template-grid li')).toHaveLength(0)
    expect(wrapper.find('.empty').text()).toContain('No templates match')
  })

  it('keeps text and picture additions available to a guest', async () => {
    const wrapper = mount(TemplatePicker, {
      props: { templates, images, allowedTemplates: ['linux', 'vpcs'] }
    })

    await wrapper.findAll('.picker-special button').find((button) => button.text() === 'Add text').trigger('click')
    await wrapper.findAll('.picker-special button').find((button) => button.text() === 'Add picture').trigger('click')

    expect(wrapper.emitted('add')).toHaveLength(2)
    expect(wrapper.emitted('add')[0][0].template).toMatchObject({ id: '__text', kind: 'text' })
    expect(wrapper.emitted('add')[1][0].template).toMatchObject({ id: '__picture', kind: 'picture' })
  })
})
