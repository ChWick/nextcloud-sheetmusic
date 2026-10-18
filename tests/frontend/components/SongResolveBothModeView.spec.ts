import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongResolveBothModeView from '../../../src/components/song-editor/SongResolveBothModeView.vue'
import type { Song } from '../../../src/types/song'

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
	id: 10,
	title: 'Song Alpha',
	files: [{ id: 100, path: '/files/Alpha.pdf', type: 1 }],
}

const mockSongB: Song = {
	id: 20,
	title: 'Song Beta',
	files: [{ id: 200, path: '/files/Beta.pdf', type: 1 }],
}

const globalStubs = {
	global: {
		stubs: {
			NcButton: {
				template: '<button :title="title" @click="$emit(\'click\')"><slot /></button>',
				props: ['title'],
			},
			NcTextField: {
				template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
				props: ['value'],
			},
			NcSelect: {
				template: '<div class="select-stub" />',
				props: ['value', 'options'],
			},
		},
	},
}

describe('SongResolveBothModeView.vue', () => {
	it('renders Song A and Song B inputs', () => {
		const wrapper = mount(SongResolveBothModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				songA_title: 'Song Alpha',
				songB_title: 'Song Beta',
				songA_composers: [],
				songB_composers: [],
				songA_books: [],
				songB_books: [],
				songA_collections: [],
				songB_collections: [],
				songA_artists: [],
				songB_artists: [],
				songA_genres: [],
				songB_genres: [],
				songA_keys: [],
				songB_keys: [],
				songA_keywords: '',
				songB_keywords: '',
				songA_detachedFiles: [],
				songB_detachedFiles: [],
				composerOptions: [],
				bookOptions: [],
				collectionOptions: [],
				artistOptions: [],
				genreOptions: [],
				keyOptions: [],
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Song A')
		expect(wrapper.text()).toContain('Song B')
		expect(wrapper.text()).toContain('Copy all to B')
		expect(wrapper.text()).toContain('Copy all to A')
	})

	it('emits copyFieldsBetweenSongs and toggleDetachFile', async () => {
		const wrapper = mount(SongResolveBothModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				songA_title: 'Song Alpha',
				songB_title: 'Song Beta',
				songA_composers: [],
				songB_composers: [],
				songA_books: [],
				songB_books: [],
				songA_collections: [],
				songB_collections: [],
				songA_artists: [],
				songB_artists: [],
				songA_genres: [],
				songB_genres: [],
				songA_keys: [],
				songB_keys: [],
				songA_keywords: '',
				songB_keywords: '',
				songA_detachedFiles: [],
				songB_detachedFiles: [],
				composerOptions: [],
				bookOptions: [],
				collectionOptions: [],
				artistOptions: [],
				genreOptions: [],
				keyOptions: [],
			},
			...globalStubs,
		})

		const copyToBBtn = wrapper.findAll('button').find(b => b.text().includes('Copy all to B'))
		await copyToBBtn?.trigger('click')
		expect(wrapper.emitted('copyFieldsBetweenSongs')?.[0]).toEqual(['A'])

		const detachBtn = wrapper.findAll('button').find(b => b.attributes('title')?.includes('Detach File from Song A'))
		await detachBtn?.trigger('click')
		expect(wrapper.emitted('toggleDetachFile')?.[0]).toEqual(['A', 100])
	})
})

