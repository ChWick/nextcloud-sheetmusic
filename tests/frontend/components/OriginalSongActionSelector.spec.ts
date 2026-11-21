import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OriginalSongActionSelector from '../../../src/components/song-pdf/OriginalSongActionSelector.vue'

vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: {
		name: 'NcPopover',
		props: ['triggers', 'placement'],
		template: `
			<div class="nc-popover-mock">
				<slot name="trigger" />
				<div class="nc-popover-content-mock">
					<slot />
				</div>
			</div>
		`,
	},
}))

describe('OriginalSongActionSelector.vue', () => {
	it('renders four options and indicates active option', () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		expect(options).toHaveLength(4)

		// Option 0 (keep) should be active
		expect(options[0].attributes('aria-checked')).toBe('true')
		expect(options[1].attributes('aria-checked')).toBe('false')
		expect(options[2].attributes('aria-checked')).toBe('false')
		expect(options[3].attributes('aria-checked')).toBe('false')
	})

	it('emits update:modelValue when an option is selected', async () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		await options[1].trigger('click')
		await options[2].trigger('click')

		expect(wrapper.emitted('update:modelValue')).toEqual([
			['remove-pages'],
			['delete-file'],
		])
	})

	it('disables remove-pages option when canRemovePages is false', async () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
				canRemovePages: false,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		const removePagesOption = options[1]

		expect(removePagesOption.attributes('aria-disabled')).toBe('true')
		const radioInput = removePagesOption.find('input[type="radio"]')
		expect(radioInput.attributes('disabled')).toBeDefined()
		expect(removePagesOption.text()).toContain('Only possible if at least one page remains in the original PDF.')

		await removePagesOption.trigger('click')
		expect(wrapper.emitted('update:modelValue')).toBeFalsy()
	})

	it('disables delete-song option when attachedFilesCount is not 1', async () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 2,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		const deleteSongOption = options[3]

		expect(deleteSongOption.attributes('aria-disabled')).toBe('true')
		const radioInput = deleteSongOption.find('input[type="radio"]')
		expect(radioInput.attributes('disabled')).toBeDefined()

		// Clicking disabled option should NOT emit update
		await deleteSongOption.trigger('click')
		expect(wrapper.emitted('update:modelValue')).toBeFalsy()
	})

	it('enables delete-song option when attachedFilesCount is 1', async () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		const deleteSongOption = options[3]

		expect(deleteSongOption.attributes('aria-disabled')).toBe('false')
		await deleteSongOption.trigger('click')

		expect(wrapper.emitted('update:modelValue')).toBeTruthy()
		expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe('delete-song')
	})

	it('supports keyboard activation on enabled remove-pages and ignores keyboard when disabled', async () => {
		const enabledWrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
				canRemovePages: true,
			},
		})
		const enabledRemoveOption = enabledWrapper.findAll('[role="radio"]')[1]
		await enabledRemoveOption.trigger('keydown.enter')
		await enabledRemoveOption.trigger('keydown.space')
		expect(enabledWrapper.emitted('update:modelValue')).toEqual([
			['remove-pages'],
			['remove-pages'],
		])

		const disabledWrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
				canRemovePages: false,
			},
		})
		const disabledRemoveOption = disabledWrapper.findAll('[role="radio"]')[1]
		await disabledRemoveOption.trigger('keydown.enter')
		await disabledRemoveOption.trigger('keydown.space')
		expect(disabledWrapper.emitted('update:modelValue')).toBeFalsy()
	})
})

