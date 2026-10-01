<script setup lang="ts">
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed, ref, watch } from 'vue'
import { formatDateTimestamp, formatDifficulty, formatDuration, formatStars, sortSongs, type SortKey, type SortOrder } from '../../utils/song-formatter'

const props = withDefaults(
	defineProps<{
		songs: Song[]
		selectedSongIds?: number[]
		isFiltered?: (entityType: EntityType, id: number) => boolean
		sortKey?: SortKey
		sortOrder?: SortOrder
		visibleColumns?: SongColumn[]
	}>(),
	{
		sortKey: 'title',
		sortOrder: 'asc',
	}
)

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
	'select-all': [songIds: number[]]
	'update:sortKey': [key: SortKey]
	'update:sortOrder': [order: SortOrder]
}>()

function isColVisible(col: SongColumn): boolean {
	if (!props.visibleColumns) return true
	return props.visibleColumns.includes(col)
}

const localSortKey = ref<SortKey>(props.sortKey)
const localSortOrder = ref<SortOrder>(props.sortOrder)

watch(
	() => props.sortKey,
	(newVal) => {
		localSortKey.value = newVal
	}
)

watch(
	() => props.sortOrder,
	(newVal) => {
		localSortOrder.value = newVal
	}
)

function toggleSort(key: SortKey) {
	let newKey = key
	let newOrder: SortOrder = 'asc'

	if (localSortKey.value === key) {
		newOrder = localSortOrder.value === 'asc' ? 'desc' : 'asc'
	} else {
		newKey = key
		newOrder = 'asc'
	}

	localSortKey.value = newKey
	localSortOrder.value = newOrder
	emit('update:sortKey', newKey)
	emit('update:sortOrder', newOrder)
}

function sortIndicator(key: SortKey): string {
	if (localSortKey.value !== key) return ''
	return localSortOrder.value === 'asc' ? ' ▲' : ' ▼'
}

const sortedSongs = computed(() => sortSongs(props.songs, localSortKey.value, localSortOrder.value))

let touchTimer: ReturnType<typeof setTimeout> | null = null
let touchStartPos = { x: 0, y: 0 }
const isLongPressing = ref(false)

function handleTouchStart(songId: number, e: TouchEvent) {
	if (e.touches.length !== 1) return
	isLongPressing.value = false
	touchStartPos = { x: e.touches[0].clientX, y: e.touches[0].clientY }
	touchTimer = setTimeout(() => {
		isLongPressing.value = true
		if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
			try {
				navigator.vibrate(40)
			} catch {
				// ignore
			}
		}
		emit('toggle-select-song', songId)
	}, 500)
}

function handleTouchMove(e: TouchEvent) {
	if (!touchTimer || e.touches.length !== 1) return
	const touch = e.touches[0]
	const dx = Math.abs(touch.clientX - touchStartPos.x)
	const dy = Math.abs(touch.clientY - touchStartPos.y)
	if (dx > 10 || dy > 10) {
		clearTimeout(touchTimer)
		touchTimer = null
	}
}

function handleTouchEnd() {
	if (touchTimer) {
		clearTimeout(touchTimer)
		touchTimer = null
	}
}

function handleTouchCancel() {
	if (touchTimer) {
		clearTimeout(touchTimer)
		touchTimer = null
	}
}

