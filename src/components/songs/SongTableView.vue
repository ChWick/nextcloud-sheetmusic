<script setup lang="ts">
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed, nextTick, ref, watch } from 'vue'
import { formatDateTimestamp, formatDifficulty, formatDuration, formatStars, sortSongs, type SortKey, type SortOrder } from '../../utils/song-formatter'
import { useSongRowInteraction } from '../../composables/useSongRowInteraction'

export type InlineEditableField =
	| 'title'
	| 'opus'
	| 'catalogNumber'
	| 'songNumber'
	| 'stars'
	| 'difficulty'
	| 'keywords'
	| 'custom'
	| 'custom2'

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
	'inline-update-song': [song: Song, updates: Partial<Song>]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
	'select-all': [songIds: number[]]
	'update:sortKey': [key: SortKey]
	'update:sortOrder': [order: SortOrder]
}>()

const editingSongId = ref<number | null>(null)
const editingField = ref<InlineEditableField | null>(null)
const inlineEditValue = ref<string>('')
const inlineEditNumberValue = ref<number>(0)
const inlineInputRef = ref<HTMLInputElement | HTMLInputElement[] | null>(null)
const inlineSelectRef = ref<HTMLSelectElement | HTMLSelectElement[] | null>(null)

function isEditingCell(songId: number, field: InlineEditableField): boolean {
	return editingSongId.value === songId && editingField.value === field
}

async function startInlineEdit(song: Song, field: InlineEditableField) {
	editingSongId.value = song.id
	editingField.value = field
	if (field === 'stars' || field === 'difficulty') {
		inlineEditNumberValue.value = Number(song[field] ?? 0)
	} else {
		inlineEditValue.value = String(song[field] ?? '')
	}
	await nextTick()
	const inputEl = Array.isArray(inlineInputRef.value) ? inlineInputRef.value[0] : inlineInputRef.value
	if (inputEl) {
		inputEl.focus()
		inputEl.select?.()
	}
	const selectEl = Array.isArray(inlineSelectRef.value) ? inlineSelectRef.value[0] : inlineSelectRef.value
	if (selectEl) {
		selectEl.focus()
	}
}

function cancelInlineEdit() {
	editingSongId.value = null
	editingField.value = null
	inlineEditValue.value = ''
}

