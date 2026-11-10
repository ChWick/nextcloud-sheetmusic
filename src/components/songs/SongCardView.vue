<script setup lang="ts">
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed } from 'vue'
import { formatDateTimestamp, formatDifficulty, formatDuration, formatStars } from '../../utils/song-formatter'
import { useSongRowInteraction } from '../../composables/useSongRowInteraction'
import SongEntityChips from './SongEntityChips.vue'
import SongInlineEditableCell from './SongInlineEditableCell.vue'
import { ENTITY_COLUMNS } from './song-view-columns'

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
	<div :class="$style.cardGrid">
		<div
			v-for="song in songs"
			:key="song.id"
			:class="[$style.songCard, selectedSongIds?.includes(song.id) && $style.selectedCard]"
			@click="handleSongClick(song, $event)"
			@touchstart="handleTouchStart(song.id, $event)"
			@touchmove="handleTouchMove($event)"
			@touchend="handleTouchEnd"
			@touchcancel="handleTouchCancel">
			<div :class="$style.cardHeader">
				<input
					type="checkbox"
					:checked="selectedSongIds?.includes(song.id)"
					:class="$style.selectCheckbox"
					:title="t('sheetmusic', 'Select song')"
					@click.stop
					@change="emit('toggle-select-song', song.id)">
				<SongInlineEditableCell
					v-if="isColVisible('title')"
					field="title"
					variant="card-title"
					:allow-empty="false"
					:value="song.title"
					:display-text="song.title"
					:aria-label="t('sheetmusic', 'Song title')"
					:edit-title="t('sheetmusic', 'Edit title inline')"
					:can-write="canWrite"
					@save="emit('inline-update-song', song, { title: String($event) })" />
			</div>

			<div :class="$style.cardBody">
				<template v-for="entCol in visibleEntityColumns" :key="entCol.col">
					<div v-if="entCol.getItems(song).length > 0" :class="$style.metaRow">
						<span :class="$style.metaLabel">{{ entCol.cardLabel() }}</span>
						<div :class="$style.chipGroup">
							<SongEntityChips
								:def="entCol"
								:items="entCol.getItems(song)"
								:is-filtered="isFiltered"
								@filter-entity="(type, ent) => emit('filter-entity', type, ent)" />
						</div>
					</div>
				</template>

				<div v-if="isColVisible('opus') && song.opus" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Opus:') }}</span>
					<span>{{ song.opus }}</span>
				</div>
				<div v-if="isColVisible('catalogNumber') && song.catalogNumber" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Catalog No.:') }}</span>
					<span>{{ song.catalogNumber }}</span>
				</div>
				<div v-if="isColVisible('songNumber') && song.songNumber" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Song No.:') }}</span>
					<span>{{ song.songNumber }}</span>
				</div>
				<div v-if="isColVisible('stars') && song.stars" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Rating:') }}</span>
					<span>{{ formatStars(song.stars) }}</span>
				</div>
				<div v-if="isColVisible('difficulty') && song.difficulty" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Difficulty:') }}</span>
					<span>{{ formatDifficulty(song.difficulty) }}</span>
				</div>
				<div v-if="isColVisible('duration') && song.duration" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Duration:') }}</span>
					<span>{{ formatDuration(song.duration) }}</span>
				</div>
				<div v-if="isColVisible('keywords') && song.keywords" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Keywords:') }}</span>
					<span>{{ song.keywords }}</span>
				</div>
				<div v-if="isColVisible('custom') && song.custom" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom:') }}</span>
					<span>{{ song.custom }}</span>
				</div>
				<div v-if="isColVisible('custom2') && song.custom2" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom 2:') }}</span>
					<span>{{ song.custom2 }}</span>
				</div>
				<div v-if="isColVisible('lastModified') && song.lastModified" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Last modified:') }}</span>
					<span>{{ formatDateTimestamp(song.lastModified) }}</span>
				</div>
				<div v-if="isColVisible('dateCreated') && song.dateCreated" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Created:') }}</span>
					<span>{{ formatDateTimestamp(song.dateCreated) }}</span>
				</div>
			</div>

			<div :class="$style.cardActions" @click.stop>
				<NcButton
					v-if="canWrite !== false"
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
		</div>
	</div>
</template>

<style module>
.cardGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}

.songCard {
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	gap: 12px;
	cursor: pointer;
	min-width: 0;
}

.songCard:hover {
	border-color: var(--color-primary-element);
}

.songCard:hover :global(.sheetmusic-inline-edit-trigger) {
	opacity: 0.75;
}

.selectedCard {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.cardHeader {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	font-size: 1.05rem;
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

.cardBody {
	display: flex;
	flex-direction: column;
	gap: 6px;
	font-size: 0.85rem;
	flex-grow: 1;
}

.metaRow {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.metaLabel {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.chipGroup {
	display: inline;
	color: var(--color-text-maxcontrast);
}

.cardActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
	.selectCheckbox {
		display: none;
	}

	.cardActions {
		display: none;
	}
}
</style>