function handleSongClick(song: Song, e: Event) {
	if (isLongPressing.value) {
		e.preventDefault()
		e.stopPropagation()
		isLongPressing.value = false
		return
	}
	emit('view-song', song)
}
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
					<th v-if="isColVisible('title')" :class="$style.sortableHeader" @click="toggleSort('title')">
						{{ t('mobilesheetsmanager', 'Title') }}{{ sortIndicator('title') }}
					</th>
					<th v-if="isColVisible('composer')" :class="$style.sortableHeader" @click="toggleSort('composer')">
						{{ t('mobilesheetsmanager', 'Composer') }}{{ sortIndicator('composer') }}
					</th>
					<th v-if="isColVisible('book')" :class="$style.sortableHeader" @click="toggleSort('book')">
						{{ t('mobilesheetsmanager', 'Book') }}{{ sortIndicator('book') }}
					</th>
					<th v-if="isColVisible('collection')" :class="$style.sortableHeader" @click="toggleSort('collection')">
						{{ t('mobilesheetsmanager', 'Collection') }}{{ sortIndicator('collection') }}
					</th>
					<th v-if="isColVisible('artist')" :class="$style.sortableHeader" @click="toggleSort('artist')">
						{{ t('mobilesheetsmanager', 'Artist') }}{{ sortIndicator('artist') }}
					</th>
					<th v-if="isColVisible('genre')" :class="$style.sortableHeader" @click="toggleSort('genre')">
						{{ t('mobilesheetsmanager', 'Genre') }}{{ sortIndicator('genre') }}
					</th>
					<th v-if="isColVisible('key')" :class="$style.sortableHeader" @click="toggleSort('key')">
						{{ t('mobilesheetsmanager', 'Key') }}{{ sortIndicator('key') }}
					</th>
					<th v-if="isColVisible('customGroup')" :class="$style.sortableHeader" @click="toggleSort('customGroup')">
						{{ t('mobilesheetsmanager', 'Custom Group') }}{{ sortIndicator('customGroup') }}
					</th>
					<th v-if="isColVisible('stars')" :class="$style.sortableHeader" @click="toggleSort('stars')">
						{{ t('mobilesheetsmanager', 'Rating') }}{{ sortIndicator('stars') }}
					</th>
					<th v-if="isColVisible('difficulty')" :class="$style.sortableHeader" @click="toggleSort('difficulty')">
						{{ t('mobilesheetsmanager', 'Difficulty') }}{{ sortIndicator('difficulty') }}
					</th>
					<th v-if="isColVisible('duration')" :class="$style.sortableHeader" @click="toggleSort('duration')">
						{{ t('mobilesheetsmanager', 'Duration') }}{{ sortIndicator('duration') }}
					</th>
					<th v-if="isColVisible('keywords')" :class="$style.sortableHeader" @click="toggleSort('keywords')">
						{{ t('mobilesheetsmanager', 'Keywords') }}{{ sortIndicator('keywords') }}
					</th>
					<th v-if="isColVisible('custom')" :class="$style.sortableHeader" @click="toggleSort('custom')">
						{{ t('mobilesheetsmanager', 'Custom') }}{{ sortIndicator('custom') }}
					</th>
					<th v-if="isColVisible('custom2')" :class="$style.sortableHeader" @click="toggleSort('custom2')">
						{{ t('mobilesheetsmanager', 'Custom 2') }}{{ sortIndicator('custom2') }}
					</th>
					<th v-if="isColVisible('lastModified')" :class="$style.sortableHeader" @click="toggleSort('lastModified')">
						{{ t('mobilesheetsmanager', 'Last modified') }}{{ sortIndicator('lastModified') }}
					</th>
					<th v-if="isColVisible('dateCreated')" :class="$style.sortableHeader" @click="toggleSort('dateCreated')">
						{{ t('mobilesheetsmanager', 'Created') }}{{ sortIndicator('dateCreated') }}
					</th>
					<th :class="$style.actionsHeader">
						{{ t('mobilesheetsmanager', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="song in sortedSongs"
					:key="song.id"
					:class="[$style.tableRow, selectedSongIds?.includes(song.id) && $style.selectedRow]"
					@click="handleSongClick(song, $event)"
					@touchstart="handleTouchStart(song.id, $event)"
					@touchmove="handleTouchMove($event)"
					@touchend="handleTouchEnd"
					@touchcancel="handleTouchCancel">
					<td :class="$style.checkboxCell">
						<input
							type="checkbox"
							:checked="selectedSongIds?.includes(song.id)"
							:title="t('mobilesheetsmanager', 'Select song')"
							@click.stop
							@change="emit('toggle-select-song', song.id)">
					</td>
					<td v-if="isColVisible('title')" :class="$style.titleCell">
						<span :class="$style.songTitle">
							{{ song.title }}
						</span>
					</td>
					<td v-if="isColVisible('composer')" :class="$style.metaCell">
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
					<td v-if="isColVisible('book')" :class="$style.metaCell">
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
					<td v-if="isColVisible('collection')" :class="$style.metaCell">
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
					<td v-if="isColVisible('artist')" :class="$style.metaCell">
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
					<td v-if="isColVisible('genre')" :class="$style.metaCell">
						<template v-if="song.genres && song.genres.length > 0">
							<span v-for="(g, idx) in song.genres" :key="g.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('genres', g.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for genre {name}', { name: g.name })"
									@click.stop="emit('filter-entity', 'genres', g)">
									{{ g.name }}
								</button>
								<template v-if="idx < song.genres.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('key')" :class="$style.metaCell">
						<template v-if="song.keys && song.keys.length > 0">
							<span v-for="(k, idx) in song.keys" :key="k.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('keys', k.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for key {name}', { name: k.name })"
									@click.stop="emit('filter-entity', 'keys', k)">
									{{ k.name }}
								</button>
								<template v-if="idx < song.keys.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('customGroup')" :class="$style.metaCell">
						<template v-if="song.customGroups && song.customGroups.length > 0">
							<span v-for="(cg, idx) in song.customGroups" :key="cg.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('customgroups', cg.id) && $style.activeChip]"
									:title="t('mobilesheetsmanager', 'Toggle filter for custom group {name}', { name: cg.name })"
									@click.stop="emit('filter-entity', 'customgroups', cg)">
									{{ cg.name }}
								</button>
								<template v-if="idx < song.customGroups.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('stars')" :class="$style.metaCell">
						{{ formatStars(song.stars) }}
					</td>
					<td v-if="isColVisible('difficulty')" :class="$style.metaCell">
						{{ formatDifficulty(song.difficulty) }}
					</td>
					<td v-if="isColVisible('duration')" :class="$style.metaCell">
						{{ formatDuration(song.duration) }}
					</td>
					<td v-if="isColVisible('keywords')" :class="$style.metaCell">
						{{ song.keywords || '—' }}
					</td>
					<td v-if="isColVisible('custom')" :class="$style.metaCell">
						{{ song.custom || '—' }}
					</td>
					<td v-if="isColVisible('custom2')" :class="$style.metaCell">
						{{ song.custom2 || '—' }}
					</td>
					<td v-if="isColVisible('lastModified')" :class="$style.dateCell">
						{{ formatDateTimestamp(song.lastModified) }}
					</td>
					<td v-if="isColVisible('dateCreated')" :class="$style.dateCell">
						{{ formatDateTimestamp(song.dateCreated) }}
					</td>
					<td :class="$style.actionsCell" @click.stop>
						<div :class="$style.actionsGroup">
							<NcButton
								size="small"
								:title="t('mobilesheetsmanager', 'Edit song')"
								:aria-label="t('mobilesheetsmanager', 'Edit song')"
								@click="emit('edit-song', song)">
								<template #icon>
									<PencilIcon :size="18" />
								</template>
							</NcButton>
							<NcButton
								size="small"
								:title="t('mobilesheetsmanager', 'View song')"
								:aria-label="t('mobilesheetsmanager', 'View song')"
								@click="emit('view-song', song)">
								<template #icon>
									<EyeIcon :size="18" />
								</template>
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
	scrollbar-gutter: stable;
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
	cursor: pointer;
}

.tableRow:hover {
	background-color: var(--color-background-hover);
}

.tableRow:hover .actionsCell {
	background-color: var(--color-background-hover);
}

.tableRow:hover .songTitle {
	color: var(--color-primary-element-hover, var(--color-primary-element));
}

.songTable td {
	padding: 10px 12px;
	vertical-align: middle;
}

.titleCell {
	font-weight: 600;
}

.songTitle {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.metaCell {
	color: var(--color-text-maxcontrast);
}

.dateCell {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	font-size: 0.85rem;
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

@media (max-width: 768px) {
	.actionsHeader,
	.actionsCell,
	.checkboxCell {
		display: none;
	}

	.songTable {
		min-width: 100%;
	}
}
</style>