function commitInlineEdit(song: Song) {
	const field = editingField.value
	if (!field || editingSongId.value !== song.id) return

	if (field === 'stars' || field === 'difficulty') {
		const nextNum = Math.max(0, Math.min(5, Number(inlineEditNumberValue.value) || 0))
		const prevNum = Number(song[field] ?? 0)
		cancelInlineEdit()
		if (nextNum !== prevNum) {
			emit('inline-update-song', song, { [field]: nextNum })
		}
		return
	}

	const trimmed = inlineEditValue.value.trim()
	if (field === 'title' && !trimmed) {
		return
	}

	const prevVal = String(song[field] ?? '').trim()
	cancelInlineEdit()
	if (trimmed !== prevVal) {
		emit('inline-update-song', song, { [field]: trimmed })
	}
}

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
					<th v-if="isColVisible('title')" :class="$style.sortableHeader" @click="toggleSort('title')">
						{{ t('sheetmusic', 'Title') }}{{ sortIndicator('title') }}
					</th>
					<th v-if="isColVisible('composer')" :class="$style.sortableHeader" @click="toggleSort('composer')">
						{{ t('sheetmusic', 'Composer') }}{{ sortIndicator('composer') }}
					</th>
					<th v-if="isColVisible('book')" :class="$style.sortableHeader" @click="toggleSort('book')">
						{{ t('sheetmusic', 'Book') }}{{ sortIndicator('book') }}
					</th>
					<th v-if="isColVisible('collection')" :class="$style.sortableHeader" @click="toggleSort('collection')">
						{{ t('sheetmusic', 'Collection') }}{{ sortIndicator('collection') }}
					</th>
					<th v-if="isColVisible('artist')" :class="$style.sortableHeader" @click="toggleSort('artist')">
						{{ t('sheetmusic', 'Artist') }}{{ sortIndicator('artist') }}
					</th>
					<th v-if="isColVisible('genre')" :class="$style.sortableHeader" @click="toggleSort('genre')">
						{{ t('sheetmusic', 'Genre') }}{{ sortIndicator('genre') }}
					</th>
					<th v-if="isColVisible('key')" :class="$style.sortableHeader" @click="toggleSort('key')">
						{{ t('sheetmusic', 'Key') }}{{ sortIndicator('key') }}
					</th>
					<th v-if="isColVisible('customGroup')" :class="$style.sortableHeader" @click="toggleSort('customGroup')">
						{{ t('sheetmusic', 'Custom Group') }}{{ sortIndicator('customGroup') }}
					</th>
					<th v-if="isColVisible('year')" :class="$style.sortableHeader" @click="toggleSort('year')">
						{{ t('sheetmusic', 'Year') }}{{ sortIndicator('year') }}
					</th>
					<th v-if="isColVisible('signature')" :class="$style.sortableHeader" @click="toggleSort('signature')">
						{{ t('sheetmusic', 'Time Signature') }}{{ sortIndicator('signature') }}
					</th>
					<th v-if="isColVisible('sourceType')" :class="$style.sortableHeader" @click="toggleSort('sourceType')">
						{{ t('sheetmusic', 'Source Type') }}{{ sortIndicator('sourceType') }}
					</th>
					<th v-if="isColVisible('instrument')" :class="$style.sortableHeader" @click="toggleSort('instrument')">
						{{ t('sheetmusic', 'Instrument') }}{{ sortIndicator('instrument') }}
					</th>
					<th v-if="isColVisible('opus')" :class="$style.sortableHeader" @click="toggleSort('opus')">
						{{ t('sheetmusic', 'Opus') }}{{ sortIndicator('opus') }}
					</th>
					<th v-if="isColVisible('catalogNumber')" :class="$style.sortableHeader" @click="toggleSort('catalogNumber')">
						{{ t('sheetmusic', 'Catalog No.') }}{{ sortIndicator('catalogNumber') }}
					</th>
					<th v-if="isColVisible('songNumber')" :class="$style.sortableHeader" @click="toggleSort('songNumber')">
						{{ t('sheetmusic', 'Song No.') }}{{ sortIndicator('songNumber') }}
					</th>
					<th v-if="isColVisible('stars')" :class="$style.sortableHeader" @click="toggleSort('stars')">
						{{ t('sheetmusic', 'Rating') }}{{ sortIndicator('stars') }}
					</th>
					<th v-if="isColVisible('difficulty')" :class="$style.sortableHeader" @click="toggleSort('difficulty')">
						{{ t('sheetmusic', 'Difficulty') }}{{ sortIndicator('difficulty') }}
					</th>
					<th v-if="isColVisible('duration')" :class="$style.sortableHeader" @click="toggleSort('duration')">
						{{ t('sheetmusic', 'Duration') }}{{ sortIndicator('duration') }}
					</th>
					<th v-if="isColVisible('keywords')" :class="$style.sortableHeader" @click="toggleSort('keywords')">
						{{ t('sheetmusic', 'Keywords') }}{{ sortIndicator('keywords') }}
					</th>
					<th v-if="isColVisible('custom')" :class="$style.sortableHeader" @click="toggleSort('custom')">
						{{ t('sheetmusic', 'Custom') }}{{ sortIndicator('custom') }}
					</th>
					<th v-if="isColVisible('custom2')" :class="$style.sortableHeader" @click="toggleSort('custom2')">
						{{ t('sheetmusic', 'Custom 2') }}{{ sortIndicator('custom2') }}
					</th>
					<th v-if="isColVisible('lastModified')" :class="$style.sortableHeader" @click="toggleSort('lastModified')">
						{{ t('sheetmusic', 'Last modified') }}{{ sortIndicator('lastModified') }}
					</th>
					<th v-if="isColVisible('dateCreated')" :class="$style.sortableHeader" @click="toggleSort('dateCreated')">
						{{ t('sheetmusic', 'Created') }}{{ sortIndicator('dateCreated') }}
					</th>
					<th :class="$style.actionsHeader">
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
					<td
						v-if="isColVisible('title')"
						:class="$style.titleCell"
						@dblclick.stop="startInlineEdit(song, 'title')">
						<div v-if="isEditingCell(song.id, 'title')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Song title')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								:aria-label="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								:aria-label="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span :class="$style.songTitle">
								{{ song.title }}
							</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-title-btn"
								:title="t('sheetmusic', 'Edit title inline')"
								:aria-label="t('sheetmusic', 'Edit title inline')"
								@click.stop="startInlineEdit(song, 'title')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td v-if="isColVisible('composer')" :class="$style.metaCell">
						<template v-if="song.composers && song.composers.length > 0">
							<span v-for="(comp, idx) in song.composers" :key="comp.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('composers', comp.id) && $style.activeChip]"
									:title="t('sheetmusic', 'Toggle filter for composer {name}', { name: comp.name })"
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
									:title="t('sheetmusic', 'Toggle filter for book {title}', { title: bk.title })"
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
									:title="t('sheetmusic', 'Toggle filter for collection {name}', { name: col.name })"
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
									:title="t('sheetmusic', 'Toggle filter for artist {name}', { name: art.name })"
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
									:title="t('sheetmusic', 'Toggle filter for genre {name}', { name: g.name })"
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
									:title="t('sheetmusic', 'Toggle filter for key {name}', { name: k.name })"
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
									:title="t('sheetmusic', 'Toggle filter for custom group {name}', { name: cg.name })"
									@click.stop="emit('filter-entity', 'customgroups', cg)">
									{{ cg.name }}
								</button>
								<template v-if="idx < song.customGroups.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('year')" :class="$style.metaCell">
						<template v-if="song.years && song.years.length > 0">
							<span v-for="(y, idx) in song.years" :key="y.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('years', y.id) && $style.activeChip]"
									:title="t('sheetmusic', 'Toggle filter for year {name}', { name: y.name })"
									@click.stop="emit('filter-entity', 'years', y)">
									{{ y.name }}
								</button>
								<template v-if="idx < song.years.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('signature')" :class="$style.metaCell">
						<template v-if="song.signatures && song.signatures.length > 0">
							<span v-for="(s, idx) in song.signatures" :key="s.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('signatures', s.id) && $style.activeChip]"
									:title="t('sheetmusic', 'Toggle filter for signature {name}', { name: s.name })"
									@click.stop="emit('filter-entity', 'signatures', s)">
									{{ s.name }}
								</button>
								<template v-if="idx < song.signatures.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('sourceType')" :class="$style.metaCell">
						<template v-if="song.sourceTypes && song.sourceTypes.length > 0">
							<span v-for="(st, idx) in song.sourceTypes" :key="st.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('sourcetypes', st.id) && $style.activeChip]"
									:title="t('sheetmusic', 'Toggle filter for source type {name}', { name: st.name })"
									@click.stop="emit('filter-entity', 'sourcetypes', st)">
									{{ st.name }}
								</button>
								<template v-if="idx < song.sourceTypes.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td v-if="isColVisible('instrument')" :class="$style.metaCell">
						<template v-if="song.instruments && song.instruments.length > 0">
							<span v-for="(inst, idx) in song.instruments" :key="inst.id">
								<button
									type="button"
									:class="[$style.filterChip, isFiltered?.('instruments', inst.id) && $style.activeChip]"
									:title="t('sheetmusic', 'Toggle filter for instrument {name}', { name: inst.name })"
									@click.stop="emit('filter-entity', 'instruments', inst)">
									{{ inst.name }}
								</button>
								<template v-if="idx < song.instruments.length - 1">, </template>
							</span>
						</template>
						<span v-else :class="$style.emptyText">—</span>
					</td>
					<td
						v-if="isColVisible('opus')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'opus')">
						<div v-if="isEditingCell(song.id, 'opus')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Opus')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ song.opus || '—' }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-opus-btn"
								:title="t('sheetmusic', 'Edit opus inline')"
								@click.stop="startInlineEdit(song, 'opus')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td
						v-if="isColVisible('catalogNumber')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'catalogNumber')">
						<div v-if="isEditingCell(song.id, 'catalogNumber')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Catalog No.')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ song.catalogNumber || '—' }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-catalogNumber-btn"
								:title="t('sheetmusic', 'Edit catalog number inline')"
								@click.stop="startInlineEdit(song, 'catalogNumber')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td
						v-if="isColVisible('songNumber')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'songNumber')">
						<div v-if="isEditingCell(song.id, 'songNumber')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Song No.')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ song.songNumber || '—' }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-songNumber-btn"
								:title="t('sheetmusic', 'Edit song number inline')"
								@click.stop="startInlineEdit(song, 'songNumber')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td
						v-if="isColVisible('stars')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'stars')">
						<div v-if="isEditingCell(song.id, 'stars')" :class="$style.inlineEditContainer" @click.stop>
							<select
								ref="inlineSelectRef"
								v-model.number="inlineEditNumberValue"
								:class="$style.inlineEditSelect"
								data-test="inline-edit-select"
								:aria-label="t('sheetmusic', 'Rating')"
								@change="commitInlineEdit(song)"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
								<option :value="0">—</option>
								<option v-for="n in 5" :key="n" :value="n">{{ formatStars(n) }}</option>
							</select>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ formatStars(song.stars) }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-stars-btn"
								:title="t('sheetmusic', 'Edit rating inline')"
								@click.stop="startInlineEdit(song, 'stars')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td
						v-if="isColVisible('difficulty')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'difficulty')">
						<div v-if="isEditingCell(song.id, 'difficulty')" :class="$style.inlineEditContainer" @click.stop>
							<select
								ref="inlineSelectRef"
								v-model.number="inlineEditNumberValue"
								:class="$style.inlineEditSelect"
								data-test="inline-edit-select"
								:aria-label="t('sheetmusic', 'Difficulty')"
								@change="commitInlineEdit(song)"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
								<option :value="0">—</option>
								<option v-for="n in 5" :key="n" :value="n">{{ formatDifficulty(n) }}</option>
							</select>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ formatDifficulty(song.difficulty) }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-difficulty-btn"
								:title="t('sheetmusic', 'Edit difficulty inline')"
								@click.stop="startInlineEdit(song, 'difficulty')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td v-if="isColVisible('duration')" :class="$style.metaCell">
						{{ formatDuration(song.duration) }}
					</td>
					<td
						v-if="isColVisible('keywords')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'keywords')">
						<div v-if="isEditingCell(song.id, 'keywords')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Keywords')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ song.keywords || '—' }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-keywords-btn"
								:title="t('sheetmusic', 'Edit keywords inline')"
								@click.stop="startInlineEdit(song, 'keywords')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td
						v-if="isColVisible('custom')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'custom')">
						<div v-if="isEditingCell(song.id, 'custom')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Custom')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ song.custom || '—' }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-custom-btn"
								:title="t('sheetmusic', 'Edit custom field inline')"
								@click.stop="startInlineEdit(song, 'custom')">
								<PencilIcon :size="14" />
							</button>
						</div>
					</td>
					<td
						v-if="isColVisible('custom2')"
						:class="$style.metaCell"
						@dblclick.stop="startInlineEdit(song, 'custom2')">
						<div v-if="isEditingCell(song.id, 'custom2')" :class="$style.inlineEditContainer" @click.stop>
							<input
								ref="inlineInputRef"
								v-model="inlineEditValue"
								type="text"
								:class="$style.inlineEditInput"
								data-test="inline-edit-input"
								:aria-label="t('sheetmusic', 'Custom 2')"
								@keydown.enter.prevent="commitInlineEdit(song)"
								@keydown.esc.prevent="cancelInlineEdit">
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
								data-test="inline-edit-save"
								:title="t('sheetmusic', 'Save')"
								@click.stop="commitInlineEdit(song)">
								<CheckIcon :size="14" />
							</button>
							<button
								type="button"
								:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
								data-test="inline-edit-cancel"
								:title="t('sheetmusic', 'Cancel')"
								@click.stop="cancelInlineEdit">
								<CloseIcon :size="14" />
							</button>
						</div>
						<div v-else :class="$style.editableCellContent">
							<span>{{ song.custom2 || '—' }}</span>
							<button
								type="button"
								:class="$style.inlineEditTrigger"
								data-test="inline-edit-custom2-btn"
								:title="t('sheetmusic', 'Edit custom 2 field inline')"
								@click.stop="startInlineEdit(song, 'custom2')">
								<PencilIcon :size="14" />
							</button>
						</div>
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
								:title="t('sheetmusic', 'Edit song')"
								:aria-label="t('sheetmusic', 'Edit song')"
								@click="emit('edit-song', song)">
								<template #icon>
									<PencilIcon :size="18" />
								</template>
							</NcButton>
							<NcButton
								size="small"
								:title="t('sheetmusic', 'View song')"
								:aria-label="t('sheetmusic', 'View song')"
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

