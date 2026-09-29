import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilesSyncToolCard from '../../../src/components/files/FilesSyncToolCard.vue'

describe('FilesSyncToolCard', () => {
	const globalStubs = {
		global: {
			stubs: {
				NcButton: {
					template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
					props: ['disabled', 'variant'],
				},
			},
		},
	}

	it('renders title, description and button text', () => {
		const wrapper = mount(FilesSyncToolCard, {
			props: {
				isRunning: false,
				disabled: false,
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Sync file paths and metadata')
		expect(wrapper.text()).toContain('Check and sync now')
		expect(wrapper.find('span.hint').exists()).toBe(false)
	})

	it('shows library hint and disables button when disabled is true', () => {
		const wrapper = mount(FilesSyncToolCard, {
			props: {
				isRunning: false,
				disabled: true,
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Select a library first.')
		const btn = wrapper.find('button')
		expect(btn.attributes('disabled')).toBeDefined()
	})

	it('shows syncing text and disables button when isRunning is true', () => {
		const wrapper = mount(FilesSyncToolCard, {
			props: {
				isRunning: true,
				disabled: false,
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Syncing…')
		const btn = wrapper.find('button')
		expect(btn.attributes('disabled')).toBeDefined()
	})

	it('emits sync event on button click', async () => {
		const wrapper = mount(FilesSyncToolCard, {
			props: {
				isRunning: false,
				disabled: false,
			},
			...globalStubs,
		})

		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('sync')).toBeTruthy()
	})
})
