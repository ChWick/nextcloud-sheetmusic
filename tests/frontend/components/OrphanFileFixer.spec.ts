import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OrphanFileFixer from '../../../src/components/OrphanFileFixer.vue'
import * as apiClient from '../../../src/api/client'

vi.mock('../../../src/api/client', () => ({
	apiGet: vi.fn().mockResolvedValue({
		data: {
			ocs: {
				data: {
					songs: [
						{ id: 1, title: 'Joshua', files: [] },
						{ id: 2, title: 'Hallelujah', files: [] },
					],
					composers: [],
					books: [],
					collections: [],
					artists: [],
				},
			},
		},
	}),
	apiPost: vi.fn().mockResolvedValue({
		data: { ocs: { data: { success: true } } },
	}),
}))

vi.mock('../../../src/components/SongEditor.vue', () => ({
	default: {
		name: 'SongEditor',
		template: '<div class="song-editor-stub">Song Editor</div>',
	},
}))

describe('OrphanFileFixer.vue', () => {
	const globalStubs = {
		global: {
			stubs: {
				ViewHeader: {
					template: '<header><h1>{{ title }}</h1><slot /></header>',
					props: ['title'],
				},
				FilePreviewer: {
					template: '<div class="file-previewer-stub">{{ filePath }}</div>',
					props: ['filePath'],
				},
				SelectSongDialog: {
					template: '<div class="select-song-dialog-stub" />',
				},
				NcButton: {
					template: '<button :disabled="disabled" :title="title" :aria-label="ariaLabel" @click="$emit(\'click\')"><slot /></button>',
					props: ['disabled', 'variant', 'title', 'ariaLabel'],
				},
			},
		},
	}

	it('renders option buttons and file preview', () => {
		const wrapper = mount(OrphanFileFixer, {
			props: {
				selectedLibrary: 1,
				orphanPath: 'scores/Joshua.pdf',
				issue: {
					type: 'orphan_file',
					severity: 'warning',
					message: 'File Joshua.pdf is not linked',
					path: 'scores/Joshua.pdf',
					fixable: false,
					details: {
						filename: 'Joshua.pdf',
						userRelativePath: '/MobileSheets/scores/Joshua.pdf',
					},
				},
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('How would you like to fix this orphan file?')
		expect(wrapper.text()).toContain('Find Existing Song')
		expect(wrapper.text()).toContain('Create a New Song')
		expect(wrapper.text()).toContain('Delete File')
		expect(wrapper.find('[class*="buttonGroup"]').exists()).toBe(true)
		expect(wrapper.findAll('[class*="buttonGroup"] button')).toHaveLength(3)
	})

	it('switches to create song mode when option b is clicked', async () => {
		const wrapper = mount(OrphanFileFixer, {
			props: {
				selectedLibrary: 1,
				orphanPath: 'scores/Joshua.pdf',
			},
			...globalStubs,
		})

		const createBtn = wrapper.findAll('button').find(b => b.text().includes('Create a New Song'))
		await createBtn?.trigger('click')

		expect(wrapper.text()).toContain('Create Song for Orphan File')
		const iconButtons = wrapper.findAll('button')
		expect(iconButtons.some(b => b.attributes('title')?.includes('Create song'))).toBe(true)
		expect(iconButtons.some(b => b.attributes('title')?.includes('Back to Orphan Fixer'))).toBe(true)
	})

	it('opens select song dialog when option a is clicked', async () => {
		const wrapper = mount(OrphanFileFixer, {
			props: {
				selectedLibrary: 1,
				orphanPath: 'scores/Joshua.pdf',
			},
			...globalStubs,
		})

		const findBtn = wrapper.findAll('button').find(b => b.text().includes('Find Existing Song'))
		await findBtn?.trigger('click')

		expect(apiClient.apiGet).toHaveBeenCalledWith('/api/songs', { queryParams: { id: '1' } })
	})
})
