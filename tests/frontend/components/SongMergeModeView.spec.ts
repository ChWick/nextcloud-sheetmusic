import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongMergeModeView from '../../../src/components/song-editor/SongMergeModeView.vue'
import {
	buildLibraryOptionsMap,
	createMergedDraft,
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
	composers: [{ id: 1, name: 'Composer A' }],
	arrangers: [{ id: 5, name: 'Arranger A' }],
	duration: 120,
	keywords: 'keyA',
	files: [{ id: 100, path: '/files/Alpha.pdf', type: 1 }],
}

const mockSongB: Song = {
	id: 20,
	title: 'Song Beta',
	composers: [{ id: 2, name: 'Composer B' }],
	arrangers: [{ id: 6, name: 'Arranger B' }],
	duration: 240,
	keywords: 'keyB',
	files: [{ id: 200, path: '/files/Beta.pdf', type: 1 }],
}

describe('SongMergeModeView.vue', () => {
	it('renders Song A, Song B and merged result with real @nextcloud/vue controls', () => {
		const draft = createMergedDraft(mockSongA, mockSongB)
		draft.title = 'Merged Alpha Beta'
		const optionsMap = buildLibraryOptionsMap({}, mockSongA, mockSongB)

		const wrapper = mount(SongMergeModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				keepId: 10,
				draft,
				optionsMap,
			},
		})

		expect(wrapper.text()).toContain('Song A')
		expect(wrapper.text()).toContain('Song B')
		expect(wrapper.text()).toContain('Merged Result')
		expect(wrapper.text()).toContain('Song Alpha')
		expect(wrapper.text()).toContain('Song Beta')
		expect(wrapper.text()).toContain('Arranger A')
		expect(wrapper.text()).toContain('Arranger B')
		expect((wrapper.find('input#merge-title-input').element as HTMLInputElement).value).toBe('Merged Alpha Beta')
	})

	it('emits copyField when copy buttons are clicked', async () => {
		const draft = createMergedDraft(mockSongA, mockSongB)
		const optionsMap = buildLibraryOptionsMap({}, mockSongA, mockSongB)

		const wrapper = mount(SongMergeModeView, {
			props: {
				songA: mockSongA,
				songB: mockSongB,
				keepId: 10,
				draft,
				optionsMap,
			},
		})

		await wrapper.find('[data-test="copy-from-a-title"]').trigger('click')
		expect(wrapper.emitted('copyField')?.[0]).toEqual(['title', 'A'])

		await wrapper.find('[data-test="copy-from-b-arrangers"]').trigger('click')
		expect(wrapper.emitted('copyField')?.[1]).toEqual(['arrangers', 'B'])

		await wrapper.find('[data-test="copy-from-a-duration"]').trigger('click')
		expect(wrapper.emitted('copyField')?.[2]).toEqual(['duration', 'A'])
	})
})
