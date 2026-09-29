import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AttachNextcloudFileDialog from '../../../src/components/song-editor/AttachNextcloudFileDialog.vue'

describe('AttachNextcloudFileDialog', () => {
	const globalStubs = {
		global: {
			stubs: {
				NcDialog: {
					template: '<div class="nc-dialog-stub"><slot /></div>',
				},
				NcButton: {
					template: '<button><slot /></button>',
				},
			},
		},
	}

	it('does not render dialog content when open is false', () => {
		const wrapper = mount(AttachNextcloudFileDialog, {
			props: {
				open: false,
				filename: 'Test.pdf',
				action: 'copy',
			},
			...globalStubs,
		})

		expect(wrapper.find('.nc-dialog-stub').exists()).toBe(false)
	})

	it('renders filename and radio options when open is true', () => {
		const wrapper = mount(AttachNextcloudFileDialog, {
			props: {
				open: true,
				filename: 'Test.pdf',
				action: 'copy',
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Attach Test.pdf to this song?')
		const radios = wrapper.findAll('input[type="radio"]')
		expect(radios.length).toBe(2)
		expect((radios[0].element as HTMLInputElement).checked).toBe(true)
	})

	it('emits update:action on radio change', async () => {
		const wrapper = mount(AttachNextcloudFileDialog, {
			props: {
				open: true,
				filename: 'Test.pdf',
				action: 'copy',
			},
			...globalStubs,
		})

		const radios = wrapper.findAll('input[type="radio"]')
		await radios[1].setValue(true)

		expect(wrapper.emitted('update:action')).toBeTruthy()
		expect(wrapper.emitted('update:action')![0]).toEqual(['move'])
	})

	it('emits confirm when confirm button is clicked', async () => {
		const wrapper = mount(AttachNextcloudFileDialog, {
			props: {
				open: true,
				filename: 'Test.pdf',
				action: 'copy',
			},
			...globalStubs,
		})

		const buttons = wrapper.findAll('button')
		const confirmBtn = buttons.find((btn) => btn.text().includes('Attach File'))
		expect(confirmBtn).toBeDefined()
		await confirmBtn!.trigger('click')

		expect(wrapper.emitted('confirm')).toBeTruthy()
	})
})
