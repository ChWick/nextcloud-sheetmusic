import type { Artist, Book, Collection, Composer, Song } from '../../../src/types/song'

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
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
					template: '<button :disabled="disabled" :type="type" :title="title" :aria-label="ariaLabel"><slot /></button>',
					props: ['disabled', 'type', 'variant', 'title', 'ariaLabel'],
				},
				NcTextField: {
					template: '<input :id="id" :placeholder="placeholder" :value="value !== undefined ? value : modelValue" @input="$emit(\'update:value\', $event.target.value); $emit(\'update:modelValue\', $event.target.value)" />',
					props: ['id', 'value', 'modelValue', 'placeholder', 'required'],
				},
				NcSelect: {
					template: '<div class="nc-select-stub"></div>',
					props: ['modelValue', 'options'],
				},
				NcLoadingIcon: {
					template: '<div class="nc-loading-stub"></div>',
				},
				NcDialog: {
					template: '<div class="nc-dialog-stub"><slot /></div>',
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
		expect(wrapper.html()).toContain('Create song')
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
		expect(wrapper.html()).toContain('Save and Close')
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

	it('emits cancel event when Cancel button is clicked', async () => {
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

		const buttons = wrapper.findAll('button')
		const cancelBtn = buttons.find((btn) => btn.text() === 'Cancel' || btn.attributes('aria-label') === 'Cancel' || btn.attributes('title') === 'Cancel')
		expect(cancelBtn).toBeDefined()
		await cancelBtn!.trigger('click')

		expect(wrapper.emitted('cancel')).toBeTruthy()
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
			'/ocs/v2.php/apps/mobilesheetsmanager/api/songs',
			{
				libraryId: 1,
				title: 'New Symphony',
				composerIds: [],
				bookIds: [],
				collectionIds: [],
				artistIds: [],
				genreIds: [],
				keyIds: [],
				stars: 0,
				difficulty: 0,
				duration: 0,
				keywords: '',
				custom: '',
				custom2: '',
			},
		)

		// Upload endpoint call
		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/mobilesheetsmanager/api/songs/99/file/upload',
			expect.any(FormData),
			expect.objectContaining({
				headers: { 'Content-Type': 'multipart/form-data' },
			}),
		)

		expect(wrapper.emitted('saved')).toBeTruthy()
		expect(wrapper.emitted('saved')![0][1]).toBe(99)
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
			'/ocs/v2.php/apps/mobilesheetsmanager/api/songs/10',
			{
				libraryId: 1,
				title: 'Existing Song',
				composerIds: [],
				bookIds: [],
				collectionIds: [],
				artistIds: [],
				genreIds: [],
				keyIds: [],
				stars: 5,
				difficulty: 3,
				duration: 240,
				keywords: 'allegro, vivace',
				custom: 'Opus 5',
				custom2: 'No. 2',
			},
		)
	})
})
