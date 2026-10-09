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
					<span v-if="isColVisible('title')" :class="$style.songTitle">
						{{ song.title }}
					</span>
					<span :class="$style.subtitle">
						<template v-if="isColVisible('composer') && song.composers && song.composers.length > 0">
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
						</template>
						<template v-if="isColVisible('composer') && song.composers?.length && isColVisible('book') && song.books?.length"> • </template>
						<template v-if="isColVisible('book') && song.books && song.books.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length) && isColVisible('collection') && song.collections?.length"> • </template>
						<template v-if="isColVisible('collection') && song.collections && song.collections.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length) && isColVisible('artist') && song.artists?.length"> • </template>
						<template v-if="isColVisible('artist') && song.artists && song.artists.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length || isColVisible('artist') && song.artists?.length) && isColVisible('genre') && song.genres?.length"> • </template>
						<template v-if="isColVisible('genre') && song.genres && song.genres.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length || isColVisible('artist') && song.artists?.length || isColVisible('genre') && song.genres?.length) && isColVisible('key') && song.keys?.length"> • </template>
						<template v-if="isColVisible('key') && song.keys && song.keys.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length || isColVisible('artist') && song.artists?.length || isColVisible('genre') && song.genres?.length || isColVisible('key') && song.keys?.length) && isColVisible('customGroup') && song.customGroups?.length"> • </template>
						<template v-if="isColVisible('customGroup') && song.customGroups && song.customGroups.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length || isColVisible('artist') && song.artists?.length || isColVisible('genre') && song.genres?.length || isColVisible('key') && song.keys?.length || isColVisible('customGroup') && song.customGroups?.length) && isColVisible('year') && song.years?.length"> • </template>
						<template v-if="isColVisible('year') && song.years && song.years.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length || isColVisible('artist') && song.artists?.length || isColVisible('genre') && song.genres?.length || isColVisible('key') && song.keys?.length || isColVisible('customGroup') && song.customGroups?.length || isColVisible('year') && song.years?.length) && isColVisible('signature') && song.signatures?.length"> • </template>
						<template v-if="isColVisible('signature') && song.signatures && song.signatures.length > 0">
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
						</template>
						<template v-if="(isColVisible('composer') && song.composers?.length || isColVisible('book') && song.books?.length || isColVisible('collection') && song.collections?.length || isColVisible('artist') && song.artists?.length || isColVisible('genre') && song.genres?.length || isColVisible('key') && song.keys?.length || isColVisible('customGroup') && song.customGroups?.length || isColVisible('year') && song.years?.length || isColVisible('signature') && song.signatures?.length) && isColVisible('sourceType') && song.sourceTypes?.length"> • </template>
						<template v-if="isColVisible('sourceType') && song.sourceTypes && song.sourceTypes.length > 0">
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
						</template>
						<template v-if="isColVisible('instrument') && song.instruments && song.instruments.length > 0">
							<span> • </span>
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
						</template>
						<template v-if="isColVisible('opus') && song.opus">
							<span> • {{ t('sheetmusic', 'Opus: {val}', { val: song.opus }) }}</span>
						</template>
						<template v-if="isColVisible('catalogNumber') && song.catalogNumber">
							<span> • {{ song.catalogNumber }}</span>
						</template>
						<template v-if="isColVisible('songNumber') && song.songNumber">
							<span> • {{ t('sheetmusic', 'No. {val}', { val: song.songNumber }) }}</span>
						</template>
						<template v-if="isColVisible('stars') && song.stars">
							<span> • {{ formatStars(song.stars) }}</span>
						</template>
						<template v-if="isColVisible('difficulty') && song.difficulty">
							<span> • {{ t('sheetmusic', 'Diff: {val}', { val: formatDifficulty(song.difficulty) }) }}</span>
						</template>
						<template v-if="isColVisible('duration') && song.duration">
							<span> • {{ formatDuration(song.duration) }}</span>
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
							<span> • {{ t('sheetmusic', 'Modified: {date}', { date: formatDateTimestamp(song.lastModified) }) }}</span>
						</template>
						<template v-if="isColVisible('dateCreated') && song.dateCreated">
							<span> • {{ t('sheetmusic', 'Created: {date}', { date: formatDateTimestamp(song.dateCreated) }) }}</span>
						</template>
					</span>
				</div>
			</div>
			<div :class="$style.actions" @click.stop>
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

.songTitle {
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
	text-align: left;
}

.songRow:hover .songTitle {
	color: var(--color-primary-element);
}

.subtitle {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	display: inline;
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
