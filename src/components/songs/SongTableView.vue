<script setup lang="ts">
import type { EntityType, FilterEntity, Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, ref } from 'vue'
import { sortSongs, type SortKey, type SortOrder } from '../../utils/song-formatter'

const props = defineProps<{
	songs: Song[]
	selectedSongIds?: number[]
	isFiltered?: (entityType: EntityType, id: number) => boolean
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
	'select-all': [songIds: number[]]
}>()

const sortKey = ref<SortKey>('title')
const sortOrder = ref<SortOrder>('asc')

function toggleSort(key: SortKey) {
	if (sortKey.value === key) {
		sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
	} else {
		sortKey.value = key
		sortOrder.value = 'asc'
	}
}

function sortIndicator(key: SortKey): string {
	if (sortKey.value !== key) return ''
	return sortOrder.value === 'asc' ? ' ▲' : ' ▼'
}

const sortedSongs = computed(() => sortSongs(props.songs, sortKey.value, sortOrder.value))
</script>

<template>
	<div :class="$style.tableContainer">
		<table :class="$style.songTable">
			<thead>
				<tr>
					<th :class="$style.checkboxCell">
						<input
							type="checkbox"
							:checked="songs.length > 0 && songs.every((s) => selectedSongIds?.includes(s.id))"
							:title="t('mobilesheetsmanager', 'Select all songs')"
							@change="emit('select-all', songs.map((s) => s.id))">
					</th>
					<th :class="$style.sortableHeader" @click="toggleSort('title')">
						{{ t('mobilesheetsmanager', 'Title') }}{{ sortIndicator('title') }}
					</th>
					<th :class="$style.sortableHeader" @click="toggleSort('composer')">
						{{ t('mobilesheetsmanager', 'Composer') }}{{ sortIndicator('composer') }}
					</th>
					<th :class="$style.sortableHeader" @click="toggleSort('book')">
						{{ t('mobilesheetsmanager', 'Book') }}{{ sortIndicator('book') }}
					</th>
					<th :class="$style.sortableHeader" @click="toggleSort('collection')">
						{{ t('mobilesheetsmanager', 'Collection') }}{{ sortIndicator('collection') }}
					</th>
					<th :class="$style.sortableHeader" @click="toggleSort('artist')">
						{{ t('mobilesheetsmanager', 'Artist') }}{{ sortIndicator('artist') }}
					</th>
					<th :class="$style.actionsHeader">
						{{ t('mobilesheetsmanager', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="song in sortedSongs" :key="song.id" :class="[$style.tableRow, selectedSongIds?.includes(song.id) && $style.selectedRow]">
					<td :class="$style.checkboxCell">
						<input
							type="checkbox"
							:checked="selectedSongIds?.includes(song.id)"
							:title="t('mobilesheetsmanager', 'Select song')"
							@click.stop
							@change="emit('toggle-select-song', song.id)">
					</td>
					<td :class="$style.titleCell">
						<button
							type="button"
							:class="$style.songTitleBtn"
							:title="t('mobilesheetsmanager', 'View this song')"
							@click="emit('view-song', song)">
							{{ song.title }}
						</button>
					</td>
					<td :class="$style.metaCell">
						<template v-if="song.composers && song.composers.length > 0">
							<span v-for="(comp, idx) in song.composers" :key="comp.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('composers', comp.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for composer {name}', { name: comp.name })"
									@click.stop="emit('filter-entity', 'composers', comp)">
									{{ comp.name }}
								</button>
								<template v-if="idx < song.composers.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td :class="$style.metaCell">
						<template v-if="song.books && song.books.length > 0">
							<span v-for="(bk, idx) in song.books" :key="bk.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('books', bk.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for book {title}', { title: bk.title })"
									@click.stop="emit('filter-entity', 'books', bk)">
									{{ bk.title }}
								</button>
								<template v-if="idx < song.books.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td :class="$style.metaCell">
						<template v-if="song.collections && song.collections.length > 0">
							<span v-for="(col, idx) in song.collections" :key="col.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('collections', col.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for collection {name}', { name: col.name })"
									@click.stop="emit('filter-entity', 'collections', col)">
									{{ col.name }}
								</button>
								<template v-if="idx < song.collections.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td :class="$style.metaCell">
						<template v-if="song.artists && song.artists.length > 0">
							<span v-for="(art, idx) in song.artists" :key="art.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('artists', art.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for artist {name}', { name: art.name })"
									@click.stop="emit('filter-entity', 'artists', art)">
									{{ art.name }}
								</button>
								<template v-if="idx < song.artists.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td :class="$style.actionsCell">
						<div :class="$style.actionsGroup">
							<NcButton size="small" @click="emit('edit-song', song)">
								{{ t('mobilesheetsmanager', 'Edit') }}
							</NcButton>
							<NcButton size="small" @click="emit('view-song', song)">
								{{ t('mobilesheetsmanager', 'View') }}
							</NcButton>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style module>
.tableContainer {
	width: 100%;
	max-width: 100%;
	min-width: 0;
	overflow-x: auto;
	scrollbar-width: thin;
	scrollbar-color: var(--color-border) transparent;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.tableContainer::-webkit-scrollbar {
	height: 8px;
}

.tableContainer::-webkit-scrollbar-track {
	background: transparent;
}

.tableContainer::-webkit-scrollbar-thumb {
	background: var(--color-border);
	border-radius: 4px;
}

.tableContainer::-webkit-scrollbar-thumb:hover {
	background: var(--color-text-maxcontrast);
}

.songTable {
	width: 100%;
	min-width: 800px;
	border-collapse: collapse;
	text-align: left;
	font-size: 0.9rem;
}

.songTable th {
	background-color: var(--color-background-hover);
	padding: 10px 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid var(--color-border);
	white-space: nowrap;
}

.sortableHeader {
	cursor: pointer;
	user-select: none;
}

.sortableHeader:hover {
	background-color: var(--color-background-dark);
}

.actionsHeader {
	text-align: right;
	position: sticky;
	right: 0;
	background-color: var(--color-background-hover);
	z-index: 2;
	box-shadow: -2px 0 6px -2px rgba(0, 0, 0, 0.12);
}

.tableRow {
	border-bottom: 1px solid var(--color-border);
}

.tableRow:hover {
	background-color: var(--color-background-hover);
}

.tableRow:hover .actionsCell {
	background-color: var(--color-background-hover);
}

.songTable td {
	padding: 10px 12px;
	vertical-align: middle;
}

.titleCell {
	font-weight: 600;
}

.songTitleBtn {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	color: var(--color-primary-element);
	cursor: pointer;
	text-align: left;
	text-decoration: underline;
}

.songTitleBtn:hover {
	color: var(--color-primary-element-hover, var(--color-primary-element));
}

.metaCell {
	color: var(--color-text-maxcontrast);
}

.filterChip {
	appearance: none;
	-webkit-appearance: none;
	background: transparent !important;
	border: none !important;
	border-radius: 0 !important;
	box-shadow: none !important;
	outline: none !important;
	padding: 0 !important;
	margin: 0 !important;
	font: inherit;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	text-align: left;
	display: inline;
	text-decoration: none;
	transition: color 0.15s ease;
}

.filterChip:hover,
.filterChip:focus,
.filterChip:active {
	background: transparent !important;
	border: none !important;
	box-shadow: none !important;
	color: var(--color-primary-element) !important;
	text-decoration: underline;
}

.activeChip {
	color: var(--color-primary-element) !important;
	font-weight: 600;
	text-decoration: underline;
}

.activeChip:hover,
.activeChip:focus,
.activeChip:active {
	background: transparent !important;
	border: none !important;
	box-shadow: none !important;
	color: var(--color-primary-element-hover, var(--color-primary-element)) !important;
}

.emptyText {
	color: var(--color-text-maxcontrast);
}

.actionsCell {
	text-align: right;
	white-space: nowrap;
	position: sticky;
	right: 0;
	background-color: var(--color-main-background);
	z-index: 1;
	box-shadow: -2px 0 6px -2px rgba(0, 0, 0, 0.12);
}

.actionsGroup {
	display: inline-flex;
	gap: 6px;
}

.checkboxCell {
	width: 36px;
	text-align: center;
}

.selectedRow {
	background-color: var(--color-background-hover);
}
</style>
