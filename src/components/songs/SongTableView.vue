<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, EntityType, FilterEntity, Genre, Instrument, Key, Signature, Song, SongColumn, SourceType, Year } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed, ref, watch } from 'vue'
import { formatDateTimestamp, formatDifficulty, formatDurationHms, formatStars, sortSongs, type SortKey, type SortOrder } from '../../utils/song-formatter'
import { useSongRowInteraction } from '../../composables/useSongRowInteraction'
import { createEntityTag } from '../song-editor/entity-utils'
import SongEntityChips from './SongEntityChips.vue'
import SongInlineEditableCell from './SongInlineEditableCell.vue'
import {
	DIFFICULTY_SELECT_OPTIONS,
	ENTITY_COLUMNS,
	EXTRA_TEXT_COLUMNS,
	IDENTIFIER_TEXT_COLUMNS,
	isColumnVisible,
	parseDurationInput,
	STAR_SELECT_OPTIONS,
	TABLE_COLUMNS,
	type EntityColumnDef,
	type InlineEditableField,
} from './song-view-columns'

export type { InlineEditableField } from './song-view-columns'

const props = withDefaults(
	defineProps<{
		songs: Song[]
		selectedSongIds?: number[]
		isFiltered?: (entityType: EntityType, id: number) => boolean
		sortKey?: SortKey
		sortOrder?: SortOrder
		visibleColumns?: SongColumn[]
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
		canWrite?: boolean
	}>(),
	{
		sortKey: 'title',
		sortOrder: 'asc',
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
		canWrite: true,
	},
)

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'inline-update-song': [song: Song, updates: Record<string, any>]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
	'select-all': [songIds: number[]]
	'update:sortKey': [key: SortKey]
	'update:sortOrder': [order: SortOrder]
}>()

function isColVisible(col: SongColumn): boolean {
	return isColumnVisible(props.visibleColumns, col)
}

const localSortKey = ref<SortKey>(props.sortKey)
const localSortOrder = ref<SortOrder>(props.sortOrder)

watch(() => props.sortKey, (newVal) => { localSortKey.value = newVal })
watch(() => props.sortOrder, (newVal) => { localSortOrder.value = newVal })

function toggleSort(key: SortKey) {
	const newOrder: SortOrder = localSortKey.value === key
		? (localSortOrder.value === 'asc' ? 'desc' : 'asc')
		: 'asc'
	localSortKey.value = key
	localSortOrder.value = newOrder
	emit('update:sortKey', key)
	emit('update:sortOrder', newOrder)
}

function sortIndicator(key: SortKey): string {
	if (localSortKey.value !== key) return ''
	return localSortOrder.value === 'asc' ? ' ▲' : ' ▼'
}

const sortedSongs = computed(() => sortSongs(props.songs, localSortKey.value, localSortOrder.value))
const visibleTableColumns = computed(() => TABLE_COLUMNS.filter((c) => isColVisible(c.col)))
const visibleEntityColumns = computed(() => ENTITY_COLUMNS.filter((c) => isColVisible(c.col)))
const visibleIdentifierColumns = computed(() => IDENTIFIER_TEXT_COLUMNS.filter((c) => isColVisible(c.col)))
const visibleExtraTextColumns = computed(() => EXTRA_TEXT_COLUMNS.filter((c) => isColVisible(c.col)))

function getEntityOptions(entCol: EntityColumnDef): any[] {
	const propOptions = (props[entCol.songProp] ?? []) as any[]
	if (propOptions.length > 0) return propOptions
	const map = new Map<string, any>()
	for (const song of props.songs) {
		for (const item of entCol.getItems(song)) {
			const label = entCol.getItemLabel(item).trim()
			const key = label.toLowerCase()
			if (key && !map.has(key)) {
				map.set(key, item)
			}
		}
	}
	return Array.from(map.values())
}

function getEntityTagCreator(entCol: EntityColumnDef): (val: string) => any {
	const options = getEntityOptions(entCol)
	return createEntityTag(
		options,
		(item: any) => entCol.getItemLabel(item),
		(val: string) => (entCol.nameKey === 'title' ? { title: val } : { name: val }),
	)
}

function handleInlineSave(song: Song, field: InlineEditableField, value: string | number) {
	emit('inline-update-song', song, { [field]: value })
}

function handleInlineEntitySave(
	song: Song,
	entCol: EntityColumnDef,
	rawValue: string | number | Array<{ id?: number, name?: string, title?: string }>,
) {
	const parsed = Array.isArray(rawValue)
		? rawValue
		: entCol.parseEditValue(String(rawValue))
	emit('inline-update-song', song, {
		[entCol.songProp]: parsed,
	})
}

function handleInlineDurationSave(song: Song, rawValue: string | number) {
	const nextSeconds = parseDurationInput(rawValue)
	if (nextSeconds !== (song.duration ?? 0)) {
		emit('inline-update-song', song, { duration: nextSeconds })
	}
}

