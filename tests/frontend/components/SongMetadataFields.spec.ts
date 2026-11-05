import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongMetadataFields from '../../../src/components/song-editor/SongMetadataFields.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		template: '<button :data-label="inputLabel" @click="$emit(\'update:modelValue\', [createOption(\'New tag\')])">{{ inputLabel }}</button>',
		props: ['inputLabel', 'createOption', 'options', 'label', 'trackBy'],
	},
}))

vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		template: '<input :id="id" :aria-label="label" :value="modelValue" />',
		props: ['id', 'label', 'modelValue', 'placeholder', 'type'],
	},
}))

describe('SongMetadataFields.vue', () => {
	const props = {
		selectedComposers: [],
		selectedBooks: [],
		selectedCollections: [],
		selectedArtists: [],
		selectedGenres: [],
		selectedKeys: [],
		selectedCustomGroups: [],
		selectedYears: [],
		selectedSignatures: [],
		selectedSourceTypes: [],
		stars: 0,
		difficulty: 0,
		duration: 0,
		keywords: '',
		custom: '',
		custom2: '',
		tempo: 0,
		composers: [{ id: 1, name: 'Bach' }],
		books: [],
		collections: [],
		artists: [],
		genres: [],
		keys: [],
		customGroups: [],
		years: [],
		signatures: [],
		sourceTypes: [],
		createComposerTag: vi.fn((name: string) => ({ name })),
		createBookTag: vi.fn((title: string) => ({ title })),
		createCollectionTag: vi.fn((name: string) => ({ name })),
		createArtistTag: vi.fn((name: string) => ({ name })),
		createGenreTag: vi.fn((name: string) => ({ name })),
		createKeyTag: vi.fn((name: string) => ({ name })),
		createCustomGroupTag: vi.fn((name: string) => ({ name })),
		createYearTag: vi.fn((name: string) => ({ name })),
		createSignatureTag: vi.fn((name: string) => ({ name })),
		createSourceTypeTag: vi.fn((name: string) => ({ name })),
	}

	it('renders all entity and scalar metadata controls', () => {
		const wrapper = mount(SongMetadataFields, {
			props,
		})

		expect(wrapper.findAll('button').map(button => button.text())).toEqual([
			'Composers',
			'Books / Albums',
			'Collections',
			'Artists',
			'Genres',
			'Keys',
			'Custom Groups',
			'Years',
			'Time Signatures',
			'Source Types',
			'Instruments',
			'Arrangers',
		])
		for (const fieldId of [
			'song-opus-input',
			'song-catalog-number-input',
			'song-song-number-input',
			'song-rating-input',
			'song-difficulty-input',
			'song-duration-input',
			'song-keywords-input',
			'song-custom-input',
			'song-custom2-input',
			'song-tempo-input',
		]) {
			expect(wrapper.find(`#${fieldId}`).exists()).toBe(true)
		}
	})

	it('emits newly tagged entity selections back to the editor model', async () => {
		const wrapper = mount(SongMetadataFields, {
			props,
		})

		await wrapper.find('button[data-label="Composers"]').trigger('click')

		expect(props.createComposerTag).toHaveBeenCalledWith('New tag')
		expect(wrapper.emitted('update:selectedComposers')).toEqual([[[{ name: 'New tag' }]]])
	})
})
