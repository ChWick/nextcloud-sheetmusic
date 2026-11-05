import type { Artist, Book, Collection, Composer, Song } from '../../../src/types/song'

import axios from '@nextcloud/axios'
import { getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SongEditor from '../../../src/components/SongEditor.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string, params?: Record<string, any>) => {
		let res = path
		if (params) {
			for (const [k, v] of Object.entries(params)) {
				res = res.replace(`{${k}}`, String(v))
			}
		}
		return `/ocs/v2.php${res}`
	}),
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((app: string, text: string, vars?: Record<string, any>) => {
			if (!vars) return text
			let res = text
			for (const [k, v] of Object.entries(vars)) {
				res = res.replace(`{${k}}`, String(v))
			}
			return res
		}),
		getLanguage: vi.fn(() => 'en'),
		isRTL: vi.fn(() => false),
	}
})

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class FilePickerClosed extends Error {},
	getFilePickerBuilder: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showInfo: vi.fn(),
}))

describe('SongEditor.vue', () => {
	const mockComposers: Composer[] = [{ id: 1, name: 'Ludwig van Beethoven' }]
	const mockBooks: Book[] = [{ id: 10, title: 'Real Book 1' }]
	const mockCollections: Collection[] = [{ id: 100, name: 'Classical Favorites' }]
	const mockArtists: Artist[] = [{ id: 1000, name: 'Berliner Philharmoniker' }]

	const mockExistingSong: Song = {
		id: 5,
		title: 'Symphony No. 5',
		composers: [mockComposers[0]],
		books: [mockBooks[0]],
		collections: [mockCollections[0]],
		artists: [mockArtists[0]],
		files: [{ id: 50, path: '/dav/files/admin/Noten/Beethoven - Symphony 5.pdf' }],
	}

	const globalStubs = {
		global: {
			stubs: {
				NcButton: {
					template: '<button :disabled="disabled" :type="type" :title="title" :aria-label="ariaLabel" :data-variant="variant"><slot name="icon" /><slot /></button>',
					props: ['disabled', 'type', 'variant', 'title', 'ariaLabel'],
				},
				NcTextField: {
					template: '<input :id="id" :placeholder="placeholder" :value="value !== undefined ? value : modelValue" @input="$emit(\'update:value\', $event.target.value); $emit(\'update:modelValue\', $event.target.value)" />',
					props: ['id', 'value', 'modelValue', 'placeholder', 'required'],
				},
				NcSelect: {
					template: '<div class="nc-select-stub"></div>',
					props: ['modelValue', 'options', 'label', 'createOption'],
				},
				NcLoadingIcon: {
					template: '<div class="nc-loading-stub"></div>',
				},
				NcDialog: {
					template: '<div class="nc-dialog-stub"><slot /></div>',
				},
				UploadIcon: {
					template: '<span class="upload-icon-stub" />',
				},
				FolderIcon: {
					template: '<span class="folder-icon-stub" />',
				},
			},
		},
	}

	beforeEach(() => {
		vi.resetAllMocks()
	})

	it('renders title input for New Song when song prop is null', () => {
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		const input = wrapper.find('input#song-title-input')
		expect(input.exists()).toBe(true)
		expect(input.attributes('placeholder')).toBe('New Song')
	})

	it('shows upload and Nextcloud attach actions in Attached Files', () => {
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		const uploadButton = wrapper.get('button[aria-label="Upload file from computer"]')
		const nextcloudButton = wrapper.get('button[aria-label="Choose file from Nextcloud"]')
		expect(uploadButton.text()).toContain('Upload')
		expect(nextcloudButton.text()).toContain('Choose from Nextcloud')
		expect(uploadButton.classes().some((className) => className.includes('fileActionButton'))).toBe(true)
		expect(nextcloudButton.classes().some((className) => className.includes('fileActionButton'))).toBe(true)
		expect(uploadButton.find('.upload-icon-stub').exists()).toBe(true)
		expect(nextcloudButton.find('.folder-icon-stub').exists()).toBe(true)
	})

	it('keeps files visible in the Nextcloud attachment picker', async () => {
		const picker = { pickNodes: vi.fn().mockResolvedValue([]) }
		let canPickPredicate: ((node: { type: string }) => boolean) | undefined
		const builder = {
			setMultiSelect: vi.fn(() => builder),
			setCanPick: vi.fn((predicate: (node: { type: string }) => boolean) => {
				canPickPredicate = predicate
				return builder
			}),
			setButtonFactory: vi.fn(() => builder),
			build: vi.fn(() => picker),
		}
		vi.mocked(getFilePickerBuilder).mockReturnValue(builder as never)

		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		await (wrapper.vm as unknown as { openNextcloudPicker: () => Promise<void> }).openNextcloudPicker()

		expect(builder.setCanPick).toHaveBeenCalledOnce()
		expect(canPickPredicate?.({ type: 'file' })).toBe(true)
		expect(canPickPredicate?.({ type: 'folder' })).toBe(false)
		expect(picker.pickNodes).toHaveBeenCalledOnce()
	})

	it('creates a new song from the accessible, colored Create icon button', async () => {
		vi.mocked(axios.post).mockResolvedValue({
			data: { ocs: { data: { songs: [], createdSongId: 99 } } },
		})
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		await wrapper.find('input#song-title-input').setValue('New Song')

		const cancelButton = wrapper.get('button[aria-label="Cancel"]')
		const createButton = wrapper.get('button[aria-label="Create song"]')
		expect(cancelButton.attributes('data-variant')).toBe('secondary')
		expect(createButton.attributes('data-variant')).toBe('primary')
		expect(createButton.attributes('type')).toBe('button')
		await createButton.trigger('click')

		expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/sheetmusic/api/songs', expect.objectContaining({
			libraryId: 1,
			title: 'New Song',
		}))
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('reuses existing entities when a typed tag matches their label', async () => {
		vi.mocked(axios.post).mockResolvedValue({
			data: { ocs: { data: { songs: [], createdSongId: 99 } } },
		})
		const entityOptions = {
			composers: mockComposers,
			books: mockBooks,
			collections: mockCollections,
			artists: mockArtists,
			genres: [{ id: 2, name: 'Jazz' }],
			keys: [{ id: 3, name: 'C' }],
			customGroups: [{ id: 4, name: 'Favorites' }],
			years: [{ id: 5, year: 2020, name: '2020' }],
			signatures: [{ id: 6, name: '4/4' }],
			sourceTypes: [{ id: 7, type: 'Digital', name: 'Digital' }],
			instruments: [{ id: 8, name: 'Piano' }],
			arrangers: [{ id: 9, name: 'Quincy Jones' }],
		}
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				...entityOptions,
			},
			global: {
				stubs: {
					...globalStubs.global.stubs,
					NcSelect: {
						template: '<button class="create-matching-tag" @click="$emit(\'update:modelValue\', [createOption(\'  \' + options[0][label].toLowerCase() + \'  \')])">{{ label }}</button>',
						props: ['modelValue', 'options', 'label', 'createOption'],
					},
				},
			},
		})

		await wrapper.find('input#song-title-input').setValue('Song with existing entities')
		for (const selector of wrapper.findAll('.create-matching-tag')) {
			await selector.trigger('click')
		}
		await wrapper.find('form').trigger('submit.prevent')

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/sheetmusic/api/songs', expect.objectContaining({
			composerIds: [1],
			bookIds: [10],
			collectionIds: [100],
			artistIds: [1000],
			genreIds: [2],
			keyIds: [3],
			customGroupIds: [4],
			yearIds: [5],
			signatureIds: [6],
			sourceTypeIds: [7],
			instrumentIds: [8],
			arrangerIds: [9],
		}))
	})

	it('creates an entity when a typed tag does not match an existing option', async () => {
		vi.mocked(axios.post)
			.mockResolvedValueOnce({ data: { ocs: { data: { id: 77 } } } })
			.mockResolvedValueOnce({ data: { ocs: { data: { songs: [], createdSongId: 99 } } } })
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: [],
				collections: [],
				artists: [],
			},
			global: {
				stubs: {
					...globalStubs.global.stubs,
					NcSelect: {
						template: '<button v-if="label === \'name\' && options.length" class="create-new-tag" @click="$emit(\'update:modelValue\', [createOption(\'New Composer\')])">Create tag</button>',
						props: ['modelValue', 'options', 'label', 'createOption'],
					},
				},
			},
		})

		await wrapper.find('input#song-title-input').setValue('Song with a new composer')
		await wrapper.get('.create-new-tag').trigger('click')
		await wrapper.find('form').trigger('submit.prevent')

		expect(axios.post).toHaveBeenNthCalledWith(
			1,
			'/ocs/v2.php/apps/sheetmusic/api/composers',
			{ libraryId: 1, name: 'New Composer' },
		)
		expect(axios.post).toHaveBeenNthCalledWith(
			2,
			'/ocs/v2.php/apps/sheetmusic/api/songs',
			expect.objectContaining({ composerIds: [77] }),
		)
		await flushPromises()
		expect(showSuccess).toHaveBeenCalledWith('Song "Song with a new composer" created successfully.')
	})

	it('renders title input with song title when song prop is provided', () => {
		const wrapper = mount(SongEditor, {
			props: {
				song: mockExistingSong,
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		const input = wrapper.find('input#song-title-input')
		expect(input.exists()).toBe(true)
		expect((input.element as HTMLInputElement).value).toBe('Symphony No. 5')
		expect(wrapper.text()).toContain('Beethoven - Symphony 5.pdf')
	})

	it('prefills fields from prefill prop when creating a new song', () => {
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
				prefill: {
					title: 'Prefilled Title',
					composers: ['Ludwig van Beethoven'],
					books: ['New Book'],
					collections: [],
					artists: [],
				},
			},
			...globalStubs,
		})

		const titleInput = wrapper.find('input#song-title-input')
		expect((titleInput.element as HTMLInputElement).value).toBe('Prefilled Title')
	})

	it('shows error when submitting empty title', async () => {
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		await wrapper.find('form').trigger('submit.prevent')
		expect(wrapper.text()).toContain('Song title cannot be empty.')
	})

	it('stages file deletion and handles restore', async () => {
		const wrapper = mount(SongEditor, {
			props: {
				song: mockExistingSong,
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		// Find delete button on file list item
		const deleteBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Delete' || btn.attributes('aria-label')?.includes('Delete') || btn.attributes('title')?.includes('Delete'))
		expect(deleteBtn).toBeDefined()
		await deleteBtn!.trigger('click')

		// Notice should appear
		expect(wrapper.text()).toContain('1 file(s) marked for removal')

		// Click restore button
		const restoreBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Restore')
		expect(restoreBtn).toBeDefined()
		await restoreBtn!.trigger('click')

		// Notice disappears
		expect(wrapper.text()).not.toContain('1 file(s) marked for removal')
	})

	it('detaches a file on save and confirms it stays in storage', async () => {
		vi.mocked(axios.post).mockResolvedValue({
			data: { ocs: { data: { songs: [] } } },
		})

		const wrapper = mount(SongEditor, {
			props: {
				song: mockExistingSong,
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		const detachButton = wrapper.findAll('button').find((button) =>
			button.attributes('title') === 'Detach file from song (keep file in storage)',
		)
		expect(detachButton).toBeDefined()
		await detachButton!.trigger('click')

		expect(wrapper.text()).toContain('1 file(s) will be detached from the song (kept in storage)')
		await wrapper.find('form').trigger('submit.prevent')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/songs/5/file/50/detach',
			{ libraryId: 1 },
		)
		expect(wrapper.text()).toContain('1 file(s) detached and kept in storage.')
		expect(showSuccess).toHaveBeenCalledWith('Song "Symphony No. 5" updated successfully. 1 file(s) detached and kept in storage.')
	})

	it('stages uploaded computer file on file input change', async () => {
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		const fileInput = wrapper.find('input[type="file"]')
		const dummyFile = new File(['pdf content'], 'uploaded.pdf', { type: 'application/pdf' })

		Object.defineProperty(fileInput.element, 'files', {
			value: [dummyFile],
		})

		await fileInput.trigger('change')

		expect(wrapper.text()).toContain('The file is added to the song when you save.')
		expect(wrapper.text()).toContain('uploaded.pdf')
	})

	it('stages non-pdf files (e.g., .txt or .sib) on file input change', async () => {
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
			},
			...globalStubs,
		})

		const fileInput = wrapper.find('input[type="file"]')
		const txtFile = new File(['text'], 'document.txt', { type: 'text/plain' })

		Object.defineProperty(fileInput.element, 'files', {
			value: [txtFile],
		})

		await fileInput.trigger('change')

		expect(wrapper.text()).toContain('The file is added to the song when you save.')
		expect(wrapper.text()).toContain('document.txt')
	})

	it('saves new song with existing entities and staged upload', async () => {
		const mockLibraryResponse = {
			data: {
				ocs: {
					data: {
						createdSongId: 99,
						songs: [],
						composers: [],
						books: [],
						collections: [],
						artists: [],
					},
				},
			},
		}

		vi.mocked(axios.post).mockResolvedValue(mockLibraryResponse)

		const dummyFile = new File(['pdf content'], 'imported.pdf', { type: 'application/pdf' })

		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
				importedFile: dummyFile,
			},
			...globalStubs,
		})

		const titleInput = wrapper.find('input#song-title-input')
		await titleInput.setValue('New Symphony')

		await wrapper.find('form').trigger('submit.prevent')

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/songs',
			{
				libraryId: 1,
				title: 'New Symphony',
				composerIds: [],
				bookIds: [],
				collectionIds: [],
				artistIds: [],
				genreIds: [],
				keyIds: [],
				customGroupIds: [],
				yearIds: [],
				signatureIds: [],
				sourceTypeIds: [],
				instrumentIds: [],
				arrangerIds: [],
				opus: '',
				catalogNumber: '',
				songNumber: '',
				stars: null,
				difficulty: null,
				duration: null,
				keywords: '',
				custom: '',
				custom2: '',
				notes: [],
				tempos: [],
				bookmarks: [],
				audioFiles: [],
			},
		)

		// Upload endpoint call
		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/songs/99/file/upload',
			expect.any(FormData),
		)

		expect(wrapper.emitted('saved')).toBeTruthy()
		expect(wrapper.emitted('saved')![0][1]).toBe(99)
	})

	it('duplicates song metadata into a new song without copying attached files', async () => {
		vi.mocked(axios.post).mockResolvedValue({
			data: { ocs: { data: { songs: [], createdSongId: 99 } } },
		})
		const duplicateSong = {
			...mockExistingSong,
			genres: [{ id: 2, name: 'Classical' }],
			keys: [{ id: 3, name: 'C' }],
			customGroups: [{ id: 4, name: 'Practice' }],
			years: [{ id: 5, year: 1808, name: '1808' }],
			signatures: [{ id: 6, name: '4/4' }],
			sourceTypes: [{ id: 7, type: 'Score', name: 'Score' }],
			stars: 4,
			difficulty: 3,
			duration: 240,
			keywords: 'allegro',
			custom: 'Opus 5',
			custom2: 'No. 2',
			tempos: [{ tempo: 120 }],
			notes: [{ id: 51, note: 'Practice slowly', title: 'Practice' }],
			bookmarks: [{ id: 52, songId: 5, name: 'Coda', pageNum: 8 }],
			audioFiles: [{ id: 53, songId: 5, title: 'Backing', file: 'backing.mp3' }],
		} as Song
		const wrapper = mount(SongEditor, {
			props: {
				libraryId: 1,
				duplicateSong,
				composers: mockComposers,
				books: mockBooks,
				collections: mockCollections,
				artists: mockArtists,
				genres: duplicateSong.genres,
				keys: duplicateSong.keys,
				customGroups: duplicateSong.customGroups,
				years: duplicateSong.years,
				signatures: duplicateSong.signatures,
				sourceTypes: duplicateSong.sourceTypes,
			},
			...globalStubs,
		})

		expect(wrapper.find('input#song-title-input').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('Beethoven - Symphony 5.pdf')
		await wrapper.find('form').trigger('submit.prevent')

		expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/sheetmusic/api/songs', expect.objectContaining({
			libraryId: 1,
			title: 'Symphony No. 5',
			composerIds: [1],
			bookIds: [10],
			collectionIds: [100],
			artistIds: [1000],
			genreIds: [2],
			keyIds: [3],
			customGroupIds: [4],
			yearIds: [5],
			signatureIds: [6],
			sourceTypeIds: [7],
			stars: 4,
			difficulty: 3,
			duration: 240,
			keywords: 'allegro',
			custom: 'Opus 5',
			custom2: 'No. 2',
			notes: [{ note: 'Practice slowly', title: 'Practice' }],
			tempos: [120],
			bookmarks: [{ name: 'Coda', pageNum: 8, endPage: null }],
			audioFiles: [{ title: 'Backing', file: 'backing.mp3' }],
		}))
		expect(axios.post).toHaveBeenCalledTimes(1)
	})

	it('binds and submits song attributes (stars, difficulty, duration, keywords, custom)', async () => {
		const wrapper = mount(SongEditor, {
			props: {
				song: {
					id: 10,
					title: 'Existing Song',
					stars: 4,
					difficulty: 3,
					duration: 240,
					keywords: 'allegro',
					custom: 'Opus 5',
					custom2: 'No. 2',
					files: [],
					composers: [],
					books: [],
					collections: [],
					artists: [],
				},
				libraryId: 1,
				composers: [],
				books: [],
				collections: [],
				artists: [],
			},
			...globalStubs,
		})

		const starsInput = wrapper.find('input#song-rating-input')
		expect((starsInput.element as HTMLInputElement).value).toBe('4')
		await starsInput.setValue('5')

		const keywordsInput = wrapper.find('input#song-keywords-input')
		expect((keywordsInput.element as HTMLInputElement).value).toBe('allegro')
		await keywordsInput.setValue('allegro, vivace')

		await wrapper.find('form').trigger('submit.prevent')

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/songs/10',
			{
				libraryId: 1,
				title: 'Existing Song',
				composerIds: [],
				bookIds: [],
				collectionIds: [],
				artistIds: [],
				genreIds: [],
				keyIds: [],
				customGroupIds: [],
				yearIds: [],
				signatureIds: [],
				sourceTypeIds: [],
				instrumentIds: [],
				arrangerIds: [],
				opus: '',
				catalogNumber: '',
				songNumber: '',
				stars: 5,
				difficulty: 3,
				duration: 240,
				keywords: 'allegro, vivace',
				custom: 'Opus 5',
				custom2: 'No. 2',
				notes: [],
				tempos: [],
				bookmarks: [],
				audioFiles: [],
			},
		)
	})

	it('renders both Save without closing and Save and Close buttons with proper titles for an existing song', async () => {
		const wrapper = mount(SongEditor, {
			props: {
				song: mockExistingSong,
				libraryId: 1,
				composers: [],
				books: [],
				collections: [],
				artists: [],
			},
			...globalStubs,
		})

		const buttons = wrapper.findAll('button')
		const saveAndCloseBtn = buttons.find((b) => b.attributes('title')?.includes('Save and Close'))
		const saveWithoutClosingBtn = buttons.find((b) => b.attributes('title')?.includes('Save without closing'))

		expect(saveAndCloseBtn?.exists()).toBe(true)
		expect(saveWithoutClosingBtn?.exists()).toBe(true)
	})
})
