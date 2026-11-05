<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, Signature, SourceType, Year } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FilterOffIcon from 'vue-material-design-icons/FilterOff.vue'
import { computed } from 'vue'
import { noneEntryOf } from '../composables/useSongFilters'
import { useCtrlKey } from '../composables/useCtrlKey'

const { isCtrlPressed } = useCtrlKey()

const props = withDefaults(
	defineProps<{
		searchTitle?: string
		withoutFiles?: boolean
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
		genres?: Genre[]
		keys?: Key[]
		customGroups?: CustomGroup[]
		years?: Year[]
		signatures?: Signature[]
		sourceTypes?: SourceType[]
		instruments?: Instrument[]
		arrangers?: Arranger[]
		selectedComposers?: Composer[]
		selectedBooks?: Book[]
		selectedCollections?: Collection[]
		selectedArtists?: Artist[]
		selectedGenres?: Genre[]
		selectedKeys?: Key[]
		selectedCustomGroups?: CustomGroup[]
		selectedYears?: Year[]
		selectedSignatures?: Signature[]
		selectedSourceTypes?: SourceType[]
		selectedInstruments?: Instrument[]
		selectedArrangers?: Arranger[]
	}>(),
	{
		searchTitle: '',
		withoutFiles: false,
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
		selectedComposers: () => [],
		selectedBooks: () => [],
		selectedCollections: () => [],
		selectedArtists: () => [],
		selectedGenres: () => [],
		selectedKeys: () => [],
		selectedCustomGroups: () => [],
		selectedYears: () => [],
		selectedSignatures: () => [],
		selectedSourceTypes: () => [],
		selectedInstruments: () => [],
		selectedArrangers: () => [],
	},
)

const emit = defineEmits<{
	'update:searchTitle': [title: string]
	'update:withoutFiles': [withoutFiles: boolean]
	'update:selectedComposers': [composers: Composer[]]
	'update:selectedBooks': [books: Book[]]
	'update:selectedCollections': [collections: Collection[]]
	'update:selectedArtists': [artists: Artist[]]
	'update:selectedGenres': [genres: Genre[]]
	'update:selectedKeys': [keys: Key[]]
	'update:selectedCustomGroups': [customGroups: CustomGroup[]]
	'update:selectedYears': [years: Year[]]
	'update:selectedSignatures': [signatures: Signature[]]
	'update:selectedSourceTypes': [sourceTypes: SourceType[]]
	'update:selectedInstruments': [instruments: Instrument[]]
	'update:selectedArrangers': [arrangers: Arranger[]]
	'clear': []
}>()

const withoutFilesModel = computed({
	get: () => props.withoutFiles,
	set: (val: boolean) => emit('update:withoutFiles', val),
})

