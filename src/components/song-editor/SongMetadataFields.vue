<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, Signature, SourceType, Year } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed } from 'vue'

type NamedTag = { name: string, id?: number }
type BookTag = { title: string, id?: number }

const props = withDefaults(defineProps<{
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
	genres: Genre[]
	keys: Key[]
	customGroups: CustomGroup[]
	years: Year[]
	signatures: Signature[]
	sourceTypes: SourceType[]
	instruments?: Instrument[]
	arrangers?: Arranger[]
	createComposerTag: (name: string) => Composer | NamedTag
	createBookTag: (title: string) => Book | BookTag
	createCollectionTag: (name: string) => Collection | NamedTag
	createArtistTag: (name: string) => Artist | NamedTag
	createGenreTag: (name: string) => Genre | NamedTag
	createKeyTag: (name: string) => Key | NamedTag
	createCustomGroupTag: (name: string) => CustomGroup | NamedTag
	createYearTag: (name: string) => Year | NamedTag
	createSignatureTag: (name: string) => Signature | NamedTag
	createSourceTypeTag: (name: string) => SourceType | NamedTag
	createInstrumentTag?: (name: string) => Instrument | NamedTag
	createArrangerTag?: (name: string) => Arranger | NamedTag
}>(), {
	composers: () => [],
	books: () => [],
	collections: () => [],
	artists: () => [],
	genres: () => [],
	keys: () => [],
	customGroups: () => [],
	years: () => [],
	signatures: () => [],
	sourceTypes: () => [],
	instruments: () => [],
	arrangers: () => [],
})

const selectedComposers = defineModel<Array<Composer | NamedTag>>('selectedComposers', { required: true })
const selectedBooks = defineModel<Array<Book | BookTag>>('selectedBooks', { required: true })
const selectedCollections = defineModel<Array<Collection | NamedTag>>('selectedCollections', { required: true })
const selectedArtists = defineModel<Array<Artist | NamedTag>>('selectedArtists', { required: true })
const selectedGenres = defineModel<Array<Genre | NamedTag>>('selectedGenres', { required: true })
const selectedKeys = defineModel<Array<Key | NamedTag>>('selectedKeys', { required: true })
const selectedCustomGroups = defineModel<Array<CustomGroup | NamedTag>>('selectedCustomGroups', { required: true })
const selectedYears = defineModel<Array<Year | NamedTag>>('selectedYears', { required: true })
const selectedSignatures = defineModel<Array<Signature | NamedTag>>('selectedSignatures', { required: true })
const selectedSourceTypes = defineModel<Array<SourceType | NamedTag>>('selectedSourceTypes', { required: true })
const selectedInstruments = defineModel<Array<Instrument | NamedTag>>('selectedInstruments', { default: () => [] })
const selectedArrangers = defineModel<Array<Arranger | NamedTag>>('selectedArrangers', { default: () => [] })

const opus = defineModel<string>('opus', { default: '' })
const catalogNumber = defineModel<string>('catalogNumber', { default: '' })
const songNumber = defineModel<string>('songNumber', { default: '' })
const stars = defineModel<number>('stars', { required: true })
const difficulty = defineModel<number>('difficulty', { required: true })
const duration = defineModel<number>('duration', { required: true })
const keywords = defineModel<string>('keywords', { required: true })
const custom = defineModel<string>('custom', { required: true })
const custom2 = defineModel<string>('custom2', { required: true })
const tempo = defineModel<number>('tempo', { required: true })

