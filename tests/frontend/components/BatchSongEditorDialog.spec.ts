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
	const genres = [{ id: 2, name: 'Classical' }]
	const keys = [{ id: 3, name: 'C Major' }]
	const customGroups = [{ id: 4, name: 'Band' }]
	const years = [{ id: 8, name: '1970', year: 1970 }]
	const signatures = [{ id: 9, name: '4/4' }]

	it('renders dialog with multi-song editing options', () => {
		const wrapper = mount(BatchSongEditorDialog, {
			props: {
				open: true,
				selectedCount: 3,
				composers,
				books,
				collections,
				artists,
				genres,
				keys,
				customGroups,
				years,
				signatures,
			},
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Batch edit 3 songs')
		expect(document.body.textContent).toContain('Composers')
		expect(document.body.textContent).toContain('Books')
		expect(document.body.textContent).toContain('Collections')
		expect(document.body.textContent).toContain('Artists')
		expect(document.body.textContent).toContain('Genres')
		expect(document.body.textContent).toContain('Keys')
		expect(document.body.textContent).toContain('Custom Groups')
		expect(document.body.textContent).toContain('Years')
		expect(document.body.textContent).toContain('Time Signatures')
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
				genres,
				keys,
				customGroups,
				years,
				signatures,
			},
			attachTo: document.body,
		})

		const checkboxes = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(checkboxes.length).toBe(12)

		// Enable composers and books
		await checkboxes[0].setValue(true)
		await checkboxes[1].setValue(true)
		await wrapper.vm.$nextTick()

		const applyButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Apply to 3 songs'))
		expect(applyButton?.props('disabled')).toBe(false)

		await applyButton?.trigger('click')
		expect(wrapper.emitted('apply')?.[0]).toEqual([{
			composers: { action: 'add', ids: [] },
			books: { action: 'add', ids: [] },
		}])
	})

	it('emits update:open false when cancelled or closed', async () => {
		const wrapper = mount(BatchSongEditorDialog, {
			props: {
				open: true,
				selectedCount: 2,
				composers,
				books,
				collections,
				artists,
			},
			attachTo: document.body,
		})

		const cancelButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Cancel'))
		await cancelButton?.trigger('click')
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
	})
})
