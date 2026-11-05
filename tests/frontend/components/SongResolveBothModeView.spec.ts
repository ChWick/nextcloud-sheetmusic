import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongResolveBothModeView from '../../../src/components/song-editor/SongResolveBothModeView.vue'
import {
	buildLibraryOptionsMap,
	createDraftFromSong,
} from '../../../src/components/song-editor/song-merge-utils'
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
	arrangers: [{ id: 5, name: 'Arranger A' }],
	duration: 150,
	files: [{ id: 100, path: '/files/Alpha.pdf', type: 1 }],
}

const mockSongB: Song = {
	id: 20,
	title: 'Song Beta',
	arrangers: [{ id: 6, name: 'Arranger B' }],
	duration: 210,
	files: [{ id: 200, path: '/files/Beta.pdf', type: 1 }],
}

describe('SongResolveBothModeView.vue', () => {
	it('renders Song A and Song B inputs with real @nextcloud/vue controls', () => {
		const wrapper = mount(SongResolveBothModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				draftA: createDraftFromSong(mockSongA),
				draftB: createDraftFromSong(mockSongB),
				detachedFilesA: [],
				detachedFilesB: [],
				optionsMap: buildLibraryOptionsMap({}, mockSongA, mockSongB),
			},
		})

		expect(wrapper.text()).toContain('Song A')
		expect(wrapper.text()).toContain('Song B')
		expect(wrapper.text()).toContain('Copy all to B')
		expect(wrapper.text()).toContain('Copy all to A')
		expect((wrapper.find('input#resolve-a-title-input').element as HTMLInputElement).value).toBe('Song Alpha')
		expect((wrapper.find('input#resolve-b-title-input').element as HTMLInputElement).value).toBe('Song Beta')
		expect((wrapper.find('input#resolve-a-duration-input').element as HTMLInputElement).value).toBe('150')
		expect((wrapper.find('input#resolve-b-duration-input').element as HTMLInputElement).value).toBe('210')
	})

	it('emits copyFieldsBetweenSongs, copySingleFieldBetweenSongs, and toggleDetachFile', async () => {
		const wrapper = mount(SongResolveBothModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				draftA: createDraftFromSong(mockSongA),
				draftB: createDraftFromSong(mockSongB),
				detachedFilesA: [],
				detachedFilesB: [],
				optionsMap: buildLibraryOptionsMap({}, mockSongA, mockSongB),
			},
		})

		await wrapper.find('[data-test="copy-all-to-b"]').trigger('click')
		expect(wrapper.emitted('copyFieldsBetweenSongs')?.[0]).toEqual(['A'])

		await wrapper.find('[data-test="copy-a-to-b-arrangers"]').trigger('click')
		expect(wrapper.emitted('copySingleFieldBetweenSongs')?.[0]).toEqual(['arrangers', 'A'])

		await wrapper.find('[data-test="detach-file-a-100"]').trigger('click')
		expect(wrapper.emitted('toggleDetachFile')?.[0]).toEqual(['A', 100])
	})
})
