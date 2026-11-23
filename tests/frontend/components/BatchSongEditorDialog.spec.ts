import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import BatchSongEditorDialog from '../../../src/components/song-editor/BatchSongEditorDialog.vue'
import { ENTITY_COLUMNS, SONG_SCALAR_FIELDS } from '../../../src/components/songs/song-view-columns'

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

	it('renders dialog with all entity and scalar editing options', () => {
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
		for (const col of ENTITY_COLUMNS) {
			expect(document.body.textContent).toContain(col.pluralLabel())
		}
		for (const field of SONG_SCALAR_FIELDS) {
			expect(document.body.textContent).toContain(field.label())
		}
		wrapper.unmount()
	})

	it('toggles entity and scalar field checkboxes and emits apply payload', async () => {
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
		expect(checkboxes.length).toBe(ENTITY_COLUMNS.length + SONG_SCALAR_FIELDS.length)

		// Enable composers and books
		await checkboxes[0].setValue(true)
		await checkboxes[1].setValue(true)

		// Enable opus and duration scalar fields
		const opusIndex = ENTITY_COLUMNS.length + SONG_SCALAR_FIELDS.findIndex((f) => f.key === 'opus')
		const durationIndex = ENTITY_COLUMNS.length + SONG_SCALAR_FIELDS.findIndex((f) => f.key === 'duration')
		await checkboxes[opusIndex].setValue(true)
		await checkboxes[durationIndex].setValue(true)
		await wrapper.vm.$nextTick()

		const opusInput = document.body.querySelector('#batch-opus-input') as HTMLInputElement
		const durationInput = document.body.querySelector('#batch-duration-input') as HTMLInputElement
		expect(opusInput).toBeTruthy()
		expect(durationInput).toBeTruthy()

		opusInput.value = 'Op. 9'
		opusInput.dispatchEvent(new Event('input', { bubbles: true }))
		durationInput.value = '210'
		durationInput.dispatchEvent(new Event('input', { bubbles: true }))
		await wrapper.vm.$nextTick()

		const applyButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Apply to 3 songs'))
		expect(applyButton?.props('disabled')).toBe(false)

		await applyButton?.trigger('click')
		expect(wrapper.emitted('apply')?.[0]).toEqual([{
			composers: { action: 'add', ids: [] },
			books: { action: 'add', ids: [] },
			scalars: {
				opus: 'Op. 9',
				duration: 210,
			},
		}])
		wrapper.unmount()
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
		wrapper.unmount()
	})

	it('renders progress bar and disables controls while applying', () => {
		const wrapper = mount(BatchSongEditorDialog, {
			props: {
				open: true,
				selectedCount: 5,
				isApplying: true,
				progressPercent: 60,
				progressMessage: 'Updating song 3 of 5: Autumn Leaves',
				composers,
				books,
				collections,
				artists,
			},
			attachTo: document.body,
		})

		const progressEl = document.body.querySelector('[data-test="batch-edit-progress"]')
		expect(progressEl).toBeTruthy()
		expect(progressEl?.textContent).toContain('Updating song 3 of 5: Autumn Leaves')

		const applyButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Applying…'))
		expect(applyButton?.props('disabled')).toBe(true)

		const cancelButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Cancel'))
		expect(cancelButton?.props('disabled')).toBe(true)
		wrapper.unmount()
	})

	it('allows tagging new entities for add/replace and strips unsaved tags when switching to remove', async () => {
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

		const checkboxes = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		await checkboxes[0].setValue(true) // composers
		await checkboxes[1].setValue(true) // books
		await wrapper.vm.$nextTick()

		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects).toHaveLength(2)
		expect(selects[0].props('taggable')).toBe(true)
		expect(selects[1].props('taggable')).toBe(true)

		const createComposer = selects[0].props('createOption') as (label: string) => any
		const createBook = selects[1].props('createOption') as (label: string) => any

		// Existing entity (case-insensitive) reuses existing object with id
		expect(createComposer('mozart')).toEqual({ id: 1, name: 'Mozart' })
		// New entity creates tag object without id
		const newComposer = createComposer('Chopin')
		const newBook = createBook('New Fake Book')
		expect(newComposer).toEqual({ name: 'Chopin' })
		expect(newBook).toEqual({ title: 'New Fake Book' })

		// Select both existing and newly created tags
		await selects[0].vm.$emit('update:modelValue', [composers[0], newComposer])
		await selects[1].vm.$emit('update:modelValue', [newBook])
		await wrapper.vm.$nextTick()

		const applyButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Apply to 2 songs'))
		await applyButton?.trigger('click')

		expect(wrapper.emitted('apply')?.[0]).toEqual([{
			composers: { action: 'add', ids: [1], newLabels: ['Chopin'] },
			books: { action: 'add', ids: [], newLabels: ['New Fake Book'] },
		}])

		// Switching composers action to 'remove' disables taggable and drops unsaved tags
		const removeRadio = document.body.querySelector('input[type="radio"][value="remove"]') as HTMLInputElement
		expect(removeRadio).toBeTruthy()
		removeRadio.checked = true
		removeRadio.dispatchEvent(new Event('change', { bubbles: true }))
		await wrapper.vm.$nextTick()

		expect(selects[0].props('taggable')).toBe(false)
		await applyButton?.trigger('click')
		expect(wrapper.emitted('apply')?.[1]).toEqual([{
			composers: { action: 'remove', ids: [1] },
			books: { action: 'add', ids: [], newLabels: ['New Fake Book'] },
		}])

		wrapper.unmount()
	})
})
