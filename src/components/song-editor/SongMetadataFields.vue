<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, Signature, SourceType, Year } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, type Ref } from 'vue'
import {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	type SongEntityProp,
	type SongScalarFieldDef,
	type SongScalarProp,
} from '../songs/song-view-columns'

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
const stars = defineModel<number | null>('stars', { default: null })
const difficulty = defineModel<number | null>('difficulty', { default: null })
const duration = defineModel<number | null>('duration', { default: null })
const keywords = defineModel<string>('keywords', { required: true })
const custom = defineModel<string>('custom', { required: true })
const custom2 = defineModel<string>('custom2', { required: true })
const tempo = defineModel<number>('tempo', { required: true })

const entityModels: Record<SongEntityProp, Ref<any[]>> = {
	composers: selectedComposers,
	books: selectedBooks,
	collections: selectedCollections,
	artists: selectedArtists,
	genres: selectedGenres,
	keys: selectedKeys,
	customGroups: selectedCustomGroups,
	years: selectedYears,
	signatures: selectedSignatures,
	sourceTypes: selectedSourceTypes,
	instruments: selectedInstruments,
	arrangers: selectedArrangers,
}

const entityFields = computed(() => {
	const createTagHandlers: Record<SongEntityProp, ((val: string) => any) | undefined> = {
		composers: props.createComposerTag,
		books: props.createBookTag,
		collections: props.createCollectionTag,
		artists: props.createArtistTag,
		genres: props.createGenreTag,
		keys: props.createKeyTag,
		customGroups: props.createCustomGroupTag,
		years: props.createYearTag,
		signatures: props.createSignatureTag,
		sourceTypes: props.createSourceTypeTag,
		instruments: props.createInstrumentTag,
		arrangers: props.createArrangerTag,
	}
	return ENTITY_COLUMNS.map((col) => ({
		key: col.songProp,
		model: entityModels[col.songProp],
		options: props[col.songProp] ?? [],
		createOption: createTagHandlers[col.songProp],
		inputLabel: col.editorInputLabel(),
		label: col.nameKey,
		placeholder: col.editorPlaceholder(),
	}))
})

const scalarModels: Record<SongScalarProp, Ref<any>> = {
	opus,
	catalogNumber,
	songNumber,
	stars,
	difficulty,
	duration,
	keywords,
	custom,
	custom2,
}

function getScalarModelValue(field: SongScalarFieldDef): string {
	const val = scalarModels[field.key].value
	if (field.type === 'number') {
		return val !== null && val !== undefined && Number(val) > 0 ? String(val) : ''
	}
	return String(val ?? '')
}

function onScalarInput(field: SongScalarFieldDef, raw: string | number) {
	if (field.type === 'number') {
		const num = Number(raw)
		scalarModels[field.key].value = Number.isFinite(num) && num > 0 ? num : null
	} else {
		scalarModels[field.key].value = String(raw ?? '')
	}
}
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
			<div v-for="field in SONG_SCALAR_FIELDS" :key="field.key" :class="$style.formGroup">
				<NcTextField
					:id="field.editorInputId"
					:value="getScalarModelValue(field)"
					:model-value="getScalarModelValue(field)"
					:type="field.type"
					:min="field.min"
					:max="field.max"
					:placeholder="field.placeholder()"
					:label="field.inputLabel()"
					@update:value="onScalarInput(field, $event)"
					@update:model-value="onScalarInput(field, $event)" />
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
