<script setup lang="ts">
import type { Artist, Book, Collection, Composer, CustomGroup, Genre, Key, Year } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FilterOffIcon from 'vue-material-design-icons/FilterOff.vue'
import { computed } from 'vue'
import { noneEntryOf } from '../composables/useSongFilters'

const props = withDefaults(
	defineProps<{
		searchTitle?: string
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
		genres?: Genre[]
		keys?: Key[]
		customGroups?: CustomGroup[]
		years?: Year[]
		selectedComposers?: Composer[]
		selectedBooks?: Book[]
		selectedCollections?: Collection[]
		selectedArtists?: Artist[]
		selectedGenres?: Genre[]
		selectedKeys?: Key[]
		selectedCustomGroups?: CustomGroup[]
		selectedYears?: Year[]
	}>(),
	{
		searchTitle: '',
		composers: () => [],
		books: () => [],
		collections: () => [],
		artists: () => [],
		genres: () => [],
		keys: () => [],
		customGroups: () => [],
		years: () => [],
		selectedComposers: () => [],
		selectedBooks: () => [],
		selectedCollections: () => [],
		selectedArtists: () => [],
		selectedGenres: () => [],
		selectedKeys: () => [],
		selectedCustomGroups: () => [],
		selectedYears: () => [],
	},
)

const emit = defineEmits<{
	'update:searchTitle': [title: string]
	'update:selectedComposers': [composers: Composer[]]
	'update:selectedBooks': [books: Book[]]
	'update:selectedCollections': [collections: Collection[]]
	'update:selectedArtists': [artists: Artist[]]
	'update:selectedGenres': [genres: Genre[]]
	'update:selectedKeys': [keys: Key[]]
	'update:selectedCustomGroups': [customGroups: CustomGroup[]]
	'update:selectedYears': [years: Year[]]
	'clear': []
}>()

const composerOptions = computed(() => [
	noneEntryOf('composers') as Composer,
	...props.composers,
])

const bookOptions = computed(() => [
	noneEntryOf('books') as Book,
	...props.books,
])

const collectionOptions = computed(() => [
	noneEntryOf('collections') as Collection,
	...props.collections,
])

const artistOptions = computed(() => [
	noneEntryOf('artists') as Artist,
	...props.artists,
])

const genreOptions = computed(() => [
	noneEntryOf('genres') as Genre,
	...props.genres,
])

const keyOptions = computed(() => [
	noneEntryOf('keys') as Key,
	...props.keys,
])

const customGroupOptions = computed(() => [
	noneEntryOf('customgroups') as CustomGroup,
	...props.customGroups,
])

const yearOptions = computed(() => [
	noneEntryOf('years') as Year,
	...props.years,
])

const hasActiveFilters = computed(() => {
	return (
		Boolean(props.searchTitle?.trim())
		|| props.selectedComposers.length > 0
		|| props.selectedBooks.length > 0
		|| props.selectedCollections.length > 0
		|| props.selectedArtists.length > 0
		|| props.selectedGenres.length > 0
		|| props.selectedKeys.length > 0
		|| props.selectedCustomGroups.length > 0
		|| props.selectedYears.length > 0
	)
})

function clearAllFilters() {
	// One atomic action: the views drop every selection in a single URL change, so
	// the browser history gets one entry instead of one per filter.
	emit('clear')
}
</script>