.editableCellContent {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	max-width: 100%;
}

.inlineEditTrigger {
	appearance: none;
	-webkit-appearance: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	padding: 0;
	margin: 0;
	border: 1px solid transparent;
	border-radius: 4px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	opacity: 0;
	flex-shrink: 0;
	transition: opacity 0.15s ease, color 0.15s ease, background-color 0.15s ease, border-color 0.15s ease;
}

.tableRow:hover .inlineEditTrigger,
.inlineEditTrigger:focus,
.inlineEditTrigger:focus-visible {
	opacity: 0.75;
}

.inlineEditTrigger:hover {
	opacity: 1 !important;
	color: var(--color-primary-element);
	background-color: var(--color-main-background);
	border-color: var(--color-border);
}

.inlineEditContainer {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	width: 100%;
	min-width: 160px;
}

.inlineEditInput {
	flex: 1;
	min-width: 110px;
	padding: 4px 8px;
	border: 1px solid var(--color-primary-element);
	border-radius: 4px;
	font: inherit;
	font-weight: 500;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-sizing: border-box;
}

.inlineEditSelect {
	padding: 4px 6px;
	border: 1px solid var(--color-primary-element);
	border-radius: 4px;
	font: inherit;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.inlineActionBtn {
	appearance: none;
	-webkit-appearance: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	margin: 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	flex-shrink: 0;
}

.inlineSaveBtn {
	color: var(--color-element-success, #16a34a);
}

.inlineSaveBtn:hover {
	background-color: var(--color-background-hover);
	border-color: var(--color-element-success, #16a34a);
}

.inlineCancelBtn {
	color: var(--color-text-maxcontrast);
}

.inlineCancelBtn:hover {
	background-color: var(--color-background-hover);
	color: var(--color-error, #dc2626);
	border-color: var(--color-error, #dc2626);
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
