import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongMergeModeView from '../../../src/components/song-editor/SongMergeModeView.vue'
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
	composers: [{ id: 1, name: 'Composer A' }],
	books: [],
	collections: [],
	artists: [],
	genres: [],
	keys: [],
	keywords: 'keyA',
	files: [{ id: 100, path: '/files/Alpha.pdf', type: 1 }],
}

const mockSongB: Song = {
	id: 20,
	title: 'Song Beta',
	composers: [{ id: 2, name: 'Composer B' }],
	books: [],
	collections: [],
	artists: [],
	genres: [],
	keys: [],
	keywords: 'keyB',
	files: [{ id: 200, path: '/files/Beta.pdf', type: 1 }],
}

const globalStubs = {
	global: {
		stubs: {
			NcButton: {
				template: '<button :title="title" @click="$emit(\'click\')"><slot /></button>',
				props: ['title'],
			},
			NcCheckboxRadioSwitch: {
				template: '<input type="radio" :checked="checked" @change="$emit(\'update:checked\', true)" />',
				props: ['checked', 'value'],
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

describe('SongMergeModeView.vue', () => {
	it('renders Song A, Song B and merged result', () => {
		const wrapper = mount(SongMergeModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				keepId: 10,
				mergedTitle: 'Merged Alpha Beta',
				mergedComposers: [],
				mergedBooks: [],
				mergedCollections: [],
				mergedArtists: [],
				mergedGenres: [],
				mergedKeys: [],
				mergedKeywords: '',
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
		expect(wrapper.text()).toContain('Merged Result')
		expect(wrapper.text()).toContain('Song Alpha')
		expect(wrapper.text()).toContain('Song Beta')
	})

	it('emits copyField when copy buttons are clicked', async () => {
		const wrapper = mount(SongMergeModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				keepId: 10,
				mergedTitle: '',
				mergedComposers: [],
				mergedBooks: [],
				mergedCollections: [],
				mergedArtists: [],
				mergedGenres: [],
				mergedKeys: [],
				mergedKeywords: '',
				composerOptions: [],
				bookOptions: [],
				collectionOptions: [],
				artistOptions: [],
				genreOptions: [],
				keyOptions: [],
			},
			...globalStubs,
		})

		const useTitleA = wrapper.findAll('button').find(b => b.attributes('title')?.includes('Use Song A Title'))
		expect(useTitleA).toBeDefined()
		await useTitleA?.trigger('click')

		expect(wrapper.emitted('copyField')?.[0]).toEqual(['title', 'A'])
	})
})