<template>
	<div :class="$style.filterBar" role="region" :aria-label="t('mobilesheetsmanager', 'Song filters')">
		<div :class="$style.filterGroup">
			<NcTextField
				:model-value="searchTitle"
				:placeholder="t('mobilesheetsmanager', 'Search by title…')"
				:label="t('mobilesheetsmanager', 'Search title')"
				:class="$style.input"
				@update:model-value="emit('update:searchTitle', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedComposers"
				:options="composerOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by composer…')"
				:input-label="t('mobilesheetsmanager', 'Composers')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedComposers', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedBooks"
				:options="bookOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by book…')"
				:input-label="t('mobilesheetsmanager', 'Books')"
				label="title"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedBooks', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedCollections"
				:options="collectionOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by collection…')"
				:input-label="t('mobilesheetsmanager', 'Collections')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedCollections', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedArtists"
				:options="artistOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by artist…')"
				:input-label="t('mobilesheetsmanager', 'Artists')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedArtists', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedGenres"
				:options="genreOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by genre…')"
				:input-label="t('mobilesheetsmanager', 'Genres')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedGenres', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedKeys"
				:options="keyOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by key…')"
				:input-label="t('mobilesheetsmanager', 'Keys')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedKeys', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedCustomGroups"
				:options="customGroupOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by custom group…')"
				:input-label="t('mobilesheetsmanager', 'Custom Groups')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedCustomGroups', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<NcSelect
				:model-value="selectedYears"
				:options="yearOptions"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by year…')"
				:input-label="t('mobilesheetsmanager', 'Years')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedYears', $event)" />
		</div>

		<div v-if="hasActiveFilters" :class="$style.actions">
			<NcButton
				type="tertiary"
				:title="t('mobilesheetsmanager', 'Clear filters')"
				:aria-label="t('mobilesheetsmanager', 'Clear filters')"
				@click="clearAllFilters">
				<template #icon>
					<FilterOffIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<style module>
.filterBar {
	display: flex;
	flex-direction: row;
	flex-wrap: wrap;
	gap: 12px 16px;
	align-items: stretch;
	padding: 12px 16px;
	margin-bottom: 16px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	position: relative;
	z-index: 2;
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 240px;
	min-width: 0;
	position: relative;
}

.filterGroup:focus-within {
	z-index: 10;
}

.input {
	width: 100%;
	min-width: 0;
	max-width: 100%;
	height: 100%;
}

.filterBar .input:global(.input-field),
.filterBar .input :global(.input-field) {
	min-width: 0;
	width: 100%;
	height: 100%;
	margin: 0 !important;
	margin-block-start: 0 !important;
	padding: 0 !important;
}

.filterBar .input :global(.input-field__main-wrapper) {
	min-height: var(--default-clickable-area, 44px);
	height: 100%;
	padding: 0 !important;
	margin: 0 !important;
}

.filterBar .input :global(.input-field__helper-text-message) {
	display: none;
}

.filterBar .input :global(.input-field__input) {
	min-height: var(--default-clickable-area, 44px) !important;
	height: 100% !important;
	box-sizing: border-box !important;
}

.select {
	width: 100%;
	min-width: 0;
	max-width: 100%;
	height: 100%;
}

.filterBar .select:global(.nc-select.v-select.select),
.filterBar .select :global(.nc-select.v-select.select) {
	min-width: 0;
	width: 100%;
	height: 100%;
	margin: 0 !important;
}

.filterBar .select :global(.vs__dropdown-toggle) {
	min-height: var(--default-clickable-area, 44px);
	height: 100%;
	box-sizing: border-box;
}

.filterBar .select :global(.vs__selected-options) {
	flex-wrap: wrap !important;
	overflow: visible !important;
}

.filterBar .select :global(.vs__selected) {
	height: 28px !important;
	min-height: 28px !important;
	max-height: 28px !important;
	line-height: 28px !important;
	display: inline-flex !important;
	align-items: center !important;
	white-space: nowrap !important;
	box-sizing: border-box !important;
}

.filterBar .select :global(.vs__dropdown-toggle),
.filterBar .select :global(.vs__selected-options),
.filterBar .select :global(.vs__selected) {
	min-width: 0;
	max-width: 100%;
	box-sizing: border-box;
}

.filterBar .select :global(.multiselect__single),
.filterBar .select :global(.multiselect__input),
.filterBar .select :global(.multiselect__tags) {
	min-width: 0;
	max-width: 100%;
	box-sizing: border-box;
}

.filterBar .select :global(.vs__dropdown-menu),
.filterBar .select :global(.multiselect__content-wrapper) {
	z-index: 100;
	max-width: 100%;
	box-sizing: border-box;
}

.actions {
	display: flex;
	align-items: center;
	align-self: flex-end;
	flex-shrink: 0;
	padding-bottom: 2px;
	margin-inline-start: auto;
}

@media (max-width: 768px) {
	.actions {
		flex-basis: 100%;
		justify-content: flex-end;
	}
}
</style>
