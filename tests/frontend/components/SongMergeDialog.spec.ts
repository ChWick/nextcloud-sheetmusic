import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongMergeDialog from '../../../src/components/song-editor/SongMergeDialog.vue'
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
	artists: [],
	genres: [],
	keys: [],
	keywords: 'organ, postludium',
	files: [{ id: 207, path: '/remote.php/dav/files/admin/Noten/Brosig/Nachspiel.pdf', type: 1 }],
}

const mockSongB: Song = {
	id: 61,
	title: 'Nachspiel in D',
	composers: [{ id: 1, name: 'Brosig' }],
	books: [{ id: 10, title: 'Freie Orgelmusik' }],
	collections: [{ id: 100, name: 'Grün' }],
	artists: [],
	genres: [],
	keys: [],
	keywords: 'organ',
	files: [{ id: 208, path: '/remote.php/dav/files/admin/Noten/Brosig/Nachspiel2.pdf', type: 1 }],
}

const mockLibraryData: LibraryData = {
	songs: [mockSongA, mockSongB],
	composers: [{ id: 1, name: 'Brosig' }],
	books: [{ id: 10, title: 'Freie Orgelmusik' }],
	collections: [{ id: 100, name: 'Grün' }],
	artists: [],
	genres: [],
	keys: [],
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

const globalStubs = {
	global: {
		stubs: {
			NcDialog: {
				template: '<div class="nc-dialog-stub"><slot /></div>',
				props: ['name', 'open', 'size'],
			},
			NcButton: {
				template: '<button :class="variant" :title="title" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
				props: ['variant', 'title'],
			},
			NcTextField: {
				template: '<div class="nc-textfield-stub"><input :value="value" @input="$emit(\'update:value\', $event.target.value)" /></div>',
				props: ['value', 'label'],
			},
			NcSelect: {
				template: '<div class="nc-select-stub"></div>',
				props: ['value', 'options'],
			},
		},
	},
}

describe('SongMergeDialog.vue', () => {
	it('renders dialog and pre-fills agreeing fields while leaving differing fields empty', () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Song A')
		expect(wrapper.text()).toContain('Song B')
		expect(wrapper.text()).toContain('Merged Result')
	})

	it('emits merge event when submitting in Mode 1', async () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
			},
			...globalStubs,
		})

		const useTitleABtn = wrapper.findAll('button').find(b => b.attributes('title')?.includes('Use Song A Title'))
		expect(useTitleABtn).toBeDefined()
		await useTitleABtn?.trigger('click')

		const mergeButton = wrapper.findAll('button').find(b => b.text().includes('Merge Songs'))
		expect(mergeButton).toBeDefined()
		await mergeButton?.trigger('click')

		const mergeEmits = wrapper.emitted('merge')
		expect(mergeEmits).toBeDefined()
		expect(mergeEmits?.[0]?.[0]).toMatchObject({
			keepId: 60,
			droppedId: 61,
			merged: {
				title: 'Nachspiel in G',
			},
		})
	})

	it('switches to Mode 2 (Keep both songs) and emits resolveBoth', async () => {
		const wrapper = mount(SongMergeDialog, {
			props: {
				open: true,
				issue: mockIssue,
				libraryData: mockLibraryData,
			},
			...globalStubs,
		})

		const buttons = wrapper.findAll('button')
		const keepBothBtn = buttons.find(b => b.text().includes('Keep as 2 Separate Songs'))
		expect(keepBothBtn).toBeDefined()
		await keepBothBtn?.trigger('click')

		expect(wrapper.text()).toContain('Keep both songs in your library as separate entries')

		const updatedButtons = wrapper.findAll('button')
		const saveBothBtn = updatedButtons.find(b => b.text().includes('Save & Resolve Conflicts'))
		expect(saveBothBtn).toBeDefined()
		await saveBothBtn?.trigger('click')

		const resolveEmits = wrapper.emitted('resolveBoth')
		expect(resolveEmits).toBeDefined()
		expect(resolveEmits?.[0]?.[0]).toMatchObject({
			songA: { id: 60, title: 'Nachspiel in G' },
			songB: { id: 61, title: 'Nachspiel in D' },
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
			...globalStubs,
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
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Conflict cannot be resolved: Song A and Song B still share the same physical file or path in storage.')
	})
})
