import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongMergeDialog from '../../../src/components/song-editor/SongMergeDialog.vue'
import { ENTITY_COLUMNS, SONG_SCALAR_FIELDS } from '../../../src/components/song-editor/song-merge-utils'
import type { IntegrityIssue, LibraryData, Song } from '../../../src/types/song'

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

const mockSongA: Song = {
	id: 60,
	title: 'Nachspiel in G',
	composers: [{ id: 1, name: 'Brosig' }],
	books: [{ id: 10, title: 'Freie Orgelmusik' }],
	collections: [{ id: 100, name: 'Grün' }],
	artists: [{ id: 201, name: 'Organist A' }],
	genres: [{ id: 301, name: 'Romantic' }],
	keys: [{ id: 401, name: 'G Major' }],
	customGroups: [{ id: 501, name: 'Choir A' }],
	years: [{ id: 601, year: 1880, name: '1880' }],
	signatures: [{ id: 701, name: '4/4' }],
	sourceTypes: [{ id: 801, type: 'Score', name: 'Score' }],
	instruments: [{ id: 901, name: 'Organ' }],
	arrangers: [{ id: 1001, name: 'Karl Straube' }],
	opus: 'Op. 12',
	catalogNumber: 'MB 12',
	songNumber: '7',
	stars: 4,
	difficulty: 3,
	duration: 210,
	keywords: 'organ, postludium',
	custom: 'Pedal solo',
	custom2: 'Manual II',
	files: [{ id: 207, path: '/remote.php/dav/files/admin/Noten/Brosig/Nachspiel.pdf', type: 1 }],
}

const mockSongB: Song = {
	id: 61,
	title: 'Nachspiel in D',
	composers: [{ id: 1, name: 'Brosig' }],
	books: [{ id: 10, title: 'Freie Orgelmusik' }],
	collections: [{ id: 100, name: 'Grün' }],
	artists: [],
	genres: [{ id: 302, name: 'Liturgical' }],
	keys: [{ id: 402, name: 'D Major' }],
	customGroups: [],
	years: [{ id: 602, year: 1885, name: '1885' }],
	signatures: [{ id: 702, name: '3/4' }],
	sourceTypes: [],
	instruments: [{ id: 902, name: 'Harmonium' }],
	arrangers: [{ id: 1002, name: 'Max Reger' }],
	opus: 'Op. 15',
	catalogNumber: 'MB 15',
	songNumber: '9',
	stars: 5,
	difficulty: 4,
	duration: 185,
	keywords: 'organ',
	custom: 'Festival',
	custom2: 'Manual I',
	files: [{ id: 208, path: '/remote.php/dav/files/admin/Noten/Brosig/Nachspiel2.pdf', type: 1 }],
}

const mockLibraryData: LibraryData = {
	songs: [mockSongA, mockSongB],
	composers: [{ id: 1, name: 'Brosig' }],
	books: [{ id: 10, title: 'Freie Orgelmusik' }],
	collections: [{ id: 100, name: 'Grün' }],
	artists: [{ id: 201, name: 'Organist A' }],
	genres: [{ id: 301, name: 'Romantic' }, { id: 302, name: 'Liturgical' }],
	keys: [{ id: 401, name: 'G Major' }, { id: 402, name: 'D Major' }],
	customGroups: [{ id: 501, name: 'Choir A' }],
	years: [{ id: 601, year: 1880, name: '1880' }, { id: 602, year: 1885, name: '1885' }],
	signatures: [{ id: 701, name: '4/4' }, { id: 702, name: '3/4' }],
	sourceTypes: [{ id: 801, type: 'Score', name: 'Score' }],
	instruments: [{ id: 901, name: 'Organ' }, { id: 902, name: 'Harmonium' }],
	arrangers: [{ id: 1001, name: 'Karl Straube' }, { id: 1002, name: 'Max Reger' }],
}

const mockIssue: IntegrityIssue = {
	type: 'duplicate_file_path',
	severity: 'error',
	message: 'Multiple database entries point to the same file path',
	fixable: false,
	details: {
		songIds: [60, 61],
		exactDuplicate: false,
	},
}

// Only stub NcDialog so it renders inline without portal/teleport; keep real NcTextField, NcSelect, NcButton, NcCheckboxRadioSwitch!
const realUiMountOptions = {
	global: {
		stubs: {
			NcDialog: {
				template: '<div class="nc-dialog-stub"><slot /></div>',
				props: ['name', 'open', 'size'],
			},
		},
	},
}

