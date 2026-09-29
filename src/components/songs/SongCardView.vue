<script setup lang="ts">
import type { EntityType, FilterEntity, Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{
	songs: Song[]
	selectedSongIds?: number[]
	isFiltered?: (entityType: EntityType, id: number) => boolean
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
	'toggle-select-song': [songId: number]
}>()
</script>

<template>
	<div :class="$style.cardGrid">
		<div
			v-for="song in songs"
			:key="song.id"
			:class="[$style.songCard, selectedSongIds?.includes(song.id) && $style.selectedCard]">
			<div :class="$style.cardHeader">
				<input
					type="checkbox"
					:checked="selectedSongIds?.includes(song.id)"
					:title="t('mobilesheetsmanager', 'Select song')"
					@click.stop
					@change="emit('toggle-select-song', song.id)">
				<button
					type="button"
					:class="$style.cardTitleBtn"
					:title="t('mobilesheetsmanager', 'View this song')"
					@click="emit('view-song', song)">
					{{ song.title }}
				</button>
			</div>

			<div :class="$style.cardBody">
				<div v-if="song.composers && song.composers.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Composer:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(c, idx) in song.composers" :key="c.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('composers', c.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for composer {name}', { name: c.name })"
								@click.stop="emit('filter-entity', 'composers', c)">
								{{ c.name }}
							</button>
							<template v-if="idx < song.composers.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="song.books && song.books.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Book:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(b, idx) in song.books" :key="b.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('books', b.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for book {title}', { title: b.title })"
								@click.stop="emit('filter-entity', 'books', b)">
								{{ b.title }}
							</button>
							<template v-if="idx < song.books.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="song.collections && song.collections.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Collection:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(col, idx) in song.collections" :key="col.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('collections', col.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for collection {name}', { name: col.name })"
								@click.stop="emit('filter-entity', 'collections', col)">
								{{ col.name }}
							</button>
							<template v-if="idx < song.collections.length - 1">, </template>
						</template>
					</div>
				</div>

				<div v-if="song.artists && song.artists.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Artist:') }}</span>
					<div :class="$style.chipGroup">
						<template v-for="(art, idx) in song.artists" :key="art.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('artists', art.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for artist {name}', { name: art.name })"
								@click.stop="emit('filter-entity', 'artists', art)">
								{{ art.name }}
							</button>
							<template v-if="idx < song.artists.length - 1">, </template>
						</template>
					</div>
				</div>
			</div>

			<div :class="$style.cardActions">
				<NcButton size="small" @click="emit('edit-song', song)">
					{{ t('mobilesheetsmanager', 'Edit') }}
				</NcButton>
				<NcButton size="small" @click="emit('view-song', song)">
					{{ t('mobilesheetsmanager', 'View') }}
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
}

.cardTitleBtn {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
}

.cardTitleBtn:hover {
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
</style>