const entityFields = computed(() => [
	{ key: 'composers', model: selectedComposers, options: props.composers, createOption: props.createComposerTag, inputLabel: t('sheetmusic', 'Composers'), label: 'name', placeholder: t('sheetmusic', 'Select or type a composer…') },
	{ key: 'books', model: selectedBooks, options: props.books, createOption: props.createBookTag, inputLabel: t('sheetmusic', 'Books / Albums'), label: 'title', placeholder: t('sheetmusic', 'Select or type a book/album…') },
	{ key: 'collections', model: selectedCollections, options: props.collections, createOption: props.createCollectionTag, inputLabel: t('sheetmusic', 'Collections'), label: 'name', placeholder: t('sheetmusic', 'Select or type a collection…') },
	{ key: 'artists', model: selectedArtists, options: props.artists, createOption: props.createArtistTag, inputLabel: t('sheetmusic', 'Artists'), label: 'name', placeholder: t('sheetmusic', 'Select or type an artist…') },
	{ key: 'genres', model: selectedGenres, options: props.genres, createOption: props.createGenreTag, inputLabel: t('sheetmusic', 'Genres'), label: 'name', placeholder: t('sheetmusic', 'Select or type a genre…') },
	{ key: 'keys', model: selectedKeys, options: props.keys, createOption: props.createKeyTag, inputLabel: t('sheetmusic', 'Keys'), label: 'name', placeholder: t('sheetmusic', 'Select or type a key…') },
	{ key: 'customGroups', model: selectedCustomGroups, options: props.customGroups, createOption: props.createCustomGroupTag, inputLabel: t('sheetmusic', 'Custom Groups'), label: 'name', placeholder: t('sheetmusic', 'Select or type a custom group…') },
	{ key: 'years', model: selectedYears, options: props.years, createOption: props.createYearTag, inputLabel: t('sheetmusic', 'Years'), label: 'name', placeholder: t('sheetmusic', 'Select or type a year…') },
	{ key: 'signatures', model: selectedSignatures, options: props.signatures, createOption: props.createSignatureTag, inputLabel: t('sheetmusic', 'Time Signatures'), label: 'name', placeholder: t('sheetmusic', 'Select or type a time signature (e.g. 4/4)…') },
	{ key: 'sourceTypes', model: selectedSourceTypes, options: props.sourceTypes, createOption: props.createSourceTypeTag, inputLabel: t('sheetmusic', 'Source Types'), label: 'name', placeholder: t('sheetmusic', 'Select or type a source type…') },
	{ key: 'instruments', model: selectedInstruments, options: props.instruments, createOption: props.createInstrumentTag, inputLabel: t('sheetmusic', 'Instruments'), label: 'name', placeholder: t('sheetmusic', 'Select or type an instrument…') },
	{ key: 'arrangers', model: selectedArrangers, options: props.arrangers, createOption: props.createArrangerTag, inputLabel: t('sheetmusic', 'Arrangers'), label: 'name', placeholder: t('sheetmusic', 'Select or type an arranger…') },
])
</script>

<template>
	<div>
		<div v-for="field in entityFields" :key="field.key" :class="$style.formGroup">
			<NcSelect
				v-model="field.model.value"
				:options="field.options"
				:multiple="true"
				:taggable="true"
				:create-option="field.createOption"
				:close-on-select="false"
				:input-label="field.inputLabel"
				:label="field.label"
				:track-by="field.label"
				:placeholder="field.placeholder" />
		</div>

		<div :class="$style.attributesGrid">
			<div :class="$style.formGroup">
				<NcTextField
					id="song-opus-input"
					v-model="opus"
					:placeholder="t('sheetmusic', 'Opus (e.g. Op. 28 No. 4)…')"
					:label="t('sheetmusic', 'Opus')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-catalog-number-input"
					v-model="catalogNumber"
					:placeholder="t('sheetmusic', 'Catalog No. (BWV, KV, etc.)…')"
					:label="t('sheetmusic', 'Catalog Number')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-song-number-input"
					v-model="songNumber"
					:placeholder="t('sheetmusic', 'Song No. (e.g. 88 or 104/5)…')"
					:label="t('sheetmusic', 'Song Number')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-rating-input"
					v-model.number="stars"
					type="number"
					:min="0"
					:max="5"
					:placeholder="t('sheetmusic', '0-5')"
					:label="t('sheetmusic', 'Rating (Stars 0-5)')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-difficulty-input"
					v-model.number="difficulty"
					type="number"
					:min="0"
					:max="5"
					:placeholder="t('sheetmusic', '0-5')"
					:label="t('sheetmusic', 'Difficulty (0-5)')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-duration-input"
					v-model.number="duration"
					type="number"
					:min="0"
					:placeholder="t('sheetmusic', 'Seconds (e.g. 180)')"
					:label="t('sheetmusic', 'Duration (seconds)')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-keywords-input"
					v-model="keywords"
					:placeholder="t('sheetmusic', 'Keywords / tags…')"
					:label="t('sheetmusic', 'Keywords')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-custom-input"
					v-model="custom"
					:placeholder="t('sheetmusic', 'Custom field…')"
					:label="t('sheetmusic', 'Custom')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-custom2-input"
					v-model="custom2"
					:placeholder="t('sheetmusic', 'Custom field 2…')"
					:label="t('sheetmusic', 'Custom 2')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-tempo-input"
					:model-value="tempo ? String(tempo) : ''"
					type="number"
					:placeholder="t('sheetmusic', 'Tempo (BPM)…')"
					:label="t('sheetmusic', 'Tempo (BPM)')"
					@update:model-value="tempo = Number($event) || 0" />
			</div>
		</div>
	</div>
</template>

<style module>
.formGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
	position: relative;
}

.attributesGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
}

.formGroup:focus-within {
	z-index: 10;
}
</style>