describe('SongMergeDialog.vue (with real @nextcloud/vue controls)', () => {
	it('renders all entity types and scalar fields from single source of truth in Mode 1', () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
			},
			...realUiMountOptions,
		})

		expect(wrapper.text()).toContain('Song A')
		expect(wrapper.text()).toContain('Song B')
		expect(wrapper.text()).toContain('Merged Result')

		// Every entity in ENTITY_COLUMNS must be rendered
		for (const col of ENTITY_COLUMNS) {
			expect(wrapper.find(`[data-test="merged-field-${col.songProp}"]`).exists()).toBe(true)
			expect(wrapper.find(`[data-test="copy-from-a-${col.songProp}"]`).exists()).toBe(true)
			expect(wrapper.find(`[data-test="copy-from-b-${col.songProp}"]`).exists()).toBe(true)
		}

		// Every scalar in SONG_SCALAR_FIELDS must be rendered
		for (const field of SONG_SCALAR_FIELDS) {
			expect(wrapper.find(`[data-test="merged-field-${field.key}"]`).exists()).toBe(true)
			expect(wrapper.find(`[data-test="copy-from-a-${field.key}"]`).exists()).toBe(true)
			expect(wrapper.find(`[data-test="copy-from-b-${field.key}"]`).exists()).toBe(true)
		}

		// Agreeing entities (Composers: Brosig) are pre-filled in the real NcSelect DOM
		expect(wrapper.find('[data-test="merged-field-composers"]').text()).toContain('Brosig')
		// Differing title starts empty in the real NcTextField <input>
		const titleInput = wrapper.find('input#merge-title-input').element as HTMLInputElement
		expect(titleInput.value).toBe('')
	})

	it('updates real NcTextField and NcSelect DOM values when clicking arrow buttons and emits full merge payload', async () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
			},
			...realUiMountOptions,
		})

		// Click arrow to copy Title from Song A -> real input element updates
		await wrapper.find('[data-test="copy-from-a-title"]').trigger('click')
		const titleInput = wrapper.find('input#merge-title-input').element as HTMLInputElement
		expect(titleInput.value).toBe('Nachspiel in G')

		// Click arrow to copy Arrangers from Song B -> real NcSelect shows Max Reger
		await wrapper.find('[data-test="copy-from-b-arrangers"]').trigger('click')
		expect(wrapper.find('[data-test="merged-field-arrangers"]').text()).toContain('Max Reger')

		// Click arrow to copy Duration from Song A -> real duration input updates to "210"
		await wrapper.find('[data-test="copy-from-a-duration"]').trigger('click')
		const durationInput = wrapper.find('input#merge-duration-input').element as HTMLInputElement
		expect(durationInput.value).toBe('210')

		// Click arrow to copy Stars from Song B -> real stars input updates to "5"
		await wrapper.find('[data-test="copy-from-b-stars"]').trigger('click')
		const starsInput = wrapper.find('input#merge-stars-input').element as HTMLInputElement
		expect(starsInput.value).toBe('5')

		// Click Merge Songs button
		await wrapper.find('[data-test="submit-merge-btn"]').trigger('click')

		const mergeEmits = wrapper.emitted('merge')
		expect(mergeEmits).toBeDefined()
		expect(mergeEmits?.[0]?.[0]).toMatchObject({
			keepId: 60,
			droppedId: 61,
			merged: {
				title: 'Nachspiel in G',
				composerIds: [1],
				bookIds: [10],
				collectionIds: [100],
				arrangerIds: [1002],
				duration: 210,
				stars: 5,
				difficulty: null,
			},
		})
	})

	it('switches to Mode 2 (Keep both songs), renders all entities & scalars, copies fields via arrows, and emits resolveBoth', async () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
			},
			...realUiMountOptions,
		})

		await wrapper.find('[data-test="mode-keep-both-btn"]').trigger('click')
		expect(wrapper.text()).toContain('Keep both songs in your library as separate entries')

		// Every entity and scalar is rendered for both Song A and Song B
		for (const col of ENTITY_COLUMNS) {
			expect(wrapper.find(`[data-test="field-a-${col.songProp}"]`).exists()).toBe(true)
			expect(wrapper.find(`[data-test="field-b-${col.songProp}"]`).exists()).toBe(true)
		}
		for (const field of SONG_SCALAR_FIELDS) {
			expect(wrapper.find(`[data-test="field-a-${field.key}"]`).exists()).toBe(true)
			expect(wrapper.find(`[data-test="field-b-${field.key}"]`).exists()).toBe(true)
		}

		// Copy Song A duration (210) to Song B via per-field arrow
		await wrapper.find('[data-test="copy-a-to-b-duration"]').trigger('click')
		const durationBInput = wrapper.find('input#resolve-b-duration-input').element as HTMLInputElement
		expect(durationBInput.value).toBe('210')

		// Copy Song B arrangers (Max Reger) to Song A via per-field arrow
		await wrapper.find('[data-test="copy-b-to-a-arrangers"]').trigger('click')
		expect(wrapper.find('[data-test="field-a-arrangers"]').text()).toContain('Max Reger')

		await wrapper.find('[data-test="submit-resolve-both-btn"]').trigger('click')

		const resolveEmits = wrapper.emitted('resolveBoth')
		expect(resolveEmits).toBeDefined()
		expect(resolveEmits?.[0]?.[0]).toMatchObject({
			songA: {
				id: 60,
				title: 'Nachspiel in G',
				arrangerIds: [1002],
				instrumentIds: [901],
				duration: 210,
				stars: 4,
				difficulty: 3,
				opus: 'Op. 12',
			},
			songB: {
				id: 61,
				title: 'Nachspiel in D',
				arrangerIds: [1002],
				instrumentIds: [902],
				duration: 210,
				stars: 5,
				difficulty: 4,
				opus: 'Op. 15',
			},
		})
	})

	it('initializes to initialMode when initialMode prop is keepBoth', () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
				initialMode: 'keepBoth',
			},
			...realUiMountOptions,
		})

		expect(wrapper.text()).toContain('Keep both songs in your library as separate entries')
	})

	it('renders server error banner when error prop is provided', () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
				error: 'Conflict cannot be resolved: Song A and Song B still share the same physical file or path in storage.',
			},
			...realUiMountOptions,
		})

		expect(wrapper.text()).toContain('Conflict cannot be resolved: Song A and Song B still share the same physical file or path in storage.')
	})
})
