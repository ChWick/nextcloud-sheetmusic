import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ShareTargetDialog from '../../../src/components/ShareTargetDialog.vue'

const mockShowSuccess = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...args: unknown[]) => mockShowSuccess(...args),
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
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		template: '<span class="nc-loading" />',
	},
}))

const singleLibrary = [
	{ id: 1, path: '/Music', canWrite: true, isShared: false },
] as any[]

const multipleLibraries = [
	{ id: 1, path: '/Music', canWrite: true, isShared: false },
	{ id: 2, path: '/Choir', canWrite: true, isShared: false },
] as any[]

const sharedPdf = new File(['pdf-bytes'], 'Clair de Lune.pdf', { type: 'application/pdf' })

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
	return wrapper.findAll('button').find(b => b.text() === text)!
}

describe('ShareTargetDialog.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(axios.get).mockImplementation(async (url: string) => {
			if (url.includes('/setlists')) {
				return {
					data: {
						ocs: {
							data: [
								{ id: 7, name: 'Sunday Service' },
								{ id: 8, name: 'Christmas Concert' },
							],
						},
					},
				} as any
			}
			return {
				data: {
					ocs: {
						data: {
							songs: [
								{ id: 42, title: 'Existing Piece', composers: [{ id: 1, name: 'Debussy' }] },
								{ id: 43, title: 'Another Song', composers: [] },
							],
						},
					},
				},
			} as any
		})
		vi.mocked(axios.post).mockImplementation(async (url: string) => {
			if (url.endsWith('/api/songs')) {
				return { data: { ocs: { data: { createdSongId: 99 } } } } as any
			}
			if (url.endsWith('/api/setlists')) {
				return { data: { ocs: { data: { id: 55, name: 'Order of Service' } } } } as any
			}
			return { data: { ocs: { data: { id: 12 } } } } as any
		})
		vi.mocked(axios.delete).mockResolvedValue({ data: { ocs: { data: {} } } } as any)
	})

	it('displays library paths on step 1, preselects single library, and creates a new song', async () => {
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [sharedPdf],
				libraries: singleLibrary,
				selectedLibraryId: -1,
			},
		})
		await flushPromises()

		expect(wrapper.text()).toContain('/Music')
		const libRadio = wrapper.find('input[type="radio"][value="1"]')
		expect((libRadio.element as HTMLInputElement).checked).toBe(true)

		// Step 1 -> Step 2
		await findButton(wrapper, 'Next').trigger('click')
		// Step 2 -> Step 3 (new-song is default)
		await findButton(wrapper, 'Next').trigger('click')

		const titleInput = wrapper.find('input[aria-label="Song title"]')
		expect((titleInput.element as HTMLInputElement).value).toBe('Clair de Lune')

		await findButton(wrapper, 'Import').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/songs'),
			{ libraryId: 1, title: 'Clair de Lune' },
		)
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/songs/99/file/upload'),
			expect.any(FormData),
		)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'new-song', libraryId: 1, songId: 99 },
		])
	})

	it('filters and adds the shared file to an existing song in the chosen library', async () => {
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [sharedPdf],
				libraries: multipleLibraries,
				selectedLibraryId: -1,
			},
		})
		await flushPromises()

		// Select second library on Step 1
		await wrapper.find('input[type="radio"][value="2"]').setValue(true)
		await findButton(wrapper, 'Next').trigger('click')

		// Choose existing song on Step 2
		await wrapper.find('input[value="existing-song"]').setValue(true)
		await findButton(wrapper, 'Next').trigger('click')
		await flushPromises()

		// Filter songs on Step 3
		const filterInput = wrapper.find('input[aria-label="Filter songs"]')
		await filterInput.setValue('Another')
		const options = wrapper.findAll('button[role="option"]')
		expect(options).toHaveLength(1)
		expect(options[0].text()).toContain('Another Song')
		await options[0].trigger('click')

		await findButton(wrapper, 'Import').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/2/songs/43/file/upload'),
			expect.any(FormData),
		)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'existing-song', libraryId: 2, songId: 43 },
		])
	})

	it('attaches the shared file to a selected setlist', async () => {
		const docxFile = new File(['docx-bytes'], 'Order of Service.docx', {
			type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		})
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [docxFile],
				libraries: singleLibrary,
				selectedLibraryId: 1,
			},
		})
		await flushPromises()

		await findButton(wrapper, 'Next').trigger('click')
		await wrapper.find('input[value="setlist-file"]').setValue(true)
		await findButton(wrapper, 'Next').trigger('click')
		await flushPromises()

		const options = wrapper.findAll('button[role="option"]')
		expect(options).toHaveLength(2)
		await options[1].trigger('click')

		await findButton(wrapper, 'Import').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/setlists/8/files'),
			expect.any(FormData),
			expect.objectContaining({ headers: { 'Content-Type': 'multipart/form-data' } }),
		)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'setlist-file', libraryId: 1, setlistId: 8 },
		])
	})

	it('creates a new setlist and attaches the shared file', async () => {
		const docxFile = new File(['docx-bytes'], 'Order of Service.docx', {
			type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		})
		const wrapper = mount(ShareTargetDialog, {
			props: {
				open: true,
				files: [docxFile],
				libraries: singleLibrary,
				selectedLibraryId: 1,
			},
		})
		await flushPromises()

		await findButton(wrapper, 'Next').trigger('click')
		await wrapper.find('input[value="new-setlist"]').setValue(true)
		await findButton(wrapper, 'Next').trigger('click')

		const nameInput = wrapper.find('input[aria-label="Setlist name"]')
		expect((nameInput.element as HTMLInputElement).value).toBe('Order of Service')

		await findButton(wrapper, 'Import').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/setlists'),
			{ libraryId: 1, name: 'Order of Service' },
		)
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/setlists/55/files'),
			expect.any(FormData),
			expect.objectContaining({ headers: { 'Content-Type': 'multipart/form-data' } }),
		)
		expect(wrapper.emitted('imported')?.[0]).toEqual([
			{ targetType: 'new-setlist', libraryId: 1, setlistId: 55 },
		])
	})
})
