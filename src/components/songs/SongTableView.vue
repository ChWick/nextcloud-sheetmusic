<script setup lang="ts">
import type { Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, ref } from 'vue'
import { formatArtists, formatBooks, formatCollections, formatComposers, sortSongs, type SortKey, type SortOrder } from '../../utils/song-formatter'

const props = defineProps<{
	songs: Song[]
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
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
				<tr v-for="song in sortedSongs" :key="song.id" :class="$style.tableRow">
					<td :class="$style.titleCell">
						<button
							type="button"
							:class="$style.songTitleBtn"
							:title="t('mobilesheetsmanager', 'View this song')"
							@click="emit('view-song', song)">
							{{ song.title }}
						</button>
					</td>
					<td :class="$style.metaCell">{{ formatComposers(song) }}</td>
					<td :class="$style.metaCell">{{ formatBooks(song) }}</td>
					<td :class="$style.metaCell">{{ formatCollections(song) }}</td>
					<td :class="$style.metaCell">{{ formatArtists(song) }}</td>
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
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.songTable {
	width: 100%;
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
}

.tableRow {
	border-bottom: 1px solid var(--color-border);
}

.tableRow:hover {
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

.actionsCell {
	text-align: right;
	white-space: nowrap;
}

.actionsGroup {
	display: inline-flex;
	gap: 6px;
}
</style>
