<script setup lang="ts">
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed } from 'vue'
import { formatDateTimestamp, formatDifficulty, formatDurationHms, formatStars } from '../../utils/song-formatter'
import { useSongRowInteraction } from '../../composables/useSongRowInteraction'
import SongEntityChips from './SongEntityChips.vue'
import SongInlineEditableCell from './SongInlineEditableCell.vue'
import { ENTITY_COLUMNS, type EntityColumnDef } from './song-view-columns'

const props = withDefaults(
	defineProps<{
		songs: Song[]
		selectedSongIds?: number[]
		isFiltered?: (entityType: EntityType, id: number) => boolean
		visibleColumns?: SongColumn[]
		canWrite?: boolean
	}>(),
	{
		canWrite: true,
	},
)

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'inline-update-song': [song: Song, updates: Partial<Song>]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
}>()

function isColVisible(col: SongColumn): boolean {
	if (!props.visibleColumns) return true
	return props.visibleColumns.includes(col)
}

const visibleEntityColumns = computed(() => ENTITY_COLUMNS.filter((c) => isColVisible(c.col)))

function populatedEntityColumnsFor(song: Song): EntityColumnDef[] {
	return visibleEntityColumns.value.filter((col) => col.getItems(song).length > 0)
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
	<ul :class="$style.songList">
		<li
			v-for="song in songs"
			:key="song.id"
			:class="[$style.songRow, selectedSongIds?.includes(song.id) && $style.selectedRow]"
			@click="handleSongClick(song, $event)"
			@touchstart="handleTouchStart(song.id, $event)"
			@touchmove="handleTouchMove($event)"
			@touchend="handleTouchEnd"
			@touchcancel="handleTouchCancel">
			<div :class="$style.songMain">
				<input
					type="checkbox"
					:checked="selectedSongIds?.includes(song.id)"
					:class="$style.selectCheckbox"
					:title="t('sheetmusic', 'Select song')"
					@click.stop
					@change="emit('toggle-select-song', song.id)">
				<div :class="$style.songInfo">
					<SongInlineEditableCell
						v-if="isColVisible('title')"
						field="title"
						variant="list-title"
						:allow-empty="false"
						:value="song.title"
						:display-text="song.title"
						:aria-label="t('sheetmusic', 'Song title')"
						:edit-title="t('sheetmusic', 'Edit title inline')"
						:can-write="canWrite"
						@save="emit('inline-update-song', song, { title: String($event) })" />
					<span :class="$style.subtitle">
						<template
							v-for="(entCol, idx) in populatedEntityColumnsFor(song)"
							:key="entCol.col">
							<template v-if="idx > 0"> • </template>
							<SongEntityChips
								:def="entCol"
								:items="entCol.getItems(song)"
								:is-filtered="isFiltered"
								@filter-entity="(type, ent) => emit('filter-entity', type, ent)" />
						</template>
						<template v-if="isColVisible('opus') && song.opus">
							<span> • {{ t('sheetmusic', 'Opus: {val}', { val: song.opus }, { escape: false }) }}</span>
						</template>
						<template v-if="isColVisible('catalogNumber') && song.catalogNumber">
							<span> • {{ song.catalogNumber }}</span>
						</template>
						<template v-if="isColVisible('songNumber') && song.songNumber">
							<span> • {{ t('sheetmusic', 'No. {val}', { val: song.songNumber }, { escape: false }) }}</span>
						</template>
						<template v-if="isColVisible('stars') && song.stars">
							<span> • {{ formatStars(song.stars) }}</span>
						</template>
						<template v-if="isColVisible('difficulty') && song.difficulty">
							<span> • {{ t('sheetmusic', 'Diff: {val}', { val: formatDifficulty(song.difficulty) }, { escape: false }) }}</span>
						</template>
						<template v-if="isColVisible('duration') && song.duration">
							<span> • {{ formatDurationHms(song.duration) }}</span>
						</template>
						<template v-if="isColVisible('keywords') && song.keywords">
							<span> • {{ song.keywords }}</span>
						</template>
						<template v-if="isColVisible('custom') && song.custom">
							<span> • {{ song.custom }}</span>
						</template>
						<template v-if="isColVisible('custom2') && song.custom2">
							<span> • {{ song.custom2 }}</span>
						</template>
						<template v-if="isColVisible('lastModified') && song.lastModified">
							<span> • {{ t('sheetmusic', 'Modified: {date}', { date: formatDateTimestamp(song.lastModified) }, { escape: false }) }}</span>
						</template>
						<template v-if="isColVisible('dateCreated') && song.dateCreated">
							<span> • {{ t('sheetmusic', 'Created: {date}', { date: formatDateTimestamp(song.dateCreated) }, { escape: false }) }}</span>
						</template>
					</span>
				</div>
			</div>
			<div v-if="canWrite !== false" :class="$style.actions" @click.stop>
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
		</li>
	</ul>
</template>

<style module>
.songList {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.songRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	gap: 16px;
	cursor: pointer;
}

.songRow:hover {
	background-color: var(--color-background-hover);
}

.songRow:hover :global(.sheetmusic-inline-edit-trigger) {
	opacity: 0.75;
}

.songMain {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1;
	min-width: 0;
}

.selectCheckbox {
	width: 18px;
	height: 18px;
	margin: 0;
	cursor: pointer;
	flex-shrink: 0;
	accent-color: var(--color-primary-element);
}

.songInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.subtitle {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	display: inline;
}

.actions {
	display: flex;
	gap: 8px;
	flex-shrink: 0;
}

.selectedRow {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

@media (max-width: 768px) {
	.selectCheckbox {
		display: none;
	}

	.actions {
		display: none;
	}
}
</style>
