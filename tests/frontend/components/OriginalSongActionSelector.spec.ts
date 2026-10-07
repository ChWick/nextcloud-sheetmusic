import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OriginalSongActionSelector from '../../../src/components/song-pdf/OriginalSongActionSelector.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((_app: string, text: string, vars?: Record<string, any>) => {
			if (!vars) return text
			let res = text
			for (const [k, v] of Object.entries(vars)) {
				res = res.replace(`{${k}}`, String(v))
			}
			return res
		}),
	}
})

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
	it('renders three options and indicates active option', () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 1,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		expect(options).toHaveLength(3)

		// Option c (keep) should be active
		expect(options[0].attributes('aria-checked')).toBe('true')
		expect(options[1].attributes('aria-checked')).toBe('false')
		expect(options[2].attributes('aria-checked')).toBe('false')
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

		expect(wrapper.emitted('update:modelValue')).toBeTruthy()
		expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe('delete-file')
	})

	it('disables delete-song option when attachedFilesCount is not 1', async () => {
		const wrapper = mount(OriginalSongActionSelector, {
			props: {
				modelValue: 'keep',
				attachedFilesCount: 2,
			},
		})

		const options = wrapper.findAll('[role="radio"]')
		const deleteSongOption = options[2]

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
		const deleteSongOption = options[2]

		expect(deleteSongOption.attributes('aria-disabled')).toBe('false')
		await deleteSongOption.trigger('click')

		expect(wrapper.emitted('update:modelValue')).toBeTruthy()
		expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe('delete-song')
	})
})

