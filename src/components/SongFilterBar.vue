<script setup lang="ts">
import type { Artist, Book, Collection, Composer } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed } from 'vue'

const props = withDefaults(
	defineProps<{
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
		selectedComposers?: Composer[]
		selectedBooks?: Book[]
		selectedCollections?: Collection[]
		selectedArtists?: Artist[]
	}>(),
	{
		composers: () => [],
		books: () => [],
		collections: () => [],
		selectedComposers: () => [],
		selectedBooks: () => [],
		selectedCollections: () => [],
		selectedArtists: () => [],
	},
)

const emit = defineEmits<{
	'update:selectedComposers': [composers: Composer[]]
	'update:selectedBooks': [books: Book[]]
	'update:selectedCollections': [collections: Collection[]]
	'update:selectedArtists': [artists: Artist[]]
	'clear': []
}>()

const hasActiveFilters = computed(() => {
	return (
		props.selectedComposers.length > 0
		|| props.selectedBooks.length > 0
		|| props.selectedCollections.length > 0
		|| props.selectedArtists.length > 0
	)
})

function clearAllFilters() {
	emit('update:selectedComposers', [])
	emit('update:selectedBooks', [])
	emit('update:selectedCollections', [])
	emit('update:selectedArtists', [])
	emit('clear')
}
</script>

<template>
	<div :class="$style.filterBar" role="region" :aria-label="t('mobilesheetsmanager', 'Song filters')">
		<div :class="$style.filterGroup">
			<label :class="$style.label">{{ t('mobilesheetsmanager', 'Composers') }}</label>
			<NcSelect
				:model-value="selectedComposers"
				:options="composers"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by composer…')"
				:input-label="t('mobilesheetsmanager', 'Filter by composer')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedComposers', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<label :class="$style.label">{{ t('mobilesheetsmanager', 'Books') }}</label>
			<NcSelect
				:model-value="selectedBooks"
				:options="books"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by book…')"
				:input-label="t('mobilesheetsmanager', 'Filter by book')"
				label="title"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedBooks', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<label :class="$style.label">{{ t('mobilesheetsmanager', 'Collections') }}</label>
			<NcSelect
				:model-value="selectedCollections"
				:options="collections"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by collection…')"
				:input-label="t('mobilesheetsmanager', 'Filter by collection')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedCollections', $event)" />
		</div>

		<div :class="$style.filterGroup">
			<label :class="$style.label">{{ t('mobilesheetsmanager', 'Artists') }}</label>
			<NcSelect
				:model-value="selectedArtists"
				:options="artists"
				:multiple="true"
				:close-on-select="false"
				:placeholder="t('mobilesheetsmanager', 'Filter by artist…')"
				:input-label="t('mobilesheetsmanager', 'Filter by artist')"
				label="name"
				track-by="id"
				:class="$style.select"
				@update:model-value="emit('update:selectedArtists', $event)" />
		</div>

		<div v-if="hasActiveFilters" :class="$style.actions">
			<NcButton
				type="tertiary"
				@click="clearAllFilters">
				{{ t('mobilesheetsmanager', 'Clear filters') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.filterBar {
	display: flex;
	flex-direction: row;
	gap: 16px;
	align-items: flex-end;
	padding: 12px 16px;
	margin-bottom: 16px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 0;
	min-width: 0;
}

.label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.select {
	width: 100%;
}

.actions {
	display: flex;
	align-items: center;
	flex-shrink: 0;
	padding-bottom: 2px;
}
</style>
