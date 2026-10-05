<script setup lang="ts">
import type { Artist, Book, Collection, Composer, CustomGroup, Genre, Key, Signature, SourceType, Year } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

type NamedTag = { name: string, id?: number }
type BookTag = { title: string, id?: number }

withDefaults(defineProps<{
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

const stars = defineModel<number>('stars', { required: true })
const difficulty = defineModel<number>('difficulty', { required: true })
const duration = defineModel<number>('duration', { required: true })
const keywords = defineModel<string>('keywords', { required: true })
const custom = defineModel<string>('custom', { required: true })
const custom2 = defineModel<string>('custom2', { required: true })
const tempo = defineModel<number>('tempo', { required: true })
</script>

<template>
	<div>
		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedComposers"
				:options="composers"
				:multiple="true"
				:taggable="true"
				:create-option="createComposerTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Composers')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a composer…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedBooks"
				:options="books"
				:multiple="true"
				:taggable="true"
				:create-option="createBookTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Books / Albums')"
				label="title"
				track-by="title"
				:placeholder="t('mobilesheetsmanager', 'Select or type a book/album…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedCollections"
				:options="collections"
				:multiple="true"
				:taggable="true"
				:create-option="createCollectionTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Collections')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a collection…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedArtists"
				:options="artists"
				:multiple="true"
				:taggable="true"
				:create-option="createArtistTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Artists')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type an artist…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedGenres"
				:options="genres"
				:multiple="true"
				:taggable="true"
				:create-option="createGenreTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Genres')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a genre…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedKeys"
				:options="keys"
				:multiple="true"
				:taggable="true"
				:create-option="createKeyTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Keys')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a key…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedCustomGroups"
				:options="customGroups"
				:multiple="true"
				:taggable="true"
				:create-option="createCustomGroupTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Custom Groups')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a custom group…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedYears"
				:options="years"
				:multiple="true"
				:taggable="true"
				:create-option="createYearTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Years')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a year…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedSignatures"
				:options="signatures"
				:multiple="true"
				:taggable="true"
				:create-option="createSignatureTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Time Signatures')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a time signature (e.g. 4/4)…')" />
		</div>

		<div :class="$style.formGroup">
			<NcSelect
				v-model="selectedSourceTypes"
				:options="sourceTypes"
				:multiple="true"
				:taggable="true"
				:create-option="createSourceTypeTag"
				:close-on-select="false"
				:input-label="t('mobilesheetsmanager', 'Source Types')"
				label="name"
				track-by="name"
				:placeholder="t('mobilesheetsmanager', 'Select or type a source type…')" />
		</div>

		<div :class="$style.attributesGrid">
			<div :class="$style.formGroup">
				<NcTextField
					id="song-rating-input"
					v-model.number="stars"
					type="number"
					:min="0"
					:max="5"
					:placeholder="t('mobilesheetsmanager', '0-5')"
					:label="t('mobilesheetsmanager', 'Rating (Stars 0-5)')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-difficulty-input"
					v-model.number="difficulty"
					type="number"
					:min="0"
					:max="5"
					:placeholder="t('mobilesheetsmanager', '0-5')"
					:label="t('mobilesheetsmanager', 'Difficulty (0-5)')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-duration-input"
					v-model.number="duration"
					type="number"
					:min="0"
					:placeholder="t('mobilesheetsmanager', 'Seconds (e.g. 180)')"
					:label="t('mobilesheetsmanager', 'Duration (seconds)')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-keywords-input"
					v-model="keywords"
					:placeholder="t('mobilesheetsmanager', 'Keywords / tags…')"
					:label="t('mobilesheetsmanager', 'Keywords')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-custom-input"
					v-model="custom"
					:placeholder="t('mobilesheetsmanager', 'Custom field…')"
					:label="t('mobilesheetsmanager', 'Custom')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-custom2-input"
					v-model="custom2"
					:placeholder="t('mobilesheetsmanager', 'Custom field 2…')"
					:label="t('mobilesheetsmanager', 'Custom 2')" />
			</div>
			<div :class="$style.formGroup">
				<NcTextField
					id="song-tempo-input"
					:model-value="tempo ? String(tempo) : ''"
					type="number"
					:placeholder="t('mobilesheetsmanager', 'Tempo (BPM)…')"
					:label="t('mobilesheetsmanager', 'Tempo (BPM)')"
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
