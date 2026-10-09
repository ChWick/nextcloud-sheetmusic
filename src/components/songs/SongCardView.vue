<script setup lang="ts">
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { formatDateTimestamp, formatDifficulty, formatDuration, formatStars } from '../../utils/song-formatter'
import { useSongRowInteraction } from '../../composables/useSongRowInteraction'

const props = defineProps<{
	songs: Song[]
	selectedSongIds?: number[]
	isFiltered?: (entityType: EntityType, id: number) => boolean
	visibleColumns?: SongColumn[]
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
}>()

function isColVisible(col: SongColumn): boolean {
	if (!props.visibleColumns) return true
	return props.visibleColumns.includes(col)
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
				<span v-if="isColVisible('title')" :class="$style.cardTitle">
					{{ song.title }}
				</span>
			</div>

			<div :class="$style.cardBody">
				<div v-if="isColVisible('composer') && song.composers && song.composers.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Composer:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(c, idx) in song.composers" :key="c.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('composers', c.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for composer {name}', { name: c.name })"
								@click.stop="emit('filter-entity', 'composers', c)">
								{{ c.name }}
							</button>
							<template v-if="idx < song.composers.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('book') && song.books && song.books.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Book:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(b, idx) in song.books" :key="b.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('books', b.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for book {title}', { title: b.title })"
								@click.stop="emit('filter-entity', 'books', b)">
								{{ b.title }}
							</button>
							<template v-if="idx < song.books.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('collection') && song.collections && song.collections.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Collection:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(col, idx) in song.collections" :key="col.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('collections', col.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for collection {name}', { name: col.name })"
								@click.stop="emit('filter-entity', 'collections', col)">
								{{ col.name }}
							</button>
							<template v-if="idx < song.collections.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('artist') && song.artists && song.artists.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Artist:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(art, idx) in song.artists" :key="art.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('artists', art.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for artist {name}', { name: art.name })"
								@click.stop="emit('filter-entity', 'artists', art)">
								{{ art.name }}
							</button>
							<template v-if="idx < song.artists.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('genre') && song.genres && song.genres.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Genre:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(g, idx) in song.genres" :key="g.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('genres', g.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for genre {name}', { name: g.name })"
								@click.stop="emit('filter-entity', 'genres', g)">
								{{ g.name }}
							</button>
							<template v-if="idx < song.genres.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('key') && song.keys && song.keys.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Key:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(k, idx) in song.keys" :key="k.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('keys', k.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for key {name}', { name: k.name })"
								@click.stop="emit('filter-entity', 'keys', k)">
								{{ k.name }}
							</button>
							<template v-if="idx < song.keys.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('customGroup') && song.customGroups && song.customGroups.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom Group:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(cg, idx) in song.customGroups" :key="cg.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('customgroups', cg.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for custom group {name}', { name: cg.name })"
								@click.stop="emit('filter-entity', 'customgroups', cg)">
								{{ cg.name }}
							</button>
							<template v-if="idx < song.customGroups.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('year') && song.years && song.years.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Year:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(y, idx) in song.years" :key="y.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('years', y.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for year {name}', { name: y.name })"
								@click.stop="emit('filter-entity', 'years', y)">
								{{ y.name }}
							</button>
							<template v-if="idx < song.years.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('signature') && song.signatures && song.signatures.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Time Signature:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(s, idx) in song.signatures" :key="s.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('signatures', s.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for signature {name}', { name: s.name })"
								@click.stop="emit('filter-entity', 'signatures', s)">
								{{ s.name }}
							</button>
							<template v-if="idx < song.signatures.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('sourceType') && song.sourceTypes && song.sourceTypes.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Source Type:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(st, idx) in song.sourceTypes" :key="st.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('sourcetypes', st.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for source type {name}', { name: st.name })"
								@click.stop="emit('filter-entity', 'sourcetypes', st)">
								{{ st.name }}
							</button>
							<template v-if="idx < song.sourceTypes.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="isColVisible('instrument') && song.instruments && song.instruments.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Instrument:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(inst, idx) in song.instruments" :key="inst.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('instruments', inst.id) && $style.activeChip]"
								:title="t('sheetmusic', 'Toggle filter for instrument {name}', { name: inst.name })"
								@click.stop="emit('filter-entity', 'instruments', inst)">
								{{ inst.name }}
							</button>
							<template v-if="idx < song.instruments.length - 1">, </template>
						</template>
					</div>
				</div>

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

.cardTitle {
	color: var(--color-main-text);
	text-align: left;
	min-width: 0;
	word-break: break-word;
}

.songCard:hover .cardTitle {
	color: var(--color-primary-element);
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