const filterFields = computed(() => [
	{
		key: 'composers',
		selected: props.selectedComposers,
		options: [noneEntryOf('composers') as Composer, ...props.composers],
		placeholder: t('sheetmusic', 'Filter by composer…'),
		inputLabel: t('sheetmusic', 'Composers'),
		label: 'name',
		onUpdate: (val: Composer[]) => emit('update:selectedComposers', val),
	},
	{
		key: 'books',
		selected: props.selectedBooks,
		options: [noneEntryOf('books') as Book, ...props.books],
		placeholder: t('sheetmusic', 'Filter by book…'),
		inputLabel: t('sheetmusic', 'Books'),
		label: 'title',
		onUpdate: (val: Book[]) => emit('update:selectedBooks', val),
	},
	{
		key: 'collections',
		selected: props.selectedCollections,
		options: [noneEntryOf('collections') as Collection, ...props.collections],
		placeholder: t('sheetmusic', 'Filter by collection…'),
		inputLabel: t('sheetmusic', 'Collections'),
		label: 'name',
		onUpdate: (val: Collection[]) => emit('update:selectedCollections', val),
	},
	{
		key: 'artists',
		selected: props.selectedArtists,
		options: [noneEntryOf('artists') as Artist, ...props.artists],
		placeholder: t('sheetmusic', 'Filter by artist…'),
		inputLabel: t('sheetmusic', 'Artists'),
		label: 'name',
		onUpdate: (val: Artist[]) => emit('update:selectedArtists', val),
	},
	{
		key: 'genres',
		selected: props.selectedGenres,
		options: [noneEntryOf('genres') as Genre, ...props.genres],
		placeholder: t('sheetmusic', 'Filter by genre…'),
		inputLabel: t('sheetmusic', 'Genres'),
		label: 'name',
		onUpdate: (val: Genre[]) => emit('update:selectedGenres', val),
	},
	{
		key: 'keys',
		selected: props.selectedKeys,
		options: [noneEntryOf('keys') as Key, ...props.keys],
		placeholder: t('sheetmusic', 'Filter by key…'),
		inputLabel: t('sheetmusic', 'Keys'),
		label: 'name',
		onUpdate: (val: Key[]) => emit('update:selectedKeys', val),
	},
	{
		key: 'customGroups',
		selected: props.selectedCustomGroups,
		options: [noneEntryOf('customgroups') as CustomGroup, ...props.customGroups],
		placeholder: t('sheetmusic', 'Filter by custom group…'),
		inputLabel: t('sheetmusic', 'Custom Groups'),
		label: 'name',
		onUpdate: (val: CustomGroup[]) => emit('update:selectedCustomGroups', val),
	},
	{
		key: 'years',
		selected: props.selectedYears,
		options: [noneEntryOf('years') as Year, ...props.years],
		placeholder: t('sheetmusic', 'Filter by year…'),
		inputLabel: t('sheetmusic', 'Years'),
		label: 'name',
		onUpdate: (val: Year[]) => emit('update:selectedYears', val),
	},
	{
		key: 'signatures',
		selected: props.selectedSignatures,
		options: [noneEntryOf('signatures') as Signature, ...props.signatures],
		placeholder: t('sheetmusic', 'Filter by time signature…'),
		inputLabel: t('sheetmusic', 'Time Signatures'),
		label: 'name',
		onUpdate: (val: Signature[]) => emit('update:selectedSignatures', val),
	},
	{
		key: 'sourceTypes',
		selected: props.selectedSourceTypes,
		options: [noneEntryOf('sourcetypes') as SourceType, ...props.sourceTypes],
		placeholder: t('sheetmusic', 'Filter by source type…'),
		inputLabel: t('sheetmusic', 'Source Types'),
		label: 'name',
		onUpdate: (val: SourceType[]) => emit('update:selectedSourceTypes', val),
	},
	{
		key: 'instruments',
		selected: props.selectedInstruments,
		options: [noneEntryOf('instruments') as Instrument, ...props.instruments],
		placeholder: t('sheetmusic', 'Filter by instrument…'),
		inputLabel: t('sheetmusic', 'Instruments'),
		label: 'name',
		onUpdate: (val: Instrument[]) => emit('update:selectedInstruments', val),
	},
	{
		key: 'arrangers',
		selected: props.selectedArrangers,
		options: [noneEntryOf('arrangers') as Arranger, ...props.arrangers],
		placeholder: t('sheetmusic', 'Filter by arranger…'),
		inputLabel: t('sheetmusic', 'Arrangers'),
		label: 'name',
		onUpdate: (val: Arranger[]) => emit('update:selectedArrangers', val),
	},
])

const hasActiveFilters = computed(() => {
	return (
		Boolean(props.searchTitle?.trim())
		|| props.withoutFiles
		|| filterFields.value.some((f) => f.selected.length > 0)
	)
})

function clearAllFilters() {
	// One atomic action: the views drop every selection in a single URL change, so
	// the browser history gets one entry instead of one per filter.
	emit('clear')
}
</script>

<template>
	<div :class="$style.filterBar" role="region" :aria-label="t('sheetmusic', 'Song filters')">
		<div :class="$style.filterGroup">
			<NcTextField
				:model-value="searchTitle"
				:placeholder="t('sheetmusic', 'Search by title…')"
				:label="t('sheetmusic', 'Search title')"
				:class="$style.input"
				@update:model-value="emit('update:searchTitle', $event)" />
		</div>

		<div :class="[$style.filterGroup, $style.checkboxGroup]">
			<NcCheckboxRadioSwitch
				v-model="withoutFilesModel"
				type="checkbox">
				{{ t('sheetmusic', 'Without files') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div v-for="field in filterFields" :key="field.key" :class="$style.filterGroup">
			<NcSelect
				:model-value="field.selected"
				:options="field.options"
				:multiple="true"
				:keep-open="isCtrlPressed"
				:placeholder="field.placeholder"
				:input-label="field.inputLabel"
				:label="field.label"
				track-by="id"
				:class="$style.select"
				@update:model-value="field.onUpdate($event)" />
		</div>

		<div v-if="hasActiveFilters" :class="$style.actions">
			<NcButton
				type="tertiary"
				:title="t('sheetmusic', 'Clear filters')"
				:aria-label="t('sheetmusic', 'Clear filters')"
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

.checkboxGroup {
	flex: 0 0 auto;
	justify-content: center;
	align-items: flex-start;
	padding: 0 8px;
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
