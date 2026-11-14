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
		template: '<input :id="id" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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
		stars: null,
		difficulty: null,
		duration: null,
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

		expect((wrapper.find('#song-rating-input').element as HTMLInputElement).value).toBe('')
		expect((wrapper.find('#song-difficulty-input').element as HTMLInputElement).value).toBe('')
		expect((wrapper.find('#song-duration-input').element as HTMLSelectElement).value).toBe('0')
	})

	it('emits newly tagged entity selections back to the editor model', async () => {
		const wrapper = mount(SongMetadataFields, {
			props,
		})

		await wrapper.find('button[data-label="Composers"]').trigger('click')

		expect(props.createComposerTag).toHaveBeenCalledWith('New tag')
		expect(wrapper.emitted('update:selectedComposers')).toEqual([[[{ name: 'New tag' }]]])
	})

	it('displays and edits duration in hh:mm:ss selector while keeping model value in seconds', async () => {
		const wrapper = mount(SongMetadataFields, {
			props: {
				...props,
				duration: 240,
			},
		})

		const hoursSelect = wrapper.find('[data-test="duration-hours"]')
		const minutesSelect = wrapper.find('[data-test="duration-minutes"]')
		const secondsSelect = wrapper.find('[data-test="duration-seconds"]')
		expect((hoursSelect.element as HTMLSelectElement).value).toBe('0')
		expect((minutesSelect.element as HTMLSelectElement).value).toBe('4')
		expect((secondsSelect.element as HTMLSelectElement).value).toBe('0')

		await hoursSelect.setValue('1')
		await wrapper.setProps({ duration: 3840 })
		await minutesSelect.setValue('2')
		await wrapper.setProps({ duration: 3720 })
		await secondsSelect.setValue('3')
		expect(wrapper.emitted('update:duration')?.at(-1)).toEqual([3723])

		await wrapper.setProps({ duration: 3723 })
		await wrapper.find('[data-test="duration-clear"]').trigger('click')
		expect(wrapper.emitted('update:duration')?.at(-1)).toEqual([null])
	})
})
