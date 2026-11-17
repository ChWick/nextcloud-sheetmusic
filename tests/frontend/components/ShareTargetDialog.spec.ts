import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ShareTargetDialog from '../../../src/components/ShareTargetDialog.vue'

const mockApiGet = vi.fn()
const mockApiPost = vi.fn()
const mockFetchSetlists = vi.fn()
const mockUploadSetlistFile = vi.fn()
const mockShowSuccess = vi.fn()

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...args: unknown[]) => mockShowSuccess(...args),
}))

vi.mock('../../../src/api/client', () => ({
	apiGet: (...args: unknown[]) => mockApiGet(...args),
	apiPost: (...args: unknown[]) => mockApiPost(...args),
}))

vi.mock('../../../src/api/setlists', () => ({
	fetchSetlists: (...args: unknown[]) => mockFetchSetlists(...args),
	uploadSetlistFile: (...args: unknown[]) => mockUploadSetlistFile(...args),
}))

vi.mock('@nextcloud/vue', () => ({
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'size'],
		template: '<div class="nc-dialog"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled', 'required'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		template: '<span class="nc-loading" />',
	},
}))

const libraries = [
	{ id: 1, name: 'Main Library', path: '/Music', canWrite: true, isShared: false },
] as any[]

const sharedPdf = new File(['pdf-bytes'], 'Clair de Lune.pdf', { type: 'application/pdf' })

describe('ShareTargetDialog.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockApiGet.mockResolvedValue({
			data: {
				ocs: {
					data: {
						songs: [{ id: 42, title: 'Existing Piece' }],
					},
				},
			},
		})
		mockFetchSetlists.mockResolvedValue([{ id: 7, name: 'Sunday Service' }])
		mockApiPost.mockImplementation((path: string) => {
			if (path === '/api/songs') {
				return Promise.resolve({ data: { ocs: { data: { createdSongId: 99 } } } })
			}
			return Promise.resolve({ data: { ocs: { data: {} } } })
		})
		mockUploadSetlistFile.mockResolvedValue({ id: 12 })
	})

	it('creates a new song and uploads the shared file', async () => {
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [sharedPdf],
				libraries,
				selectedLibraryId: 1,
			},
		})
		await flushPromises()

		const titleInput = wrapper.find('input[aria-label="Song title"]')
		expect((titleInput.element as HTMLInputElement).value).toBe('Clair de Lune')

		await wrapper.findAll('button').find(b => b.text() === 'Import')!.trigger('click')
		await flushPromises()

		expect(mockApiPost).toHaveBeenCalledWith('/api/songs', {
			libraryId: 1,
			title: 'Clair de Lune',
		})
		expect(mockApiPost).toHaveBeenCalledWith(
			'/api/libraries/{libraryId}/songs/{id}/file/upload',
			expect.any(FormData),
			{ pathParams: { libraryId: 1, id: 99 } },
		)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'new-song', libraryId: 1, songId: 99 },
		])
	})

	it('adds the shared file to an existing song', async () => {
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [sharedPdf],
				libraries,
				selectedLibraryId: 1,
			},
		})
		await flushPromises()

		await wrapper.find('input[value="existing-song"]').setValue(true)
		await wrapper.findAll('button').find(b => b.text() === 'Import')!.trigger('click')
		await flushPromises()

		expect(mockApiPost).toHaveBeenCalledWith(
			'/api/libraries/{libraryId}/songs/{id}/file/upload',
			expect.any(FormData),
			{ pathParams: { libraryId: 1, id: 42 } },
		)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'existing-song', libraryId: 1, songId: 42 },
		])
	})

	it('attaches the shared file to a setlist', async () => {
		const docxFile = new File(['docx-bytes'], 'Order of Service.docx', {
			type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		})
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [docxFile],
				libraries,
				selectedLibraryId: 1,
			},
		})
		await flushPromises()

		await wrapper.find('input[value="setlist-file"]').setValue(true)
		await wrapper.findAll('button').find(b => b.text() === 'Import')!.trigger('click')
		await flushPromises()

		expect(mockUploadSetlistFile).toHaveBeenCalledWith(1, 7, docxFile)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'setlist-file', libraryId: 1, setlistId: 7 },
		])
	})
})
