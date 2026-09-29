import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import BatchSongEditorDialog from '../../../src/components/song-editor/BatchSongEditorDialog.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string, vars?: Record<string, any>) => {
		if (!vars) return text
		let res = text
		for (const [k, v] of Object.entries(vars)) {
			res = res.replace(`{${k}}`, String(v))
		}
		return res
	},
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('BatchSongEditorDialog.vue', () => {
	const composers = [{ id: 1, name: 'Mozart' }]
	const books = [{ id: 10, title: 'Real Book 1' }]
	const collections = [{ id: 100, name: 'Jazz' }]
	const artists = [{ id: 5, name: 'Miles Davis' }]

	it('renders dialog with multi-song editing options', () => {
		const wrapper = mount(BatchSongEditorDialog, {
			props: {
				open: true,
				selectedCount: 3,
				composers,
				books,
				collections,
				artists,
			},
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Batch edit 3 songs')
		expect(document.body.textContent).toContain('Composers')
		expect(document.body.textContent).toContain('Books')
		expect(document.body.textContent).toContain('Collections')
		expect(document.body.textContent).toContain('Artists')
	})

	it('toggles field checkboxes and emits apply payload', async () => {
		const wrapper = mount(BatchSongEditorDialog, {
			props: {
				open: true,
				selectedCount: 3,
				composers,
				books,
				collections,
				artists,
			},
			attachTo: document.body,
		})

		const checkbox = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(checkbox.exists()).toBe(true)

		await checkbox.setValue(true)
		await wrapper.vm.$nextTick()

		const applyButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Apply to 3 songs'))
		expect(applyButton?.props('disabled')).toBe(false)

		await applyButton?.trigger('click')
		expect(wrapper.emitted('apply')).toBeTruthy()
	})
})