const {
	handleTouchStart,
	handleTouchMove,
	handleTouchEnd,
	handleTouchCancel,
	handleSongClick,
} = useSongRowInteraction({
	onToggleSelect: (songId) => emit('toggle-select-song', songId),
	onViewSong: (song) => emit('view-song', song),
})
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
							:title="t('sheetmusic', 'Select all songs')"
							@change="emit('select-all', songs.map((s) => s.id))">
					</th>
					<th
						v-for="colDef in visibleTableColumns"
						:key="colDef.col"
						:class="$style.sortableHeader"
						@click="toggleSort(colDef.sortKey)">
						{{ colDef.label() }}{{ sortIndicator(colDef.sortKey) }}
					</th>
					<th v-if="canWrite !== false" :class="$style.actionsHeader">
						{{ t('sheetmusic', 'Actions') }}
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
							:title="t('sheetmusic', 'Select song')"
							@click.stop
							@change="emit('toggle-select-song', song.id)">
					</td>

					<SongInlineEditableCell
						v-if="isColVisible('title')"
						tag="td"
						:class="$style.titleCell"
						field="title"
						variant="table-title"
						:allow-empty="false"
						:value="song.title"
						:display-text="song.title"
						:aria-label="t('sheetmusic', 'Song title')"
						:edit-title="t('sheetmusic', 'Edit title inline')"
						:can-write="canWrite"
						@save="handleInlineSave(song, 'title', $event)" />

					<SongInlineEditableCell
						v-for="entCol in visibleEntityColumns"
						:key="entCol.col"
						tag="td"
						:class="$style.metaCell"
						:field="entCol.field"
						mode="entity"
						:entity-options="getEntityOptions(entCol)"
						:entity-label-key="entCol.nameKey"
						:create-entity-tag="getEntityTagCreator(entCol)"
						:initial-entities="entCol.getItems(song)"
						:value="entCol.formatEditValue(song)"
						:placeholder="t('sheetmusic', 'Select or type…')"
						:aria-label="entCol.ariaLabel()"
						:edit-title="entCol.editTitle()"
						:can-write="canWrite"
						@save="handleInlineEntitySave(song, entCol, $event)">
						<SongEntityChips
							:def="entCol"
							:items="entCol.getItems(song)"
							:is-filtered="isFiltered"
							show-empty-dash
							@filter-entity="(type, ent) => emit('filter-entity', type, ent)" />
					</SongInlineEditableCell>

					<SongInlineEditableCell
						v-for="textCol in visibleIdentifierColumns"
						:key="textCol.col"
						tag="td"
						:class="$style.metaCell"
						:field="textCol.field"
						:value="song[textCol.field]"
						:aria-label="textCol.ariaLabel()"
						:edit-title="textCol.editTitle()"
						:can-write="canWrite"
						@save="handleInlineSave(song, textCol.field, $event)" />

					<SongInlineEditableCell
						v-if="isColVisible('stars')"
						tag="td"
						:class="$style.metaCell"
						field="stars"
						mode="select"
						:options="STAR_SELECT_OPTIONS"
						:value="song.stars"
						:display-text="formatStars(song.stars)"
						:aria-label="t('sheetmusic', 'Rating')"
						:edit-title="t('sheetmusic', 'Edit rating inline')"
						:can-write="canWrite"
						@save="handleInlineSave(song, 'stars', $event)" />

					<SongInlineEditableCell
						v-if="isColVisible('difficulty')"
						tag="td"
						:class="$style.metaCell"
						field="difficulty"
						mode="select"
						:options="DIFFICULTY_SELECT_OPTIONS"
						:value="song.difficulty"
						:display-text="formatDifficulty(song.difficulty)"
						:aria-label="t('sheetmusic', 'Difficulty')"
						:edit-title="t('sheetmusic', 'Edit difficulty inline')"
						:can-write="canWrite"
						@save="handleInlineSave(song, 'difficulty', $event)" />

					<SongInlineEditableCell
						v-if="isColVisible('duration')"
						tag="td"
						:class="$style.metaCell"
						field="duration"
						mode="duration"
						:value="song.duration ?? 0"
						:display-text="formatDurationHms(song.duration)"
						:aria-label="t('sheetmusic', 'Duration')"
						:edit-title="t('sheetmusic', 'Edit duration inline')"
						:can-write="canWrite"
						@save="handleInlineDurationSave(song, $event)" />

					<SongInlineEditableCell
						v-for="extraCol in visibleExtraTextColumns"
						:key="extraCol.col"
						tag="td"
						:class="$style.metaCell"
						:field="extraCol.field"
						:value="song[extraCol.field]"
						:aria-label="extraCol.ariaLabel()"
						:edit-title="extraCol.editTitle()"
						:can-write="canWrite"
						@save="handleInlineSave(song, extraCol.field, $event)" />

					<td v-if="isColVisible('lastModified')" :class="$style.dateCell">
						{{ formatDateTimestamp(song.lastModified) }}
					</td>
					<td v-if="isColVisible('dateCreated')" :class="$style.dateCell">
						{{ formatDateTimestamp(song.dateCreated) }}
					</td>
					<td v-if="canWrite !== false" :class="$style.actionsCell" @click.stop>
						<div :class="$style.actionsGroup">
							<NcButton
								size="small"
								:title="t('sheetmusic', 'Edit song')"
								:aria-label="t('sheetmusic', 'Edit song')"
								@click="emit('edit-song', song)">
								<template #icon>
									<PencilIcon :size="18" />
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

@media (hover: hover) {
	.tableRow:hover :global(.sheetmusic-inline-edit-trigger) {
		opacity: 0.75;
		pointer-events: auto;
	}
}

.songTable td {
	padding: 10px 12px;
	vertical-align: middle;
}

.titleCell {
	font-weight: 600;
}

.metaCell {
	color: var(--color-text-maxcontrast);
}

.dateCell {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	font-size: 0.85rem;
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
